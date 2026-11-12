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

namespace local_moodesk;

use local_moodesk\form\department_form;
use local_moodesk\output\form_page;
use local_moodesk\output\page_header;
use local_moodesk\service\edition_service;

/**
 * The form page's contract, enforced against the entry points and stylesheets.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The form page's contract, enforced against the entry points and stylesheets.
 *
 * ── What went wrong without it ───────────────────────────────────────────────
 *
 * One screen had the product's form surface and seven did not, and nothing said
 * so. The seven handed `$form->render()` straight to the shell and inherited
 * Boost's own field row: a `col-md-3` label column beside a `col-md-9` control
 * column, flipping to stacked at 768px because Bootstrap says so, with
 * `word-break: break-word` on the label.
 *
 * On the department screen that produced the bug this file exists to keep
 * closed. Its longest label is "Resolution time — Normal (hours)"; the column it
 * had to fit in was about 178px; `break-word` did the rest, and the label came
 * out over four lines, broken mid-word. Meanwhile an iPad held upright sat on
 * the wide side of Bootstrap's breakpoint and kept two columns it had no room
 * for, while a phone — below the breakpoint, stacked — read perfectly.
 *
 * The fix is not CSS for department. It is that a form page is a type, the
 * layout is one set of mixins, and the number of columns is decided by the
 * container rather than by a breakpoint nobody here chose. What a grep can check
 * of that is checked here.
 *
 * @coversNothing
 */
final class form_page_markup_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/..';

    /** @var string The one file allowed to declare the moodleform reset. */
    private const COMPONENT = '/scss/components/_moodleform.scss';

    /**
     * The reply composer shares moodleform's markup but not its shape.
     *
     * It dissolves the form with `display: contents`, lays the fields on a grid
     * of its own, hides the label the tab row already states, and pins a send
     * button to the message box. Including the stacked mixin there would import
     * a layout it immediately has to undo, so it keeps its own rules — named
     * here so the next reader can tell a deliberate exception from a fourth copy.
     *
     * @var string[]
     */
    private const NOT_A_FORM_SURFACE = [
        'modules/_ticket-detail.scss',
    ];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * The surface is a header and a card, in that order, inside the page.
     */
    public function test_it_wraps_the_form_in_the_product_surface(): void {
        $this->setAdminUser();

        $html = form_page::render('<form class="mform">FIELDS</form>', new page_header('Edit department'));

        $page = strpos($html, 'moodesk-form-page');
        $head = strpos($html, 'mhd-page-header');
        $card = strpos($html, 'moodesk-form-card');

        $this->assertNotFalse($page, 'The form page wrapper is missing.');
        $this->assertNotFalse($head, 'The page header is missing.');
        $this->assertNotFalse($card, 'The form card is missing.');
        $this->assertLessThan($head, $page, 'The header must be inside the page wrapper.');
        $this->assertLessThan($card, $head, 'The header must come before the card.');
        $this->assertStringContainsString('FIELDS', $html, 'The form must be passed through verbatim.');
    }

    /**
     * Every screen whose body is a moodleform uses the shared surface.
     *
     * This is the assertion that would have failed before the migration, on all
     * seven admin edit screens at once.
     */
    public function test_every_shell_form_page_uses_the_shared_surface(): void {
        $offenders = [];

        foreach (glob(self::PLUGIN_DIR . '/*.php') as $path) {
            $source = file_get_contents($path);

            // A screen that mounts a moodleform in the application shell.
            if (!str_contains($source, 'render_within_shell') || !str_contains($source, '$form->render()')) {
                continue;
            }

            if (!str_contains($source, 'form_page::render')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Shell form pages not using form_page: ' . implode(' | ', $offenders)
        );
    }

    /**
     * One file describes what a moodleform looks like in mooDesk.
     *
     * The copies had already drifted once: the card's never zeroed the grid
     * gutter, so every control sat 12px past its own label, and never handled
     * the label addon, so the required marker drifted to the far edge of a
     * full-width cell. A screen got whichever behaviour it happened to inherit.
     */
    public function test_the_field_reset_is_declared_in_one_place(): void {
        $dir     = self::PLUGIN_DIR . '/scss';
        $allowed = realpath(self::PLUGIN_DIR . self::COMPONENT);

        // The selectors that make a Boost field row stacked. A file declaring
        // one of these is describing the reset rather than including it.
        $reset = [
            '.fitem > .col-form-label',
            '.fitem > .felement',
        ];

        $offenders = [];

        foreach ($this->stylesheets() as $path) {
            $relative = str_replace($dir . '/', '', $path);
            if (realpath($path) === $allowed || in_array($relative, self::NOT_A_FORM_SURFACE, true)) {
                continue;
            }

            foreach (file($path) as $number => $line) {
                // Comments may name a selector; rules may not declare it.
                $code = (string) preg_replace('|//.*$|', '', $line);
                foreach ($reset as $selector) {
                    if (str_contains($code, $selector)) {
                        $offenders[] = $relative . ':' . ($number + 1) . ' ' . $selector;
                    }
                }
            }
        }

        $this->assertFileExists(self::PLUGIN_DIR . self::COMPONENT);
        $this->assertSame(
            [],
            $offenders,
            'The moodleform reset is declared outside its component: ' . implode(' | ', $offenders)
        );
    }

    /**
     * The superseded wrapper is gone, not merely unused.
     *
     * `.mhd-sla-grid` was a class name emitted by the department form for two
     * years and styled by nothing at all. The only thing it ever did was carry
     * Bootstrap's `.row` alongside it. A dead name that still ships is a name
     * the next screen can find and copy.
     */
    public function test_the_superseded_sla_grid_is_deleted(): void {
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PLUGIN_DIR));
        foreach ($it as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'scss', 'mustache'], true)) {
                continue;
            }
            if (str_contains($file->getPathname(), '/tests/')) {
                continue;
            }
            if (str_contains(file_get_contents($file->getPathname()), 'mhd-sla-grid')) {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'mhd-sla-grid still ships in: ' . implode(' | ', $offenders));
    }

    /**
     * The field grid's track minimum is capped at the container.
     *
     * A bare `minmax(340px, 1fr)` keeps the track 340px wide even when the
     * container is narrower than that — one column cannot become half a column.
     * Measured on the department form at a 320px viewport: the card's inner
     * width was 256px, the track stayed 340px, and the field ran 61px past the
     * card's right edge, where `overflow: hidden` clipped it with no scrollbar
     * to reveal what had been cut. `min($min, 100%)` lets the last column shrink
     * with its container instead.
     *
     * Asserted against the SOURCE rather than the compiled stylesheet because
     * this is a decision about the mixin; that the compiled file matches its
     * source is the build job's assertion, not this one's.
     */
    public function test_the_field_grid_track_cannot_outgrow_its_container(): void {
        $source = file_get_contents(self::PLUGIN_DIR . self::COMPONENT);

        $this->assertStringContainsString(
            'minmax(min($min, 100%), 1fr)',
            $source,
            'The field grid must cap its track minimum at the container width.'
        );
    }

    /**
     * Every stylesheet in the plugin.
     *
     * @return string[] Absolute paths.
     */
    private function stylesheets(): array {
        $paths = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PLUGIN_DIR . '/scss'));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'scss') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }
}
