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
 * The three bands of the application-shell sidebar, asserted on the markup.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

/**
 * Which band each element of the sidebar belongs to.
 *
 * The sidebar is a brand header, a scrolling navigation and a pinned footer.
 * Only the middle band scrolls, and that is a fact about the DOM before it is a
 * fact about CSS: an element scrolls away because of the parent it sits in.
 * "Return to Moodle" spent three phases as the last list inside <nav>, looking
 * pinned because nothing had yet been tall enough to scroll — the context tests
 * could not see it, because the context was right the whole time.
 *
 * The footer is also the one part of the sidebar that must render for everyone.
 * Settings is gated on local/moodesk:managesettings, so an agent without it
 * exports an empty `footeritems`; while the footer was wrapped in that array's
 * own section, those users lost the way back to Moodle along with it.
 *
 * @coversNothing
 */
final class sidebar_markup_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Render the sidebar partial and return an XPath reader over it.
     *
     * @param array $context Template context.
     * @return \DOMXPath
     */
    private function xpath(array $context): \DOMXPath {
        global $OUTPUT;

        $html = $OUTPUT->render_from_template('local_moodesk/shell/sidebar', $context);
        $this->assertNotSame('', trim($html), 'The sidebar partial rendered nothing.');

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The partial is a fragment, and <use href> is SVG the HTML parser
        // moans about; neither is a defect in the markup under test.
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($doc);
    }

    /**
     * The context the shell really exports, for an admin (Settings present).
     *
     * @return array
     */
    private function shell_context(): array {
        global $PAGE;

        $this->setAdminUser();

        return (new app_shell('', 'dashboard'))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * XPath predicate matching one whole class name.
     *
     * @param string $class Class to match.
     * @return string
     */
    private function has_class(string $class): string {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    /**
     * The band that scrolls holds the navigation and nothing else.
     */
    public function test_return_to_moodle_is_in_the_footer_and_not_in_the_nav(): void {
        $context = $this->shell_context();
        $xpath   = $this->xpath($context);
        $return  = $context['returnurl'];

        $innav = $xpath->query(
            "//nav[{$this->has_class('moodesk-app__nav')}]//a[@href='{$return}']"
        );
        $this->assertSame(
            0,
            $innav->length,
            '"Return to Moodle" must not sit inside the scrolling navigation band.'
        );

        $infooter = $xpath->query(
            "//div[{$this->has_class('moodesk-app__sidebar-footer')}]//a[@href='{$return}']"
        );
        $this->assertSame(
            1,
            $infooter->length,
            '"Return to Moodle" must be pinned in the sidebar footer.'
        );
    }

    /**
     * The three bands are siblings under the aside, in reading order.
     */
    public function test_the_sidebar_has_three_sibling_bands(): void {
        $xpath = $this->xpath($this->shell_context());

        $bands = $xpath->query(
            "//aside[{$this->has_class('moodesk-app__sidebar')}]/*[self::a or self::nav or self::div]"
        );
        $this->assertSame(3, $bands->length, 'The sidebar must be exactly three bands.');

        $classes = [];
        foreach ($bands as $band) {
            $classes[] = $band->getAttribute('class');
        }
        $this->assertSame(
            ['moodesk-app__brand', 'moodesk-app__nav', 'moodesk-app__sidebar-footer'],
            $classes,
            'The bands must read brand, navigation, footer — in that order.'
        );
    }

    /**
     * Settings follows "Return to Moodle", and shares its list.
     */
    public function test_the_footer_keeps_return_above_settings(): void {
        $context = $this->shell_context();
        $xpath   = $this->xpath($context);

        $this->assertNotEmpty(
            $context['footeritems'],
            'An admin must export Settings, or this test proves nothing.'
        );

        $links = $xpath->query(
            "//div[{$this->has_class('moodesk-app__sidebar-footer')}]//li/a/@href"
        );
        $hrefs = [];
        foreach ($links as $href) {
            $hrefs[] = $href->value;
        }

        $this->assertSame(
            [$context['returnurl'], $context['footeritems'][0]['url']],
            $hrefs,
            'The footer must list "Return to Moodle" first and Settings after it.'
        );
    }

    /**
     * Without Settings there is still a footer, and the way back is still in it.
     *
     * This is the regression the capability gate hides: every context test in
     * the suite runs as an admin, and an admin always has Settings.
     */
    public function test_the_footer_renders_for_a_user_without_settings(): void {
        $context = $this->shell_context();
        $context['footeritems'] = [];

        $xpath = $this->xpath($context);

        $footer = $xpath->query("//div[{$this->has_class('moodesk-app__sidebar-footer')}]");
        $this->assertSame(
            1,
            $footer->length,
            'The footer must render even when the user cannot reach Settings.'
        );

        $links = $xpath->query(
            "//div[{$this->has_class('moodesk-app__sidebar-footer')}]//a[@href='{$context['returnurl']}']"
        );
        $this->assertSame(1, $links->length, 'The way back to Moodle must survive an empty footer list.');
    }

    /**
     * The one property the DOM cannot show.
     *
     * A column flex item starts at min-height:auto and will not shrink below
     * its content, so without this the navigation pushes the footer past the
     * bottom of the viewport instead of scrolling — and every band still looks
     * correct in the markup while it happens.
     */
    public function test_the_navigation_band_may_shrink_and_owns_the_scroll(): void {
        $css = (string) file_get_contents(__DIR__ . '/../../styles.css');

        $this->assertMatchesRegularExpression(
            '/\.moodesk-app__nav\{[^}]*min-height:0[^}]*overflow-y:auto[^}]*\}/',
            $css,
            'The navigation band must declare min-height:0 and own the scroll. ' .
                'If scss/layout/_app-sidebar.scss is right, run: npm run scss'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.moodesk-app__sidebar\{[^}]*overflow-y:auto[^}]*\}/',
            $css,
            'The sidebar itself must not scroll, or the brand and footer scroll away with it.'
        );
    }

    /**
     * The drawer carries its own way out, and it is not inside the brand link.
     *
     * The topbar's toggle opens the drawer and is then covered by it, so the
     * pointer needs a control that travels with the panel. It is a sibling of
     * the brand rather than a child: the brand is an <a>, and a button inside a
     * link is not valid HTML — a thing the DOM will happily represent and no
     * rendering test would notice.
     */
    public function test_the_drawer_carries_its_own_close_control(): void {
        $context = $this->shell_context();
        $xpath   = $this->xpath($context);

        $buttons = $xpath->query(
            "//aside[{$this->has_class('moodesk-app__sidebar')}]" .
                "/button[@data-region='moodesk-drawer-close']"
        );
        $this->assertSame(
            1,
            $buttons->length,
            'The drawer must carry exactly one close control, as a direct child of the aside.'
        );

        $this->assertNotSame(
            '',
            (string) $buttons->item(0)->getAttribute('aria-label'),
            'The close control is icon-only, so its accessible name has to come from aria-label.'
        );

        $nested = $xpath->query(
            "//a[{$this->has_class('moodesk-app__brand')}]//button"
        );
        $this->assertSame(
            0,
            $nested->length,
            'A button inside the brand link is invalid HTML; the close control is a sibling of it.'
        );
    }

    /**
     * The close control is display:none until the drawer is both a drawer and open.
     *
     * display, not visibility and not an off-screen shift: it is what keeps the
     * control out of the accessibility tree, so a rail — and a closed drawer
     * parked off-canvas — offers one navigation control and not two. The markup
     * cannot show this; only the compiled rule can.
     */
    public function test_the_close_control_is_hidden_until_the_drawer_is_open(): void {
        $css = (string) file_get_contents(__DIR__ . '/../../styles.css');

        $rebuild = ' If scss/layout/_app-sidebar.scss is right, run: npm run scss';

        $this->assertMatchesRegularExpression(
            '/\.moodesk-app__drawer-close\{[^}]*display:none[^}]*\}/',
            $css,
            'The close control must rest at display:none, or it is in the tab order on every rail.' . $rebuild
        );

        $this->assertMatchesRegularExpression(
            '/\.moodesk-app--drawer-open \.moodesk-app__drawer-close\{[^}]*display:inline-flex[^}]*\}/',
            $css,
            'Only an open drawer may show the close control.' . $rebuild
        );

        // It lives inside the drawer, which already outranks the backdrop and the
        // topbar. A z-index here would mean the fix went in the wrong place.
        $this->assertDoesNotMatchRegularExpression(
            '/\.moodesk-app__drawer-close\{[^}]*z-index[^}]*\}/',
            $css,
            'The close control needs no z-index: it is inside the topmost layer of the shell.'
        );
    }

    /**
     * The shell measures itself against the viewport that is actually visible.
     *
     * On a phone 100vh is the viewport with the browser's bars hidden, which is
     * taller than the screen while they are showing: the sidebar footer ends up
     * underneath the address bar. 100dvh tracks the visible height instead.
     *
     * Order is the whole mechanism and it cannot be seen from either rule on
     * its own — the 100vh line has to come first, so a browser that does not
     * know the dynamic unit keeps it and one that does overrides it. Sass emits
     * both because the values differ; a "tidy-up" dropping either one silently
     * breaks a class of browser, so both are asserted here in sequence.
     */
    public function test_the_shell_and_the_sidebar_use_the_dynamic_viewport(): void {
        $css = (string) file_get_contents(__DIR__ . '/../../styles.css');

        $rebuild = ' If scss/layout/ is right, run: npm run scss';

        $this->assertMatchesRegularExpression(
            '/\.moodesk-app\{[^}]*min-height:100vh;min-height:100dvh[^}]*\}/',
            $css,
            'The shell must fall back to min-height:100vh before min-height:100dvh.' . $rebuild
        );

        // Both of the sidebar's own height declarations: the sticky rail above
        // the tablet step, and the off-canvas drawer below it.
        preg_match_all('/\.moodesk-app__sidebar\{[^}]*height:100vh;height:100dvh[^}]*\}/', $css, $matches);
        $this->assertCount(
            2,
            $matches[0],
            'Both the sticky rail and the mobile drawer must declare height:100vh before height:100dvh.' . $rebuild
        );
    }

    /**
     * The page behind the mobile drawer is held still by the shared lock.
     *
     * amd/src/app_shell.js puts the body into this class through
     * local_moodesk/scroll_lock while the off-canvas navigation is open. The
     * class is the overlay drawers' own, deliberately: one rule, one owner, and
     * the lock only comes off once nothing is covering the viewport.
     */
    public function test_the_body_scroll_lock_rule_exists(): void {
        $css = (string) file_get_contents(__DIR__ . '/../../styles.css');

        $this->assertMatchesRegularExpression(
            '/body\.mhd-drawer-locked\{[^}]*overflow:hidden[^}]*\}/',
            $css,
            'body.mhd-drawer-locked must still hide the document overflow: the mobile ' .
                'navigation drawer relies on it. If scss/components/_drawer-base.scss ' .
                'is right, run: npm run scss'
        );
    }
}
