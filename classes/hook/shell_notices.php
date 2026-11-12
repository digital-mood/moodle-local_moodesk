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
 * Lets a plugin show notices above the content of every mooDesk page.
 *
 * Dispatched by {@see \local_moodesk\output\app_shell} when it builds its
 * context. A callback adds a notice as a template and its context; the shell
 * renders each one, in the order added, in a slot above the page content. The
 * callback decides who sees a notice: the shell shows whatever it is given.
 * With no notice the shell markup is unchanged.
 *
 * The over-capacity notice of the add-on is the first user
 * (docs/two-plugin-split-plan.md §6). A notice carries no purchase link and
 * no link outside the site (§6.1).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add notices above the content of every mooDesk page.')]
#[\core\attribute\tags('local_moodesk')]
final class shell_notices {
    /** @var array<int, array{template: string, context: array}> The notices, in order. */
    private array $notices = [];

    /**
     * Create the hook.
     *
     * @param string $pagekey The page key of the current screen (as given to app_page).
     */
    public function __construct(
        /** @var string */
        public readonly string $pagekey,
    ) {
    }

    /**
     * Add a notice.
     *
     * @param string $template A Mustache template name, e.g. 'local_moodeskpro/capacity_notice'.
     * @param array  $context  Its context.
     * @throws \coding_exception On an empty template name.
     */
    public function add_notice(string $template, array $context = []): void {
        if ($template === '') {
            throw new \coding_exception('A notice template is required');
        }
        $this->notices[] = ['template' => $template, 'context' => $context];
    }

    /**
     * The notices added so far, in order.
     *
     * @return array<int, array{template: string, context: array}>
     */
    public function get_notices(): array {
        return $this->notices;
    }
}
