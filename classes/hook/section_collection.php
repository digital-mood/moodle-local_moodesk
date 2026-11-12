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
 * What the panel hooks share: a callback adds a template and its context, and
 * the page renders each one, in the order added, in its own slot.
 *
 * The callback decides who sees what; the page renders whatever it is given.
 * With nothing added the page's markup is unchanged.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class section_collection {
    /** @var array<int, array{template: string, context: array}> The sections, in order. */
    private array $sections = [];

    /**
     * Add a section.
     *
     * @param string $template A Mustache template name.
     * @param array  $context  Its context.
     * @throws \coding_exception On an empty template name.
     */
    public function add_section(string $template, array $context = []): void {
        if ($template === '') {
            throw new \coding_exception('A section template is required');
        }
        $this->sections[] = ['template' => $template, 'context' => $context];
    }

    /**
     * The sections added so far, in order.
     *
     * @return array<int, array{template: string, context: array}>
     */
    public function get_sections(): array {
        return $this->sections;
    }

    /**
     * Dispatch the hook and render what was added.
     *
     * Untyped on purpose: the global $OUTPUT is a bootstrap_renderer until the
     * page first uses it, and it forwards render_from_template() all the same.
     *
     * @param \renderer_base|\core\output\bootstrap_renderer $output
     * @return array<int, array{html: string}> One entry per section, in order.
     */
    public function dispatch_and_render($output): array {
        \core\di::get(\core\hook\manager::class)->dispatch($this);

        $html = [];
        foreach ($this->sections as $section) {
            $html[] = ['html' => $output->render_from_template($section['template'], $section['context'])];
        }
        return $html;
    }
}
