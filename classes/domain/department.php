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
 * Department domain constants.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Department domain constants and helpers.
 *
 * Departments serve as both organisational units and routing queues.
 * A ticket assigned to a department is visible to all agents who are
 * members of that department, in addition to any explicitly assigned agent.
 *
 * Tickets with departmentid = null are globally visible to all agents
 * (backwards-compatible with Phase 1/2 behaviour where no departments exist).
 *
 * Agent workspace views:
 *   VIEW_ALL        — All tickets accessible to this agent (dept membership + own).
 *   VIEW_MINE       — Tickets assigned to me.
 *   VIEW_UNASSIGNED — Unassigned tickets in my departments (or global).
 *   VIEW_DEPARTMENT — Tickets in departments I belong to.
 */
class department {
    /** Active department — visible in dropdowns, accepts new tickets. */
    const STATUS_ACTIVE = 1;

    /** Archived department — hidden from dropdowns, no new tickets routed here. */
    const STATUS_ARCHIVED = 0;

    /*
     * -------------------------------------------------------------------------
     * Agent workspace view identifiers (used by ticket_service::get_all_tickets)
     * -------------------------------------------------------------------------
     */

    /** Show all tickets accessible to the agent based on dept membership. */
    const VIEW_ALL = 'all';

    /** Show only tickets assigned directly to the acting agent. */
    const VIEW_MINE = 'mine';

    /** Show unassigned tickets accessible to the agent. */
    const VIEW_UNASSIGNED = 'unassigned';

    /** Show tickets belonging to departments the agent is a member of. */
    const VIEW_DEPARTMENT = 'department';

    /**
     * Return the list of valid view identifiers.
     *
     * @return string[]
     */
    public static function valid_views(): array {
        return [
            self::VIEW_ALL,
            self::VIEW_MINE,
            self::VIEW_UNASSIGNED,
            self::VIEW_DEPARTMENT,
        ];
    }
}
