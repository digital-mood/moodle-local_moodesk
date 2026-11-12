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
 * The Pill component's contract, enforced against the templates and stylesheets.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * One chip component, and no way back.
 *
 * The pills drifted once already: six screens grew their own sizing, three more
 * implementations sat in the stylesheet that no template ever rendered, and a
 * dozen admin screens simply inherited Bootstrap's `.badge`, whose font-size is
 * a percentage and therefore measured differently in every container it landed
 * in. Consolidating that is cheap; keeping it consolidated is the hard part,
 * because the next status label is one `class="badge"` away from starting the
 * drift again.
 *
 * So the contract is a test. Like {@see page_gate_test}, the sources are read
 * rather than rendered: what is being asserted is a property of the markup, and
 * a grep proves it across every screen at once where a render test would prove
 * it for one.
 *
 * @coversNothing
 */
final class pill_markup_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/..';

    /**
     * Every .mustache file in the plugin, keyed by a readable name.
     *
     * @return array<string, string> Relative name => absolute path.
     */
    private function templates(): array {
        $dir   = self::PLUGIN_DIR . '/templates';
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'mustache') {
                $found[str_replace($dir . '/', '', $file->getPathname())] = $file->getPathname();
            }
        }

        ksort($found);
        return $found;
    }

    /**
     * Every element and its class attribute, for one template.
     *
     * @param string $html Template source.
     * @return array<int, array{0: string, 1: string}> [tag name, class attribute] pairs.
     */
    private function elements_with_classes(string $html): array {
        $out = [];
        preg_match_all('/<([a-z]+)\b[^>]*\bclass="([^"]*)"/is', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $out[] = [strtolower($match[1]), $match[2]];
        }
        return $out;
    }

    /**
     * Bootstrap's badge is the theme's, not the product's. Its font-size is a
     * percentage of whatever contains it, so the same label came out one size
     * in a table cell and another beside a heading — which is how the screens
     * drifted apart in the first place.
     */
    public function test_no_template_uses_a_bootstrap_badge(): void {
        $offenders = [];

        foreach ($this->templates() as $name => $path) {
            foreach ($this->elements_with_classes(file_get_contents($path)) as [$tag, $classes]) {
                $tokens = preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($tokens as $token) {
                    if ($token === 'badge' || $token === 'rounded-pill' || str_starts_with($token, 'badge-')) {
                        $offenders[] = "{$name}: <{$tag} class=\"{$classes}\">";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Status labels are Pills, not Bootstrap badges: ' . implode(' | ', $offenders));
    }

    /**
     * A pill with no variant renders with no colour at all — which reads as
     * "this ticket has no status", the one thing a status label must never say
     * by accident. The base carries geometry only, on purpose.
     */
    public function test_every_pill_carries_a_variant(): void {
        $offenders = [];

        foreach ($this->templates() as $name => $path) {
            foreach ($this->elements_with_classes(file_get_contents($path)) as [$tag, $classes]) {
                $tokens = preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY);
                if (!in_array('mhd-pill', $tokens, true)) {
                    continue;
                }
                // A variant may be interpolated ({{statevariant}}) or literal.
                $hasvariant = str_contains($classes, 'mhd-pill--') &&
                    (bool) preg_match('/mhd-pill--(?!interactive|leading-icon|trailing-icon)[a-z{]/', $classes);
                if (!$hasvariant) {
                    $offenders[] = "{$name}: <{$tag} class=\"{$classes}\">";
                }
            }
        }

        $this->assertSame([], $offenders, 'Pills without a colour variant: ' . implode(' | ', $offenders));
    }

    /**
     * The state layer promises that something happens when you activate the
     * chip. On a <span> that promise is a lie, and a keyboard user cannot
     * reach it to find out.
     */
    public function test_the_interactive_modifier_only_lands_on_interactive_elements(): void {
        $offenders = [];

        foreach ($this->templates() as $name => $path) {
            foreach ($this->elements_with_classes(file_get_contents($path)) as [$tag, $classes]) {
                $tokens = preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY);
                if (in_array('mhd-pill--interactive', $tokens, true) && !in_array($tag, ['a', 'button'], true)) {
                    $offenders[] = "{$name}: <{$tag} class=\"{$classes}\">";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Only links and buttons may carry the M3 state layer: ' . implode(' | ', $offenders)
        );
    }

    /**
     * One file decides what a pill looks like. A second file with an opinion is
     * how a screen quietly acquires its own pill size.
     */
    public function test_only_the_component_styles_the_pill(): void {
        $dir       = self::PLUGIN_DIR . '/scss';
        $component = $dir . '/components/_pill.scss';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'scss') {
                continue;
            }
            if ($file->getPathname() === $component) {
                continue;
            }
            // Comments may name the component; selectors may not declare it.
            // A `:not()` exclusion is neither: a file is always allowed to say
            // "not this component" — that is how base/_app-reset.scss keeps its
            // link colour off buttons and chips that happen to be anchors.
            foreach (file($file->getPathname()) as $number => $line) {
                $code = preg_replace('|//.*$|', '', $line);
                $code = preg_replace('/:not\([^)]*\)/', '', (string) $code);
                if (str_contains((string) $code, '.mhd-pill')) {
                    $offenders[] = str_replace($dir . '/', '', $file->getPathname()) . ':' . ($number + 1);
                }
            }
        }

        $this->assertFileExists($component);
        $this->assertSame([], $offenders, 'Pill styling outside the component: ' . implode(' | ', $offenders));
    }
}
