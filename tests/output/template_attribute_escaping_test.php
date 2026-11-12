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

namespace local_moodesk\output;

/**
 * No template puts an unescaped `{{{ }}}` inside an HTML attribute (audit H6).
 *
 * There were 151 such sites, every one a URL from `moodle_url::out(false)`,
 * which is raw `&`: correct only because nothing else ever reached them. A
 * value that can carry a quote — the webhook URL was the one — could close
 * the attribute. All of them are `{{ }}` now, and this keeps it that way: a
 * value that really is pre-escaped HTML belongs in element content, not in an
 * attribute.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class template_attribute_escaping_test extends \advanced_testcase {
    public function test_no_triple_stash_inside_an_attribute_value(): void {
        global $CFG;

        $offenders = [];
        foreach (glob($CFG->dirroot . '/local/moodesk/templates/*.mustache') as $path) {
            // Comments may quote markup; they are not rendered.
            $source = preg_replace('/\{\{!.*?\}\}/s', '', file_get_contents($path));

            // An attribute value opens with =" or =' and runs to the matching
            // quote; a triple-stash before that quote is inside it.
            if (preg_match_all('/=(["\'])(?:(?!\1).)*?\{\{\{/s', $source, $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = basename($path) . ': ' . trim(substr($match, 0, 80));
                }
            }
        }

        $this->assertSame([], $offenders, 'Use {{ }} for values rendered inside an attribute.');
    }
}
