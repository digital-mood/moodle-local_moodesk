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

namespace local_moodesk\hook;

/**
 * Tells plugins an agent is losing agent status.
 *
 * Dispatched by {@see \local_moodesk\service\agent_service::revoke_agent()} with
 * the role still in place, so a plugin can clear what it holds for the agent.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Tells plugins that a mooDesk agent is losing agent status.')]
#[\core\attribute\tags('local_moodesk')]
final class agent_revoking {
    /**
     * Create the hook.
     *
     * @param int $userid The agent.
     */
    public function __construct(
        /** @var int */
        public readonly int $userid,
    ) {
    }
}
