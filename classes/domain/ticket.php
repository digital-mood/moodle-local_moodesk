<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Ticket domain constants and state machine.
 *
 * This class contains no database access and no Moodle API calls.
 * It is a pure domain model: constants and validation logic only.
 * Any class that needs to reason about ticket state imports from here.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Ticket domain model: constants and state machine validation.
 */
class ticket {
    /*
     * -------------------------------------------------------------------------
     * Status constants
     * Values stored in local_moodesk_ticket.status (TINYINT).
     * -------------------------------------------------------------------------
     */

    /** Ticket submitted, not yet acted on by any agent. */
    const STATUS_NEW = 0;

    /** Ticket received and open, not yet actively being worked on. */
    const STATUS_OPEN = 1;

    /** Waiting for a response from the requester. */
    const STATUS_PENDING = 2;

    /**
     * Legacy Phase 1 status — kept for backward compatibility with existing data.
     * Tickets in this state transition to STATUS_OPEN or STATUS_INPROGRESS.
     * No new tickets are created with this status.
     */
    const STATUS_ON_HOLD = 3;

    /** Agent has resolved the issue. Awaiting confirmation or auto-close. */
    const STATUS_RESOLVED = 4;

    /** Ticket is fully closed. No further action expected. */
    const STATUS_CLOSED = 5;

    /** Agent is actively working on the ticket. Phase 2 status. */
    const STATUS_INPROGRESS = 6;

    /*
     * -------------------------------------------------------------------------
     * Priority constants
     * Values stored in local_moodesk_ticket.priority (TINYINT).
     * -------------------------------------------------------------------------
     */

    /** Priority: low. */
    const PRIORITY_LOW    = 0;
    /** Priority: normal — the default for new tickets. */
    const PRIORITY_NORMAL = 1;
    /** Priority: high. */
    const PRIORITY_HIGH   = 2;
    /** Priority: urgent — the top of the scale. */
    const PRIORITY_URGENT = 3;

    /*
     * -------------------------------------------------------------------------
     * Source constants
     * Values stored in local_moodesk_ticket.source (TINYINT).
     * Phase 1: only SOURCE_WEB is used. Others are reserved.
     * -------------------------------------------------------------------------
     */

    /** Submitted via the web UI. Phase 1 only value. */
    const SOURCE_WEB   = 0;

    /** Phase 2: email-to-ticket ingestion. */
    const SOURCE_EMAIL = 1;

    /** Phase 2/Enterprise: submitted via REST API. */
    const SOURCE_API   = 2;

    /*
     * -------------------------------------------------------------------------
     * State machine
     * Defines which status transitions are permitted.
     * ticket_service::transition_status() enforces this.
     * -------------------------------------------------------------------------
     */

    /**
     * Valid status transitions map.
     *
     * Key:   current status
     * Value: array of allowed next statuses
     *
     * Phase 2 active workflow (5 statuses):
     *  open       → inprogress : agent picks up and starts working
     *  open       → pending    : waiting on requester before work begins
     *  open       → resolved   : resolved without actively working (e.g. answered in place)
     *  open       → closed     : spam / duplicate — close without working
     *  inprogress → open       : agent pauses / unassigns work
     *  inprogress → pending    : waiting on requester response
     *  inprogress → resolved   : agent resolves
     *  inprogress → closed     : closed without resolution (e.g. duplicate)
     *  pending    → open       : requester responded, back to open queue
     *  pending    → inprogress : agent re-engages directly
     *  pending    → resolved   : resolved while awaiting response
     *  pending    → closed     : no response — close without resolution
     *  resolved   → open       : requester disputes resolution
     *  resolved   → inprogress : agent re-engages after dispute
     *  resolved   → closed     : confirmed resolved
     *  closed     → open       : explicit reopen only
     *
     * Legacy entries (backward compatibility for data created in Phase 1):
     *  new      → open/inprogress/closed : legacy initial state
     *  on_hold  → open/inprogress/closed : legacy paused state
     */
    const VALID_TRANSITIONS = [
        // Legacy Phase 1 initial state — no new tickets start here.
        self::STATUS_NEW        => [self::STATUS_OPEN, self::STATUS_INPROGRESS, self::STATUS_CLOSED],
        // Phase 2 active workflow.
        self::STATUS_OPEN       => [self::STATUS_INPROGRESS, self::STATUS_PENDING, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_INPROGRESS => [self::STATUS_OPEN, self::STATUS_PENDING, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_PENDING    => [self::STATUS_OPEN, self::STATUS_INPROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_RESOLVED   => [self::STATUS_OPEN, self::STATUS_INPROGRESS, self::STATUS_CLOSED],
        self::STATUS_CLOSED     => [self::STATUS_OPEN],
        // Legacy Phase 1 on-hold state — no new tickets are set to this.
        self::STATUS_ON_HOLD    => [self::STATUS_OPEN, self::STATUS_INPROGRESS, self::STATUS_CLOSED],
    ];

    /**
     * Return true if transitioning from $from to $to is a valid state change.
     *
     * @param int $from Current status constant.
     * @param int $to   Target status constant.
     * @return bool
     */
    public static function is_valid_transition(int $from, int $to): bool {
        return isset(self::VALID_TRANSITIONS[$from])
            && in_array($to, self::VALID_TRANSITIONS[$from], true);
    }

    /**
     * Return the SLA pause columns a status change has to write, if any.
     *
     * The resolution SLA clock stops while a ticket sits in STATUS_PENDING:
     * time the requester takes to answer is not time the team failed to act.
     * Two columns carry that state — sla_paused_seconds holds every interval
     * that has closed, sla_paused_since holds the one still open (NULL when
     * the ticket is not paused).
     *
     * Entering pending opens an interval. Leaving it for anything at all —
     * resolved and closed included — folds the elapsed time into the total and
     * clears the marker. Every other transition writes nothing.
     *
     * Returns a field => value map to merge into the caller's update object, so
     * that both ticket_service and merge_service move the clock the same way
     * rather than each keeping its own copy of this reasoning.
     *
     * @param \stdClass $ticket    Current ticket record (status, sla_paused_*).
     * @param int       $newstatus Status the ticket is moving to.
     * @param int       $now       Timestamp of the transition.
     * @return array<string, int|null>  Empty when the pause state does not change.
     */
    public static function sla_pause_fields(\stdClass $ticket, int $newstatus, int $now): array {
        $oldstatus = (int) $ticket->status;

        if ($newstatus === self::STATUS_PENDING) {
            // Already paused — a repeated entry must not restart the interval,
            // which would silently discard everything accrued since it opened.
            if ($oldstatus === self::STATUS_PENDING || $ticket->sla_paused_since !== null) {
                return [];
            }
            return ['sla_paused_since' => $now];
        }

        if ($ticket->sla_paused_since === null) {
            return [];
        }

        // Leaving a pause. max() guards against a marker somehow set in the
        // future: a clock skew must not hand the ticket back SLA budget.
        $elapsed = max(0, $now - (int) $ticket->sla_paused_since);

        return [
            'sla_paused_seconds' => (int) $ticket->sla_paused_seconds + $elapsed,
            'sla_paused_since'   => null,
        ];
    }

    /**
     * Return all valid status constants as an indexed array.
     *
     * Useful for validation of incoming data at the service boundary.
     *
     * @return int[]
     */
    public static function all_statuses(): array {
        return [
            self::STATUS_NEW, // Legacy.
            self::STATUS_OPEN,
            self::STATUS_PENDING,
            self::STATUS_ON_HOLD, // Legacy.
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
            self::STATUS_INPROGRESS,
        ];
    }

    /**
     * Return the statuses a ticket is still being worked in.
     *
     * Everything except RESOLVED and CLOSED. Listed rather than derived by
     * comparison: STATUS_INPROGRESS is 6, above STATUS_RESOLVED at 4, so any
     * "status < resolved" test silently drops the tickets agents are actually
     * working on.
     *
     * @return int[]
     */
    public static function active_statuses(): array {
        return [
            self::STATUS_NEW, // Legacy.
            self::STATUS_OPEN,
            self::STATUS_PENDING,
            self::STATUS_ON_HOLD, // Legacy.
            self::STATUS_INPROGRESS,
        ];
    }

    /**
     * Return all valid priority constants as an indexed array.
     *
     * @return int[]
     */
    public static function all_priorities(): array {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_NORMAL,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    /**
     * Return true if the given status value is a terminal state.
     *
     * A ticket in a terminal state should not receive further replies
     * without being explicitly reopened first.
     *
     * @param int $status
     * @return bool
     */
    public static function is_terminal(int $status): bool {
        return $status === self::STATUS_CLOSED;
    }

    /**
     * Return the human-readable label map for every ticket status.
     *
     * Centralised here so pages (index/view/ajax) share a single source of
     * truth instead of redeclaring the same get_string() map.
     *
     * @return string[] Status constant => localised label.
     */
    public static function status_labels(): array {
        return [
            self::STATUS_NEW        => get_string('status_new', 'local_moodesk'),
            self::STATUS_OPEN       => get_string('status_open', 'local_moodesk'),
            self::STATUS_INPROGRESS => get_string('status_inprogress', 'local_moodesk'),
            self::STATUS_PENDING    => get_string('status_pending', 'local_moodesk'),
            self::STATUS_ON_HOLD    => get_string('status_on_hold', 'local_moodesk'),
            self::STATUS_RESOLVED   => get_string('status_resolved', 'local_moodesk'),
            self::STATUS_CLOSED     => get_string('status_closed', 'local_moodesk'),
        ];
    }

    /**
     * Return the human-readable label map for every ticket priority.
     *
     * @return string[] Priority constant => localised label.
     */
    public static function priority_labels(): array {
        return [
            self::PRIORITY_LOW    => get_string('priority_low', 'local_moodesk'),
            self::PRIORITY_NORMAL => get_string('priority_normal', 'local_moodesk'),
            self::PRIORITY_HIGH   => get_string('priority_high', 'local_moodesk'),
            self::PRIORITY_URGENT => get_string('priority_urgent', 'local_moodesk'),
        ];
    }
}
