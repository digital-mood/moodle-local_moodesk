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
 * The Button component's contract, enforced against the stylesheets and templates.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * One button component, built once and reused — including by Moodle's own markup.
 *
 * The plugin reached twenty-eight different spellings of a Bootstrap button
 * across twenty-six templates before this component existed, plus three places
 * that patched `.btn` inside a moodleform by hand. The way that happened is not
 * carelessness: it is that nothing stopped it. So the parts of the contract that
 * a grep can check are checked here.
 *
 * The migration to `.mhd-btn` ran screen by screen and is finished, so the
 * assertion that was deliberately withheld during it — that no template still
 * spells a button in Bootstrap — is here now. Together with the two rules that
 * held while the migration was under way, that is the whole contract: one
 * component declares the geometry, every button carries a variant, and no
 * screen writes `.btn` any more.
 *
 * The bridge in vendors/_moodle-buttons.scss stays: Moodle emits `.btn` markup
 * of its own inside the shell — moodleform's Save and Cancel, the file picker,
 * core dialogs — and none of it is ours to rewrite.
 *
 * @coversNothing
 */
final class button_markup_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/..';

    /** @var string The component. The only file allowed to declare a dimension. */
    private const COMPONENT = '/scss/components/_button.scss';

    /** @var string The documented bridge. Includes the component's mixins; declares nothing. */
    private const BRIDGE = '/scss/vendors/_moodle-buttons.scss';

    /**
     * Two files may say `.mhd-btn`, and only one of them may say how big it is.
     *
     * A third file with an opinion is how a screen quietly acquires its own
     * button size — which is exactly how the drawer and the ticket detail each
     * ended up with their own `buttonar` patch.
     */
    public function test_only_the_component_styles_the_button(): void {
        $dir       = self::PLUGIN_DIR . '/scss';
        $allowed   = [
            realpath(self::PLUGIN_DIR . self::COMPONENT),
            realpath(self::PLUGIN_DIR . self::BRIDGE),
        ];
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'scss') {
                continue;
            }
            if (in_array(realpath($file->getPathname()), $allowed, true)) {
                continue;
            }
            // Comments may name the component; selectors may not declare it.
            // A `:not()` exclusion is neither: a file is always allowed to say
            // "not this component" — that is how base/_app-reset.scss keeps its
            // link colour off buttons and chips that happen to be anchors.
            foreach (file($file->getPathname()) as $number => $line) {
                $code = preg_replace('|//.*$|', '', $line);
                $code = preg_replace('/:not\([^)]*\)/', '', (string) $code);
                if (str_contains((string) $code, '.mhd-btn')) {
                    $offenders[] = str_replace($dir . '/', '', $file->getPathname()) . ':' . ($number + 1);
                }
            }
        }

        $this->assertFileExists(self::PLUGIN_DIR . self::COMPONENT);
        $this->assertSame([], $offenders, 'Button styling outside the component: ' . implode(' | ', $offenders));
    }

    /**
     * The bridge exists to give Moodle's buttons the component, not to grow a
     * second one. It may include the mixins; it may not restate a number.
     */
    public function test_the_bridge_declares_no_dimension_of_its_own(): void {
        $forbidden = [
            'min-height', 'height', 'padding', 'font-size', 'line-height',
            'font-weight', 'border-radius', 'letter-spacing', 'gap', 'border-width',
        ];
        $offenders = [];

        foreach (file(self::PLUGIN_DIR . self::BRIDGE) as $number => $line) {
            $code = trim((string) preg_replace('|//.*$|', '', $line));
            foreach ($forbidden as $property) {
                if (preg_match('/^' . preg_quote($property, '/') . '\s*:/', $code)) {
                    $offenders[] = ($number + 1) . ': ' . $code;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'The bridge must include the component mixins, not restate values: ' . implode(' | ', $offenders)
        );
    }

    /**
     * A button with no variant renders with no fill and no border — an invisible
     * control. The base carries geometry only, on purpose.
     *
     * ONE TOKEN IS ALLOWED TO BE A VARIABLE, and only its exact spelling:
     * `mhd-btn--{{variant}}`, in the page header's action partial. That value is
     * not the template's to choose — page_header::export_for_template() emits it
     * from the SLOT the action was put into, so it is 'outlined' for a secondary
     * action and 'filled' for the primary one and can be nothing else.
     * page_header_markup_test asserts exactly that, and asserts that
     * header_action never names a variant of its own. So the guarantee this test
     * exists to give is still given here; it is given by the type instead of by
     * the string, and a literal in the template would in fact be the weaker
     * arrangement — it is how two screens ended up with a filled button in the
     * secondary position.
     *
     * Any OTHER interpolated variant is still an offender. The exception is a
     * single exact spelling, not a hole for `mhd-btn--{{anything}}`.
     */
    public function test_every_migrated_button_carries_a_variant(): void {
        $dir       = self::PLUGIN_DIR . '/templates';
        $variants  = ['filled', 'tonal', 'outlined', 'text', 'elevated', 'danger', 'outlined-danger'];
        // The one variant supplied by a slot rather than written down. See above.
        $slotsupplied = 'mhd-btn--{{variant}}';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'mustache') {
                continue;
            }
            $name = str_replace($dir . '/', '', $file->getPathname());
            preg_match_all(
                '/<([a-z]+)\b[^>]*\bclass="([^"]*)"/is',
                file_get_contents($file->getPathname()),
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                $tokens = preg_split('/\s+/', $match[2], -1, PREG_SPLIT_NO_EMPTY);
                if (!in_array('mhd-btn', $tokens, true)) {
                    continue;
                }
                $found = false;
                foreach ($variants as $variant) {
                    if (in_array('mhd-btn--' . $variant, $tokens, true)) {
                        $found = true;
                        break;
                    }
                }
                // A Mustache section can leave its opening tag glued to the end
                // of the class token, so compare on the prefix.
                foreach ($tokens as $token) {
                    if (str_starts_with($token, $slotsupplied)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $offenders[] = "{$name}: <{$match[1]} class=\"{$match[2]}\">";
                }
            }
        }

        $this->assertSame([], $offenders, 'Buttons without a colour variant: ' . implode(' | ', $offenders));
    }

    /**
     * No template spells a button in Bootstrap any more.
     *
     * This is the assertion the migration was working towards. `.btn` in one of
     * our own templates now means a button that missed the component: it renders
     * correctly — the bridge sees to that — while carrying none of the decisions
     * the variant names record, and it is how a screen quietly starts drifting
     * again.
     *
     * Moodle's own `.btn` markup inside the shell is untouched and unaffected:
     * this looks at our templates, which are the only ones we write.
     */
    public function test_no_template_spells_a_button_in_bootstrap(): void {
        $dir       = self::PLUGIN_DIR . '/templates';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'mustache') {
                continue;
            }
            $name = str_replace($dir . '/', '', $file->getPathname());
            preg_match_all(
                '/\bclass="([^"]*)"/i',
                file_get_contents($file->getPathname()),
                $matches,
                PREG_SET_ORDER
            );
            foreach ($matches as $match) {
                foreach (preg_split('/\s+/', $match[1], -1, PREG_SPLIT_NO_EMPTY) as $token) {
                    // Bootstrap spells it btn, btn-primary, btn-sm; never mhd-btn.
                    if (preg_match('/^btn(-[a-z]+)*$/', $token)) {
                        $offenders[] = "{$name}: {$token}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Bootstrap buttons left in our templates: ' . implode(' | ', $offenders)
        );
    }
}
