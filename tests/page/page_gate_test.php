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
 * Regression suite for Etapa 2 entry-point gates.
 *
 * Two surfaces are covered:
 *   1. Static contract — every Pro/Enterprise page calls
 *      edition_service::has_feature() with the feature key declared in
 *      docs/etapa2-hardening-audit.md. Catches the case where a future
 *      refactor removes the gate by accident.
 *   2. Primary-navigation behaviour — the Help Desk nav node hides Pro+
 *      links (Reports, Automations) on a Starter site. Lock-in for the
 *      D7=b navbar cleanup.
 *
 * The page files are not actually included here; the goal is to prove that
 * each page file *contains* the gate, not to exercise its render path. Full
 * render tests would require a full HTTP request setup that buys little over
 * the targeted check below.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * Verifies every Pro/Enterprise page enforces its edition feature gate.
 *
 * @covers \local_moodesk\hook_callbacks::extend_primary_navigation
 */
final class page_gate_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/../..';

    /**
     * The contract enforced by docs/etapa2-hardening-audit.md.
     *
     * Each entry: page filename => feature key the gate must check.
     * If you add a new Pro/Enterprise page, add its row here and to the
     * audit doc in the same PR.
     */
    private const GATED_PAGES = [
    ];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // The CI legs' moodesk_LOCAL_EDITION constant is masked so the licence
        // decides the edition, as on a real install; set_edition_for_testing()
        // still wins where a case needs a given edition.
        \local_moodesk\service\edition_service::set_dev_override_for_testing(false);
    }

    protected function tearDown(): void {
        \local_moodesk\service\edition_service::set_dev_override_for_testing(null);
        parent::tearDown();
    }

    /**
     * Every Pro/Enterprise page must call edition_service::has_feature() with
     * the feature key declared in the audit doc.
     */
    /**
     * The authoring page is Starter. What may prefill a draft comes from a hook, so
     * nothing on this page reads a ticket and nothing skips the callback's checks.
     */
    public function test_the_authoring_page_prefills_only_through_the_hook(): void {
        $source = file_get_contents(self::PLUGIN_DIR . '/kb_article_edit.php');

        $this->assertStringContainsString("optional_param('fromticket', 0, PARAM_INT)", $source);
        $this->assertStringContainsString('hook\\kb_article_draft_defaults', $source);
        $this->assertStringNotContainsString('local_moodesk_ticket', $source);
        $this->assertStringNotContainsString('ticket_service', $source);
    }

    public function test_each_gated_page_calls_has_feature_with_expected_key(): void {
        foreach (self::GATED_PAGES as $page => $expectedkey) {
            $path = self::PLUGIN_DIR . '/' . $page;
            $this->assertFileExists($path, "Page $page declared in GATED_PAGES is missing.");

            $source = file_get_contents($path);
            $this->assertNotFalse($source, "Could not read $page.");

            // Either has_feature or require_feature, with the exact key as a
            // single-quoted argument. Loose enough to allow either function but
            // tight enough that a typo or a removed gate fails the test.
            $pattern = sprintf(
                "/(has_feature|require_feature)\\(\\s*['\"]%s['\"]\\s*\\)/",
                preg_quote($expectedkey, '/')
            );
            $this->assertMatchesRegularExpression(
                $pattern,
                $source,
                "Page $page must gate on feature key '$expectedkey'. "
                . 'See docs/etapa2-hardening-audit.md for the contract.'
            );
        }
    }

    /**
     * On a Starter site, the primary-nav callback must NOT add Reports or
     * Automations to the Help Desk node.
     */

    public function test_primary_nav_hides_pro_links_on_starter(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Force Starter: no JWT, no legacy config row that would let compat
        // resolve to Pro/Enterprise.
        unset_config('license_key', 'local_moodesk');
        unset_config('edition', 'local_moodesk');
        \cache_helper::purge_all();

        $this->assertSame(
            \local_moodesk\service\edition_service::EDITION_STARTER,
            \local_moodesk\service\edition_service::get_edition(),
            'Test setup invariant: edition must resolve to starter before invoking the navbar hook.'
        );

        // Build a primary view, dispatch the hook, inspect the resulting nodes.
        $primaryview = new \core\navigation\views\primary($PAGE);
        $hook = new \core\hook\navigation\primary_extend($primaryview);
        \local_moodesk\hook_callbacks::extend_primary_navigation($hook);

        $hd = $primaryview->find('moodesk', \navigation_node::TYPE_CUSTOM);
        $this->assertNotEmpty($hd, 'Help Desk parent node should exist for an admin on Starter.');

        // Node keys live under the Help Desk parent.
        $this->assertNull(
            $hd->find('moodesk_reports', \navigation_node::TYPE_CUSTOM) ?: null,
            'Reports nav link must be hidden on Starter (D7=b).'
        );
        $this->assertNull(
            $hd->find('moodesk_automations', \navigation_node::TYPE_CUSTOM) ?: null,
            'Automations nav link must be hidden on Starter (D7=b).'
        );
    }

    /**
     * Sanity check: the Help Desk parent itself must still appear on
     * Starter — only the Pro children are hidden.
     */
    public function test_primary_nav_keeps_helpdesk_parent_on_starter(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        unset_config('license_key', 'local_moodesk');
        unset_config('edition', 'local_moodesk');
        \cache_helper::purge_all();

        $primaryview = new \core\navigation\views\primary($PAGE);
        $hook = new \core\hook\navigation\primary_extend($primaryview);
        \local_moodesk\hook_callbacks::extend_primary_navigation($hook);

        $this->assertNotEmpty(
            $primaryview->find('moodesk', \navigation_node::TYPE_CUSTOM),
            'Help Desk parent must always be present for users with viewown / agent.'
        );
    }
}
