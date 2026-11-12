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

/**
 * The invariant that keeps RISK_XSS off every capability.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * Structural guard: nothing in the tree renders user text unclean.
 *
 * db/access.php declares riskbitmask 0 on every capability, and since KB-01
 * that rests on a promise about rendering rather than about storage: an
 * article body is HTML, kept exactly as authored, and made safe by
 * format_text() on the way out — which only holds while nothing passes
 * `noclean` and no editor is configured with `trusttext`. Either option is
 * one line to add and easy to add for a good-looking reason, so this test
 * reads the tree and fails on the line. If the day comes that one of them
 * is wanted, the mask on local/moodesk:editkb changes with it, and
 * tests/db/access_test.php::test_no_capability_claims_xss is updated to say
 * why.
 *
 * @covers \local_moodesk\db
 */
final class kb_html_rendering_test extends \basic_testcase {
    /**
     * Patterns that would hand raw HTML to a reader, with what each one is.
     *
     * @var array<string, string> regex => what it means
     */
    private const FORBIDDEN = [
        // The option array handed to format_text() or format_string().
        '~[\'"]noclean[\'"]\s*=>\s*(true|1)\b~i'        => 'a noclean option handed to format_text()',
        // The same option set on an options variable before the call.
        '~noclean[\'"]?\s*\]?\s*=\s*(true|1)\s*;~i'      => 'a noclean option set on a variable',
        // Editor / form element options: ['trusttext' => true].
        '~[\'"]trusttext[\'"]\s*=>\s*(true|1)\b~i'      => 'an editor configured with trusttext',
        // The trusttext API itself.
        '~\btrusttext_(pre_edit|prepare_edit|active|strip|trusted)\s*\(~i' => 'a call into the trusttext API',
    ];

    /**
     * Every PHP and Mustache file shipped in the plugin, tests excluded.
     *
     * @return string[] Absolute paths.
     */
    private function shipped_files(): array {
        global $CFG;
        $root  = $CFG->dirroot . '/local/moodesk';
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!preg_match('~\.(php|mustache)$~', $path)) {
                continue;
            }
            if (str_starts_with($path, $root . '/tests/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);
        $this->assertNotEmpty($files);
        return $files;
    }

    /**
     * No shipped file opts out of cleaning.
     */
    public function test_nothing_renders_unclean(): void {
        global $CFG;
        $hits = [];
        foreach ($this->shipped_files() as $path) {
            $source = file_get_contents($path);
            foreach (self::FORBIDDEN as $pattern => $meaning) {
                if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as [, $offset]) {
                        $line   = substr_count(substr($source, 0, $offset), "\n") + 1;
                        $hits[] = str_replace($CFG->dirroot . '/', '', $path) . ":$line — $meaning";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "These lines would render user-authored text without cleaning it. The plugin's "
                . "capabilities declare no RISK_XSS on the strength of nothing doing this; "
                . "either remove the option or change the mask on local/moodesk:editkb and "
                . "the reasoning in db/access.php:\n" . implode("\n", $hits)
        );
    }

    /**
     * A Mustache template never emits an article body unescaped.
     *
     * Bodies come out of format_text() already HTML, so a template has to use
     * a triple mustache to show one — which is fine only when what it receives
     * has been through format_text(). This pins the naming: a template may
     * emit `{{{body}}}` only under a name that says it was rendered.
     */
    public function test_templates_only_emit_rendered_bodies(): void {
        global $CFG;
        $hits = [];
        foreach ($this->shipped_files() as $path) {
            if (!str_ends_with($path, '.mustache')) {
                continue;
            }
            $source = file_get_contents($path);
            if (preg_match_all('~\{\{\{\s*(body|article\.body|rawbody)\s*\}\}\}~', $source, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$match, $offset]) {
                    $line   = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $hits[] = str_replace($CFG->dirroot . '/', '', $path) . ":$line — $match";
                }
            }
        }
        $this->assertSame([], $hits, "A template emits a body that may not have been rendered:\n" . implode("\n", $hits));
    }
}
