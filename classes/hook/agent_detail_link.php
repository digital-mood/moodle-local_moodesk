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
 * Asks plugins for the page that shows one agent.
 *
 * Dispatched by agents.php. The directory adds the agent's `userid` to the URL
 * and renders no link when nobody answers.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to link a page per agent from the mooDesk agent directory.')]
#[\core\attribute\tags('local_moodesk')]
final class agent_detail_link {
    /** @var \moodle_url|null The page, null when no plugin offers one. */
    private ?\moodle_url $url = null;

    /**
     * Name the page.
     *
     * @param \moodle_url $url
     */
    public function set_url(\moodle_url $url): void {
        $this->url = $url;
    }

    /**
     * The page, if a plugin offers one.
     *
     * @return \moodle_url|null
     */
    public function get_url(): ?\moodle_url {
        return $this->url;
    }
}
