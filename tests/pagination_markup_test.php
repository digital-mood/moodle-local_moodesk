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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * What the shell is allowed to do to markup Moodle renders inside it.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * A base reset must stay light enough to lose.
 *
 * The shell wraps screens that render markup we do not write: moodleform, the
 * file picker, core dialogs, the paging bar. Some of that markup colours its own
 * links against its own background, and it is right every time — it knows what
 * is behind them and we do not.
 *
 * The link reset learned that twice. First it was written as a bare `a`, at
 * (0,1,1), and lost to a button variant's own colour by one element, so
 * `+ New department` rendered blue on blue at 1.00:1. The fix excluded the
 * components — and spelled the exclusions as :not(.a):not(.b):not(.c), which
 * took the selector to (0,4,1). That outranks `.active > .page-link` at (0,2,0),
 * so the pagination's current page stopped being white on Moodle's blue and
 * became brand blue on Moodle's blue: 1.04:1, the same defect one component
 * over.
 *
 * :where() is what reconciles them: it contributes no specificity, so the
 * exclusions are free and the selector stays (0,1,1). Anything Moodle ships with
 * an opinion of its own wins again.
 *
 * These assertions read the sources, like {@see pill_markup_test}. What is being
 * checked is a property of the cascade, and the cascade is not something a
 * render test can see.
 *
 * @coversNothing
 */
final class pagination_markup_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/..';

    /**
     * Read one file below the plugin root.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    private function source(string $relative): string {
        $path = self::PLUGIN_DIR . '/' . $relative;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * The exclusions may not buy specificity.
     *
     * A chain of :not() is the tempting way to write this and the wrong one: it
     * fixes whichever component prompted it and leaves the next one to find the
     * same edge, one specificity point higher.
     */
    public function test_the_link_reset_excludes_without_gaining_weight(): void {
        $scss = $this->source('scss/base/_app-reset.scss');

        $this->assertMatchesRegularExpression(
            '/a:not\(:where\([^)]*\)\)\s*\{/',
            $scss,
            'The link reset must spell its exclusions inside :where(), which costs no specificity.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/a:not\(\.[a-z-]+\):not\(/',
            $scss,
            'Chained :not() raises the reset above markup Moodle colours itself.'
        );
    }

    /**
     * The same property, asserted against the stylesheet that actually ships.
     */
    public function test_the_built_stylesheet_carries_the_light_reset(): void {
        $css = $this->source('styles.css');

        $this->assertStringContainsString(
            '.moodesk-app a:not(:where(',
            $css,
            'styles.css is out of date with scss/base/_app-reset.scss — run: npm run scss'
        );
    }

    /**
     * The paging bar is core's, and its colours are Bootstrap's.
     *
     * The current page is white on blue at 5.36:1 because Bootstrap says so. The
     * moment a stylesheet here starts having opinions about that, the contrast
     * becomes ours to maintain against a background we do not control.
     */
    public function test_no_stylesheet_here_colours_the_paging_bar(): void {
        $dir       = self::PLUGIN_DIR . '/scss';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'scss') {
                continue;
            }

            $inpagelink = false;
            foreach (file($file->getPathname()) as $number => $line) {
                $code = trim((string) preg_replace('|//.*$|', '', $line));
                if ($code === '') {
                    continue;
                }
                // A selector that names .page-link inside :not() or :where() is
                // excluding it, not styling it — that is the reset, and it is
                // the whole point. Strip those before deciding.
                $selector = preg_replace('/:(not|where|is)\([^)]*\)/', '', $code);
                // Track the one block allowed to mention .page-link at all.
                if (str_contains((string) $selector, '.page-link')) {
                    $inpagelink = str_ends_with($code, '{');
                    continue;
                }
                if ($inpagelink) {
                    if (str_starts_with($code, '}')) {
                        $inpagelink = false;
                        continue;
                    }
                    if (preg_match('/^(color|background|background-color|border-color|border)\s*:/', $code)) {
                        $offenders[] = str_replace($dir . '/', '', $file->getPathname()) . ':' . ($number + 1)
                            . ' — ' . $code;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Colour on the paging bar belongs to Bootstrap: ' . implode(' | ', $offenders)
        );
    }

    /**
     * The current page is marked by something other than its colour.
     *
     * Bootstrap inverts it, which is a hue difference and nothing else. The
     * hook is core's own aria-current, so the marker cannot outlive the
     * semantics it is standing in for.
     */
    public function test_the_current_page_carries_a_non_chromatic_marker(): void {
        $scss = $this->source('scss/vendors/_moodle-pagination.scss');

        $this->assertMatchesRegularExpression(
            '/\.page-link\[aria-current=[\'"]?page[\'"]?\]/',
            $scss,
            'The current page must be selected through aria-current, not through .active alone.'
        );
        $this->assertStringContainsString(
            'font-weight',
            $scss,
            'The current page needs a marker that survives losing colour.'
        );
    }
}
