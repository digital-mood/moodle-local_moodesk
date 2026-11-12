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
 * Articles were offered as Suggested Articles. The subject typed is not part of it.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Tells plugins which articles were offered while a ticket was being written.')]
#[\core\attribute\tags('local_moodesk')]
final class kb_suggestions_shown {
    /**
     * Create the hook.
     *
     * @param int[] $articleids The rows offered.
     * @param int   $userid     Who they were offered to.
     */
    public function __construct(
        /** @var int[] */
        public readonly array $articleids,
        /** @var int */
        public readonly int $userid,
    ) {
    }
}
