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

use local_moodesk\output\header_action;
use local_moodesk\output\page_header;

/**
 * The Page Header's contract, enforced against the stylesheets and templates.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * One page header, built once and reused.
 *
 * The plugin reached twenty-eight screens with eight different header idioms
 * before this component existed. Four title sizes were in play and one of them
 * was whatever the site's Moodle theme said an `<h2>` was. Two screens put the
 * filled button to the LEFT of the outlined one, and one of those inverted a
 * second time when it stacked on a phone. Eight screens had no title at all.
 *
 * None of that was carelessness. It was that every screen spelled the band out
 * by hand and nothing stopped it drifting. The parts of the contract a grep can
 * check are checked here.
 *
 * Six edit forms — department_edit, team_edit, category_edit, custom_field_edit,
 * automation_edit and webhook_edit — used to sit outside the migration by name,
 * pending a redesign of their own. That redesign is
 * `local_moodesk\output\form_page`, and they are all inside now: the exclusion
 * list is gone rather than empty, and so is the complement test that guarded it.
 * Every screen the shell renders has a page header, with no names attached.
 *
 * @coversNothing
 */
final class page_header_markup_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/..';

    /** @var string The component. The only file allowed to declare these names. */
    private const COMPONENT = '/scss/layout/_page-header.scss';

    /**
     * Screens whose header is the ENTITY's, not the page's.
     *
     * view.php renders `.mhd-detail__header` — the ticket's status and priority
     * pills, its subject, and `#id, requester, created`. That answers "what is
     * this ticket": it is the identity of the thing on screen rather than a
     * label for the screen, and the same markup serves the detail drawer over
     * the ticket list. A second, generic title above it would say less and take
     * more room.
     *
     * @var string[]
     */
    private const ENTITY_HEADED = [
        'view.php',
    ];

    /**
     * Entry points that render outside the application shell and therefore do
     * not carry a shell page header.
     *
     * @var string[]
     */
    private const NOT_IN_SHELL = [
        'ajax.php',
        'bulk_action.php',
        // Public, token-authenticated survey rendered through $OUTPUT->header()
        // directly. A centred card on a bare page, not a product screen.
        'lib.php',
        'settings.php',
        'version.php',
    ];

    /**
     * One file declares the page header's geometry.
     *
     * A second file with an opinion is how the product ended up with
     * `.mhd-dash-header`, `.mhd-page-header` and `.moodesk-form-page__head` —
     * three near-copies of the same three rules, at three different title sizes,
     * in three files none of which was the one named after the thing.
     */
    public function test_only_the_component_styles_the_page_header(): void {
        $dir       = self::PLUGIN_DIR . '/scss';
        $allowed   = realpath(self::PLUGIN_DIR . self::COMPONENT);
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'scss') {
                continue;
            }
            if (realpath($file->getPathname()) === $allowed) {
                continue;
            }
            // Comments may name the component; selectors may not declare it.
            foreach (file($file->getPathname()) as $number => $line) {
                $code = preg_replace('|//.*$|', '', $line);
                if (str_contains((string) $code, '.mhd-page-header')) {
                    $offenders[] = str_replace($dir . '/', '', $file->getPathname()) . ':' . ($number + 1);
                }
            }
        }

        $this->assertFileExists(self::PLUGIN_DIR . self::COMPONENT);
        $this->assertSame(
            [],
            $offenders,
            'Page-header styling outside the component: ' . implode(' | ', $offenders)
        );
    }

    /**
     * The superseded idioms are gone, not merely unused.
     *
     * A dead rule that still ships is a rule the next screen can find and copy.
     */
    public function test_the_superseded_header_idioms_are_deleted(): void {
        $dir  = self::PLUGIN_DIR . '/scss';
        $dead = [
            '.mhd-list-header', '.mhd-list-title',
            '.mhd-dash-header', '.mhd-dash-title', '.mhd-dash-actions',
            '.mhd-page-title', '.mhd-print-range',
            '.moodesk-form-page__head', '.moodesk-form-page__title', '.moodesk-form-page__subtitle',
        ];
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'scss') {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                $code = (string) preg_replace('|//.*$|', '', $line);
                foreach ($dead as $selector) {
                    if (str_contains($code, $selector)) {
                        $offenders[] = str_replace($dir . '/', '', $file->getPathname())
                            . ':' . ($number + 1) . ' ' . $selector;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Superseded header rules still ship: ' . implode(' | ', $offenders));
    }

    /**
     * No template spells a page header in Bootstrap.
     *
     * `d-flex justify-content-between` beside a heading was the idiom on eleven
     * screens, in two spacing variants and two alignments.
     */
    public function test_no_template_spells_a_page_header_by_hand(): void {
        $dir       = self::PLUGIN_DIR . '/templates';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'mustache') {
                continue;
            }
            $lines = file($file->getPathname());
            foreach ($lines as $number => $line) {
                if (!str_contains($line, 'justify-content-between')) {
                    continue;
                }
                // A heading within the next few lines makes this a page header
                // rather than a row of controls.
                $window = implode('', array_slice($lines, $number, 4));
                if (preg_match('/<h[1-3][\s>]/', $window)) {
                    $offenders[] = str_replace($dir . '/', '', $file->getPathname()) . ':' . ($number + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Hand-spelled page header in a template: ' . implode(' | ', $offenders)
        );
    }

    /**
     * The primary action is rendered after the secondary ones.
     *
     * This is the whole enforcement of "the primary action is the right-most
     * action": not a class anyone has to remember, but which section of the
     * partial the action was put into. If the two sections are ever reordered
     * or merged, every screen inverts at once and nothing else would notice.
     */
    public function test_the_partial_renders_the_primary_action_last(): void {
        $partial = file_get_contents(self::PLUGIN_DIR . '/templates/page_header.mustache');

        $secondary = strpos($partial, '{{#actions}}');
        $primary   = strpos($partial, '{{#primaryaction}}');

        $this->assertNotFalse($secondary, 'The partial must render a secondary action section.');
        $this->assertNotFalse($primary, 'The partial must render a primary action section.');
        $this->assertLessThan(
            $primary,
            $secondary,
            'The primary action section must come after the secondary one, or every header inverts.'
        );
    }

    /**
     * A caller cannot choose a button variant.
     *
     * The slot decides: with_action() is outlined, with_primary_action() is
     * filled. If header_action ever grows a variant, "outlined primary" becomes
     * expressible again and the invariant is back to being a convention.
     */
    public function test_an_action_cannot_carry_its_own_variant(): void {
        $source = file_get_contents(self::PLUGIN_DIR . '/classes/output/header_action.php');
        $body   = (string) preg_replace('|//.*$|m', '', $source);

        $this->assertStringNotContainsString(
            'mhd-btn--',
            $body,
            'header_action must not name a button variant; the slot decides it.'
        );
    }

    /**
     * Every screen in the shell renders a page header.
     *
     * The only names left are screens that do not render in the shell at all,
     * and the one screen whose header is the ticket's identity rather than the
     * page's. There is no longer a list of screens that simply have not got one.
     */
    public function test_every_shell_entry_point_renders_a_header(): void {
        $offenders = [];

        foreach (glob(self::PLUGIN_DIR . '/*.php') as $path) {
            $name = basename($path);
            $skip = in_array($name, self::NOT_IN_SHELL, true)
                || in_array($name, self::ENTITY_HEADED, true);
            if ($skip) {
                continue;
            }

            $source = file_get_contents($path);
            if (!str_contains($source, 'render_within_shell')) {
                continue;
            }

            // Either the screen builds a header itself, or an output class
            // builds it for the screen (kb.php, kb_article.php) — in which case
            // the screen still puts it in the context under `pageheader`.
            $buildsheader = str_contains($source, 'new page_header(')
                || str_contains($source, "['pageheader'] = ");
            if (!$buildsheader) {
                $offenders[] = $name;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Shell screens with no page header: ' . implode(' | ', $offenders)
        );
    }

    /**
     * A header must have a title. The type refuses anything else.
     */
    public function test_a_header_cannot_be_built_without_a_title(): void {
        $this->expectException(\coding_exception::class);
        new page_header('   ');
    }

    /**
     * The slot supplies the variant, and the primary one is filled.
     */
    public function test_the_slot_supplies_the_variant(): void {
        $header = (new page_header('Agents'))
            ->with_action(new header_action('Manage in Moodle', new \moodle_url('/admin/roles/manage.php')))
            ->with_primary_action(header_action::create_new('Add agent', new \moodle_url('/local/moodesk/add_agent.php')));

        $context = $header->to_context();

        $this->assertSame('outlined', $context['actions'][0]['variant']);
        $this->assertSame('filled', $context['primaryaction']['variant']);
        $this->assertTrue($context['hasactions']);
    }

    /**
     * A "New <entity>" action is the plus glyph alone, and keeps its label as
     * its accessible name.
     */
    public function test_a_create_action_is_icon_only_and_keeps_its_label(): void {
        $action = header_action::create_new('New department', new \moodle_url('/local/moodesk/department_edit.php'));
        $export = $action->export('filled');

        $this->assertTrue($export['icononly'], 'A create action renders icon-only.');
        $this->assertSame(header_action::ICON_PLUS, $export['icon']);
        $this->assertSame('New department', $export['label'], 'The label survives as the accessible name.');
    }

    /**
     * Absent parts render nothing rather than an empty placeholder.
     */
    public function test_optional_parts_are_absent_not_empty(): void {
        $context = (new page_header('System health'))->to_context();

        $this->assertNull($context['description']);
        $this->assertNull($context['primaryaction']);
        $this->assertNull($context['meta']);
        $this->assertSame([], $context['actions']);
        $this->assertFalse($context['hasactions']);
    }

    /**
     * Calling with_primary_action() twice replaces rather than appends.
     *
     * Two filled buttons in one header is the ambiguity the component exists to
     * remove.
     */
    public function test_a_header_has_at_most_one_primary_action(): void {
        $context = (new page_header('Tickets'))
            ->with_primary_action(new header_action('First', new \moodle_url('/a')))
            ->with_primary_action(new header_action('Second', new \moodle_url('/b')))
            ->to_context();

        $this->assertSame('Second', $context['primaryaction']['label']);
    }

    /**
     * A screen can build its context with the $OUTPUT it actually has.
     *
     * This is the bug Behat caught after PHPUnit had been talked out of it. At
     * the point a product screen builds its template context, $OUTPUT is still
     * core\output\bootstrap_renderer — the lazy stand-in Moodle keeps there
     * until the theme is set up — so export_for_template()'s renderer_base
     * parameter is a TypeError on every migrated screen, in a browser, on the
     * first request. to_context() takes no renderer because it needs none.
     */
    public function test_a_screen_can_build_its_context_without_a_renderer(): void {
        $context = (new page_header('Tickets'))->to_context();

        $this->assertSame('Tickets', $context['title']);
    }

    /**
     * No entry point calls export_for_template() with the global $OUTPUT.
     *
     * The assertion above proves to_context() works; this one proves the screens
     * use it. Together they close the hole rather than document it.
     */
    public function test_no_entry_point_exports_through_the_global_renderer(): void {
        $offenders = [];

        foreach (glob(self::PLUGIN_DIR . '/*.php') as $path) {
            $source = file_get_contents($path);
            if (str_contains($source, 'export_for_template($OUTPUT)')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Entry points must call to_context(): ' . implode(' | ', $offenders)
        );
    }

    /**
     * Progressive enhancement survives the move into the component.
     *
     * The ticket list's and the dashboard's "New ticket" are plain links to
     * submit.php that ticket_drawer.js upgrades by `data-action`. If that
     * attribute stops reaching the markup, the drawer silently stops opening and
     * every click navigates away instead.
     */
    public function test_extra_attributes_reach_the_markup(): void {
        $export = header_action::create_new(
            'New ticket',
            new \moodle_url('/local/moodesk/submit.php'),
            ['data-action' => 'mhd-open-new-ticket'],
        )->export('filled');

        $this->assertTrue($export['hasattributes']);
        $this->assertSame(
            [['name' => 'data-action', 'value' => 'mhd-open-new-ticket']],
            $export['attributes']
        );
    }
    /**
     * The title and the actions share one line, at every width.
     *
     * This is the shape of the band — "Title, then whatever room is left, then
     * the actions" — and it is a promise a screen makes to the person using it:
     * the primary action of the page is where the page says what it is. The
     * component has broken that promise twice already, in opposite directions.
     * `.mhd-list-header` truncated the title with an ellipsis below 576px; the
     * first version of this file let the ACTIONS drop to a second, full-width
     * row, which on Agents at 402px left "Manage roles" and the plus button
     * stranded under a title that had the whole band to itself.
     *
     * Neither is expressible while these three declarations stand together, and
     * each is load-bearing:
     *
     *   - the row does not wrap, so the actions cannot leave the line;
     *   - the actions may shrink, so a row too narrow for them folds the buttons
     *     inside their own column instead of pushing them out of the band;
     *   - the title may shrink below its own text, so it yields last rather than
     *     shoving the actions off the right edge.
     *
     * A regression here is not a visual nit: it is the band changing shape on
     * one class of screen and nothing else noticing.
     */
    public function test_the_title_and_the_actions_never_leave_the_same_line(): void {
        $scss = file_get_contents(self::PLUGIN_DIR . self::COMPONENT);
        $rule = static function (string $selector) use ($scss): string {
            $start = strpos($scss, $selector . ' {');
            if ($start === false) {
                return '';
            }
            $end = strpos($scss, "\n}", $start);
            $body = substr($scss, $start, $end === false ? null : $end - $start);

            // Comments describe the rule; only declarations bind it.
            return (string) preg_replace('|//.*$|m', '', $body);
        };

        $top     = $rule('.mhd-page-header__top');
        $title   = $rule('.mhd-page-header__title');
        $actions = $rule('.mhd-page-header__actions');

        $this->assertNotSame('', $top, 'The component must declare the header row.');

        $this->assertMatchesRegularExpression(
            '/flex-wrap:\s*nowrap\s*;/',
            $top,
            'The header row must not wrap, or the actions drop below the title.'
        );

        $this->assertMatchesRegularExpression(
            '/flex:\s*0\s+[1-9]\d*\s/',
            $actions,
            'The actions must be allowed to shrink, or a narrow header pushes them out of the band.'
        );

        $this->assertMatchesRegularExpression(
            '/min-width:/',
            $title,
            'The title must be allowed below its content width, or it shoves the actions off the edge.'
        );
    }

    /* ── KB-02: an action that submits a form on the page ────────────────────── */

    /**
     * A submitting action carries the id of the form it submits and no URL;
     * the slot still decides its variant.
     */
    public function test_a_submit_action_points_at_its_form(): void {
        $export = header_action::submit('Publish', 'mhd-kb-action-publish')->export('filled');

        $this->assertFalse($export['hasurl']);
        $this->assertNull($export['url']);
        $this->assertTrue($export['hasform']);
        $this->assertSame('mhd-kb-action-publish', $export['form']);
        $this->assertSame('filled', $export['variant'], 'The slot still supplies the variant.');
    }

    /**
     * An action navigates or submits — never both.
     */
    public function test_an_action_cannot_both_navigate_and_submit(): void {
        $this->expectException(\coding_exception::class);
        new header_action('Odd', new \moodle_url('/local/moodesk/index.php'), null, [], false, 'some-form');
    }

    /**
     * Destructive moves the slot's variant to its danger colour, and nothing
     * else: an outlined slot stays outlined, a filled one stays filled.
     */
    public function test_destructive_keeps_the_slot_but_changes_the_hue(): void {
        $delete = header_action::submit('Delete', 'mhd-kb-action-delete', 'trash', true);

        $this->assertSame('outlined-danger', $delete->export('outlined')['variant']);
        $this->assertSame('danger', $delete->export('filled')['variant']);
        $this->assertSame('outlined', header_action::submit('Archive', 'f')->export('outlined')['variant']);
    }

    /**
     * The partial renders a submitting action as a submit button bound to the
     * form by id, and a URL-less, form-less one as a plain button.
     */
    public function test_the_partial_renders_a_submit_button_for_a_form_action(): void {
        global $OUTPUT;

        $submit = $OUTPUT->render_from_template(
            'local_moodesk/page_header_action',
            header_action::submit('Publish', 'mhd-kb-action-publish')->export('filled')
        );
        $this->assertMatchesRegularExpression('~<button\s+type="submit"\s+form="mhd-kb-action-publish"~', $submit);
        $this->assertStringNotContainsString('<a ', $submit);

        $plain = $OUTPUT->render_from_template(
            'local_moodesk/page_header_action',
            (new header_action('Refresh'))->export('outlined')
        );
        $this->assertMatchesRegularExpression('~<button\s+type="button"~', $plain);
        $this->assertStringNotContainsString('form=', $plain);
    }

    /* ── KB-03: the title carries a fixed id, once per page ──────────────────── */

    /**
     * The h1 carries `id="mhd-page-title"`, and it is unique on every screen:
     * the component declares it exactly once, no template includes the header
     * partial more than once, and no template spells the id by hand.
     */
    public function test_the_title_id_is_declared_once_and_never_duplicated(): void {
        $dir = self::PLUGIN_DIR . '/templates';
        $component = file_get_contents($dir . '/page_header.mustache');
        $this->assertSame(1, substr_count($component, 'id="mhd-page-title"'), 'The component declares the id once.');
        $this->assertMatchesRegularExpression('~<h1 class="mhd-page-header__title" id="mhd-page-title">~', $component);

        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'mustache') {
                continue;
            }
            $name   = str_replace($dir . '/', '', $file->getPathname());
            $source = file_get_contents($file->getPathname());
            $includes = preg_match_all('~\{\{>\s*local_moodesk/page_header\s*\}\}~', $source);
            if ($includes > 1) {
                $offenders[] = "$name includes the header $includes times";
            }
            // Pointing at the id (aria-labelledby) is what it is for; declaring it is not.
            if ($name !== 'page_header.mustache' && str_contains($source, 'id="mhd-page-title"')) {
                $offenders[] = "$name spells the title id by hand";
            }
        }
        $this->assertSame([], $offenders, implode(' | ', $offenders));
    }
}
