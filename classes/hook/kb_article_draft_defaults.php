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
 * Lets a plugin start a new article from a value in the page's URL. A callback may
 * refuse by throwing, as the page's own checks do.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to prefill a new knowledge base article.')]
#[\core\attribute\tags('local_moodesk')]
final class kb_article_draft_defaults {
    /** @var array|null Form defaults: title, categoryid, lang. */
    private ?array $defaults = null;

    /** @var string HTML of the notice above the form. */
    private string $notice = '';

    /**
     * Create the hook.
     *
     * @param int $fromticket The ticket the URL names.
     * @param int $actorid    The editor.
     */
    public function __construct(
        /** @var int */
        public readonly int $fromticket,
        /** @var int */
        public readonly int $actorid,
    ) {
    }

    /**
     * Set what the form starts with.
     *
     * @param array  $defaults title, categoryid, lang.
     * @param string $notice   HTML of the notice above the form.
     */
    public function set_defaults(array $defaults, string $notice = ''): void {
        $this->defaults = $defaults;
        $this->notice = $notice;
    }

    /**
     * What the form starts with, or null.
     *
     * @return array|null
     */
    public function get_defaults(): ?array {
        return $this->defaults;
    }

    /**
     * The notice above the form.
     *
     * @return string
     */
    public function get_notice(): string {
        return $this->notice;
    }
}
