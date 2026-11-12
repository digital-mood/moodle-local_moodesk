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
 * A person is being erased, and the ids that lead back to them are still there.
 *
 * Dispatched by {@see \local_moodesk\privacy\provider} before it zeroes the
 * ticket and reply references, so a plugin can reach the rows it keeps about this
 * person's tickets. The order in which Moodle runs the providers is not defined,
 * and a plugin's own provider may find these ids already gone.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Lets plugins erase what they keep about a person before their ticket references are zeroed.')]
#[\core\attribute\tags('local_moodesk')]
final class user_erasing {
    /**
     * Create the hook.
     *
     * @param int   $userid    The person.
     * @param int[] $ticketids The tickets they raised.
     * @param int[] $replyids  The replies they wrote.
     */
    public function __construct(
        /** @var int */
        public readonly int $userid,
        /** @var int[] */
        public readonly array $ticketids,
        /** @var int[] */
        public readonly array $replyids,
    ) {
    }
}
