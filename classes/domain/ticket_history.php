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
 * Ticket history domain constants.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Action constants for the immutable ticket audit log.
 *
 * These string constants are stored verbatim in local_moodesk_ticket_history.action.
 * They must never be renamed after data is in production — doing so would
 * break any query that filters history by action type.
 *
 * To add a new action: add a constant here and emit it from the relevant
 * service method. Never write raw strings into the history table.
 */
class ticket_history {
    /**
     * Ticket was first created.
     * oldvalue: null. newvalue: JSON with initial status, priority, categoryid.
     */
    const ACTION_CREATED = 'created';

    /**
     * Status field changed.
     * oldvalue: {"status": N}. newvalue: {"status": N}, plus "auditid" when the
     * change is the undo of a merge restoring the source's status.
     */
    const ACTION_STATUS_CHANGED = 'status_changed';

    /**
     * Priority field changed.
     * oldvalue: {"priority": N}. newvalue: {"priority": N}.
     */
    const ACTION_PRIORITY_CHANGED = 'priority_changed';

    /**
     * Ticket was assigned to an agent.
     * oldvalue: {"assigneeid": N|null}. newvalue: {"assigneeid": N}.
     */
    const ACTION_ASSIGNED = 'assigned';

    /**
     * Ticket was unassigned (assigneeid set to null).
     * oldvalue: {"assigneeid": N}. newvalue: {"assigneeid": null}.
     */
    const ACTION_UNASSIGNED = 'unassigned';

    /**
     * Category changed.
     * oldvalue: {"categoryid": N}. newvalue: {"categoryid": N}.
     */
    const ACTION_CATEGORY_CHANGED = 'category_changed';

    /**
     * A reply or internal note was added to the thread.
     * oldvalue: null. newvalue: {"replyid": N, "type": N}.
     */
    const ACTION_REPLY_ADDED = 'reply_added';

    /**
     * Department changed. Phase 3+.
     * oldvalue: {"departmentid": N|null}. newvalue: {"departmentid": N|null}.
     */
    const ACTION_DEPARTMENT_CHANGED = 'department_changed';

    /**
     * This ticket was merged into another ticket. Phase 11.B+.
     * oldvalue: null. newvalue: {"targetid": N}.
     */
    const ACTION_MERGED_INTO = 'merged_into';

    /**
     * Another ticket was merged into this ticket. Phase 11.B+.
     * oldvalue: null. newvalue: {"sourceid": N}.
     */
    const ACTION_MERGED_FROM = 'merged_from';

    /**
     * A merge was undone within its TTL. Phase 12+.
     * Written to BOTH tickets involved.
     * oldvalue: null. newvalue: {"auditid": N}.
     */
    const ACTION_MERGE_REVERTED = 'merge_reverted';

    /**
     * Team assignment changed. Phase 11.E+.
     * oldvalue: {"teamid": N|null}. newvalue: {"teamid": N|null}.
     */
    const ACTION_TEAM_ASSIGNED = 'team_assigned';

    /**
     * The ticket's email reply tokens were reset (SEC-04A).
     *
     * Every tokenised reply address issued for this conversation before the
     * reset stops working. Nothing about the ticket or its replies changes —
     * this records the withdrawal of a capability, not a change to the record.
     */
    const ACTION_REPLY_TOKENS_RESET = 'reply_tokens_reset';

    /**
     * Return all defined action constants.
     *
     * Useful for validation and reporting queries.
     *
     * @return string[]
     */
    public static function all_actions(): array {
        return [
            self::ACTION_CREATED,
            self::ACTION_STATUS_CHANGED,
            self::ACTION_PRIORITY_CHANGED,
            self::ACTION_ASSIGNED,
            self::ACTION_UNASSIGNED,
            self::ACTION_CATEGORY_CHANGED,
            self::ACTION_REPLY_ADDED,
            self::ACTION_DEPARTMENT_CHANGED,
            self::ACTION_MERGED_INTO,
            self::ACTION_MERGED_FROM,
            self::ACTION_MERGE_REVERTED,
            self::ACTION_TEAM_ASSIGNED,
        ];
    }
}
