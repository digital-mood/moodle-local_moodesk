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
 * Reply domain constants.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Reply type constants.
 *
 * All three types share the local_moodesk_reply table.
 * The type column determines visibility and rendering:
 *
 *  PUBLIC_REPLY    — visible to both requester and agents.
 *  INTERNAL_NOTE   — visible to agents only. The service layer enforces this;
 *                    requesters must never receive internal note content.
 *  SYSTEM_EVENT    — machine-written entries recording state changes inline
 *                    in the thread (e.g. "Status changed to Resolved by Agent X").
 *                    These are distinct from ticket_history, which is the
 *                    queryable audit log. System events are for display only.
 */
class reply {
    /** Visible to requester and all agents. */
    const TYPE_PUBLIC_REPLY   = 0;

    /** Visible to agents only. Never exposed to requesters. */
    const TYPE_INTERNAL_NOTE  = 1;

    /**
     * Machine-generated thread entry for state change visibility.
     * Written by the service layer when status, priority, or assignment changes.
     * Not created by users directly.
     */
    const TYPE_SYSTEM_EVENT   = 2;

    /**
     * Return the types that are visible to a requester.
     *
     * reply_repository uses this when building the thread for a non-agent user.
     *
     * @return int[]
     */
    public static function requester_visible_types(): array {
        return [self::TYPE_PUBLIC_REPLY, self::TYPE_SYSTEM_EVENT];
    }

    /**
     * Return true if the given type constant is valid.
     *
     * @param int $type
     * @return bool
     */
    public static function is_valid_type(int $type): bool {
        return in_array($type, [
            self::TYPE_PUBLIC_REPLY,
            self::TYPE_INTERNAL_NOTE,
            self::TYPE_SYSTEM_EVENT,
        ], true);
    }
}
