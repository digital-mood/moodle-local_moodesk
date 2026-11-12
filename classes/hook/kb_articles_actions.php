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
 * Secondary actions of the knowledge base management list.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add secondary actions to the knowledge base management list.')]
#[\core\attribute\tags('local_moodesk')]
final class kb_articles_actions {
    /** @var \local_moodesk\output\header_action[] The actions, in order. */
    private array $actions = [];

    /**
     * Create the hook.
     *
     * @param int $userid The viewer.
     */
    public function __construct(
        /** @var int */
        public readonly int $userid,
    ) {
    }

    /**
     * Add an action.
     *
     * @param \local_moodesk\output\header_action $action
     */
    public function add_action(\local_moodesk\output\header_action $action): void {
        $this->actions[] = $action;
    }

    /**
     * The actions added.
     *
     * @return \local_moodesk\output\header_action[]
     */
    public function get_actions(): array {
        return $this->actions;
    }
}
