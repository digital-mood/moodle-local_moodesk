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
 * Outcome of one bulk operation over many tickets.
 *
 * This class contains no database access and no Moodle API calls.
 * It is a pure domain model: a record of what happened, nothing more.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * What a bulk action did, ticket by ticket.
 *
 * A bulk action is not one operation over N tickets — it is N operations that
 * happen to have been requested together. Some of them will fail for reasons
 * that are entirely correct: a status transition the state machine forbids, a
 * ticket that was merged while the agent was reading the list. Those failures
 * must not roll back the tickets that did move, and the agent must be told
 * which ones stayed behind, because a bare count leaves them hunting.
 *
 * So the result carries both halves: the ids that succeeded, and the ids that
 * did not with the reason attached where a safe one exists.
 */
final class bulk_result {
    /** @var int[] Ticket ids the action completed on. */
    private array $succeeded = [];

    /** @var array<int, string> Ticket id => reason, empty string when none is safe to show. */
    private array $failed = [];

    /**
     * Record a ticket the action completed on.
     *
     * @param int $ticketid
     */
    public function record_success(int $ticketid): void {
        $this->succeeded[] = $ticketid;
    }

    /**
     * Record a ticket the action could not be applied to.
     *
     * The reason is shown to the agent, so it may only ever be a message that
     * was written for them. Callers pass an empty string for anything else —
     * a database error's text is for the log, not for the notification.
     *
     * @param int    $ticketid
     * @param string $reason Localised, user-facing reason, or '' when there is none.
     */
    public function record_failure(int $ticketid, string $reason = ''): void {
        $this->failed[$ticketid] = $reason;
    }

    /**
     * Ticket ids the action completed on.
     *
     * @return int[]
     */
    public function succeeded(): array {
        return $this->succeeded;
    }

    /**
     * Ticket ids the action failed on, mapped to their reason.
     *
     * @return array<int, string>
     */
    public function failed(): array {
        return $this->failed;
    }

    /**
     * Ticket ids the action failed on.
     *
     * @return int[]
     */
    public function failed_ids(): array {
        return array_keys($this->failed);
    }

    /**
     * How many tickets the action completed on.
     *
     * @return int
     */
    public function success_count(): int {
        return count($this->succeeded);
    }

    /**
     * How many tickets the action failed on.
     *
     * @return int
     */
    public function failure_count(): int {
        return count($this->failed);
    }

    /**
     * How many tickets were attempted, after normalisation.
     *
     * @return int
     */
    public function processed_count(): int {
        return $this->success_count() + $this->failure_count();
    }

    /**
     * True when nothing was attempted — the selection normalised to empty.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return $this->processed_count() === 0;
    }

    /**
     * True when at least one ticket moved and at least one did not.
     *
     * @return bool
     */
    public function is_partial(): bool {
        return $this->success_count() > 0 && $this->failure_count() > 0;
    }
}
