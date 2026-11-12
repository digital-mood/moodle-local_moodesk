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
 * A knowledge base search ran. Carries no text unless it found nothing, and then
 * only the redacted words (docs/two-plugin-split-plan.md §8.2).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Tells plugins about a knowledge base search, with its redacted words when it found nothing.')]
#[\core\attribute\tags('local_moodesk')]
final class kb_search_recorded {
    /**
     * Create the hook.
     *
     * @param int         $userid      Who searched.
     * @param int         $resultcount What the search returned.
     * @param string|null $words       The redacted words, only when it found nothing.
     */
    public function __construct(
        /** @var int */
        public readonly int $userid,
        /** @var int */
        public readonly int $resultcount,
        /** @var string|null */
        public readonly ?string $words,
    ) {
    }
}
