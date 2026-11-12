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
 * Sections above the form of an existing article.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add sections above the knowledge base article form.')]
#[\core\attribute\tags('local_moodesk')]
final class kb_article_edit_panels extends section_collection {
    /**
     * Create the hook.
     *
     * @param \stdClass $article The article record. Read it; do not change it.
     * @param int       $actorid The editor.
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $article,
        /** @var int */
        public readonly int $actorid,
    ) {
    }
}
