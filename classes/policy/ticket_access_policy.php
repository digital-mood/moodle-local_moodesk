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
 * Access policy for tickets: who may see one, who may write to one, and who
 * may change one.
 *
 * The single source of truth for both questions. Service methods, page
 * controllers, the file-serving callback, tasks and external functions all ask
 * this class rather than restating the rule — an access rule that exists in two
 * places is an access rule that will eventually disagree with itself, and it
 * did: the ticket list, the ticket detail, the attachment callback and the
 * merge-revert path each had their own answer, and each restatement was weaker
 * than the one before it.
 *
 * Visibility contract (product decision, 2026-09-04):
 *
 *   local/moodesk:viewall is genuinely global. A user who holds it sees every
 *   ticket, in the list, in the detail, in its attachments and in every
 *   operation that depends on visibility. Department membership narrows what an
 *   agent chooses to look at — the Department and Team views — it does not
 *   decide what they are allowed to reach.
 *
 * If departmental isolation is ever wanted, it has to be expressed through
 * explicit capabilities or rules that an administrator can see in the role
 * editor. It must never reappear as a quiet extra condition on one path, which
 * is what the old RESTRICTIVE_MODE hook would have been.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\policy;

/**
 * Decides whether an actor may view, answer or manage a ticket.
 *
 * Management operations include: status transitions, priority changes,
 * assignment, department, category, team, and any field mutation.
 *
 * The write primitives (can_reply, can_add_note) exist for the same reason
 * can_view does. reply_service used to answer them inline, asking only for a
 * capability and never for reach, and it worked only because every caller
 * happened to have checked reach first. The email pipeline was the caller that
 * did not (audit finding V6), and there was no primitive for it to ask. There
 * is now, and no write path may restate the rule instead of calling it.
 */
class ticket_access_policy {
    /** @var \context_system */
    private \context_system $context;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->context = \context_system::instance();
    }

    /**
     * Whether the actor may see the given ticket at all.
     *
     * Decision tree:
     *  1. actorid = 0  → system actor (tasks, automations). Always trusted.
     *  2. :manage      → every ticket.
     *  3. :viewall     → every ticket. Global by product decision; department
     *                    membership is a filter, not a boundary.
     *  4. :viewown     → the tickets they raised.
     *  5. otherwise    → denied.
     *
     * @param int       $actorid Actor user ID. Pass 0 for system/task operations.
     * @param \stdClass $ticket  Raw ticket DB record; needs at least userid.
     * @return bool
     */
    public function can_view(int $actorid, \stdClass $ticket): bool {
        if ($actorid === 0) {
            return true; // System actor (scheduled tasks, automation engine).
        }

        if (
            has_capability('local/moodesk:manage', $this->context, $actorid)
                || has_capability('local/moodesk:viewall', $this->context, $actorid)
        ) {
            return true;
        }

        return has_capability('local/moodesk:viewown', $this->context, $actorid)
            && (int) $ticket->userid === $actorid;
    }

    /**
     * Throw if the actor may not see the ticket.
     *
     * @param int       $actorid
     * @param \stdClass $ticket
     * @throws \required_capability_exception When the actor may not see it.
     */
    public function require_can_view(int $actorid, \stdClass $ticket): void {
        if (!$this->can_view($actorid, $ticket)) {
            throw new \required_capability_exception(
                $this->context,
                'local/moodesk:viewall',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Whether the actor may add a public reply to the given ticket.
     *
     * Decision tree:
     *  1. actorid = 0  → system actor (tasks, automations). Always trusted.
     *  2. cannot view  → denied. Visibility is a precondition of writing: a
     *                    ticket an actor may not reach is not one they may
     *                    answer, whatever capability they hold.
     *  3. :reply       → allowed.
     *  4. requester    → allowed on their own ticket. Step 2 has already
     *                    established they hold :viewown (or better).
     *  5. otherwise    → denied.
     *
     * What this method does NOT decide: whether the ticket is in a state that
     * accepts a reply at all. Closed and merged are lifecycle, not
     * authorisation, and each channel answers them differently on purpose — the
     * UI hides the composer, an emailed reply to a closed ticket opens a new
     * one. Those rules stay in reply_service, so "who" and "in what state" do
     * not become two places that both decide "closed".
     *
     * @param int       $actorid Actor user ID. Pass 0 for system/task operations.
     * @param \stdClass $ticket  Raw ticket DB record; needs at least userid.
     * @return bool
     */
    public function can_reply(int $actorid, \stdClass $ticket): bool {
        if ($actorid === 0) {
            return true; // System actor (scheduled tasks, automation engine).
        }

        if (!$this->can_view($actorid, $ticket)) {
            return false;
        }

        if (has_capability('local/moodesk:reply', $this->context, $actorid)) {
            return true;
        }

        return (int) $ticket->userid === $actorid;
    }

    /**
     * Throw if the actor may not reply to the ticket.
     *
     * @param int       $actorid
     * @param \stdClass $ticket
     * @throws \required_capability_exception When the actor may not reply.
     */
    public function require_can_reply(int $actorid, \stdClass $ticket): void {
        if (!$this->can_reply($actorid, $ticket)) {
            throw new \required_capability_exception(
                $this->context,
                'local/moodesk:reply',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Whether the actor may add an internal note to the given ticket.
     *
     * Decision tree:
     *  1. actorid = 0  → system actor (tasks, automations). Always trusted.
     *  2. cannot view  → denied, for the same reason as can_reply().
     *  3. :addnote     → allowed.
     *  4. otherwise    → denied.
     *
     * There is deliberately no requester branch: an internal note is the one
     * reply type its own ticket's requester must never be able to write.
     *
     * @param int       $actorid Actor user ID. Pass 0 for system/task operations.
     * @param \stdClass $ticket  Raw ticket DB record; needs at least userid.
     * @return bool
     */
    public function can_add_note(int $actorid, \stdClass $ticket): bool {
        if ($actorid === 0) {
            return true; // System actor (scheduled tasks, automation engine).
        }

        return $this->can_view($actorid, $ticket)
            && has_capability('local/moodesk:addnote', $this->context, $actorid);
    }

    /**
     * Throw if the actor may not add an internal note to the ticket.
     *
     * @param int       $actorid
     * @param \stdClass $ticket
     * @throws \required_capability_exception When the actor may not add a note.
     */
    public function require_can_add_note(int $actorid, \stdClass $ticket): void {
        if (!$this->can_add_note($actorid, $ticket)) {
            throw new \required_capability_exception(
                $this->context,
                'local/moodesk:addnote',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Whether the actor may perform management operations on the given ticket.
     *
     * Decision tree:
     *  1. actorid = 0 → system actor (tasks, automations). Always trusted.
     *  2. :manage     → allowed on any ticket.
     *  3. otherwise   → denied.
     *
     * @param int       $actorid  Actor user ID. Pass 0 for system/task operations.
     * @param \stdClass $ticket   Raw ticket DB record.
     * @return bool
     */
    public function can_manage(int $actorid, \stdClass $ticket): bool {
        if ($actorid === 0) {
            return true; // System actor (scheduled tasks, automation engine).
        }

        return has_capability('local/moodesk:manage', $this->context, $actorid);
    }

    /**
     * Throw if the actor is not allowed to manage the ticket.
     *
     * @param int       $actorid
     * @param \stdClass $ticket
     * @throws \required_capability_exception  When actor lacks :manage.
     * @throws \moodle_exception               When restrictive check fails.
     */
    public function require_can_manage(int $actorid, \stdClass $ticket): void {
        if (!$this->can_manage($actorid, $ticket)) {
            throw new \required_capability_exception(
                $this->context,
                'local/moodesk:manage',
                'nopermissions',
                ''
            );
        }
    }
}
