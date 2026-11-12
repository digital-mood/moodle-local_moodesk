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
 * Unit tests for navigation_service.
 *
 * The service is the single source of truth for mooDesk navigation, extracted
 * from the primary-navigation hook in phase F0. These tests lock in the
 * behaviour the hook used to provide inline (capability + edition gating,
 * order, structure) plus the additive per-item icon token the application
 * shell needs.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Locks in the navigation behaviour extracted from the primary-navigation hook.
 *
 * @covers \local_moodesk\service\navigation_service
 * @covers \local_moodesk\service\nav_item
 */
final class navigation_service_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // The CI legs' moodesk_LOCAL_EDITION constant is masked so the licence
        // decides the edition, as on a real install; set_edition_for_testing()
        // still wins where a case needs a given edition.
        edition_service::set_dev_override_for_testing(false);
    }

    protected function tearDown(): void {
        edition_service::set_dev_override_for_testing(null);
        parent::tearDown();
    }

    /**
     * Force a clean Starter resolution (no JWT, no legacy edition row).
     */
    private function force_starter(): void {
        unset_config('edition', 'local_moodesk');
        \cache_helper::purge_all();
        $this->assertSame(
            edition_service::EDITION_STARTER,
            edition_service::get_edition(),
            'Test invariant: edition must resolve to starter.'
        );
    }

    /**
     * Collect the keys of a nav_item list, in order.
     *
     * @param nav_item[] $items
     * @return string[]
     */
    private function keys(array $items): array {
        return array_map(static fn(nav_item $i): string => $i->key, $items);
    }

    public function test_unavailable_for_guest(): void {
        $this->setGuestUser();
        $this->assertFalse(navigation_service::is_available());
        $this->assertSame([], navigation_service::get_shell_items());
    }

    public function test_available_for_authenticated_user(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertTrue(
            navigation_service::is_available(),
            ':viewown defaults to the user archetype, so any logged-in user qualifies.'
        );
    }

    public function test_entry_point_contract(): void {
        $entry = navigation_service::get_entry_point();
        $this->assertSame('helpdesk', $entry->key);
        $this->assertNotSame('', $entry->icon);
        $this->assertStringEndsWith('/local/moodesk/index.php', $entry->url->out_omit_querystring());
    }

    /**
     * A plain authenticated user (user archetype) sees the operation items —
     * Tickets, the Dashboard with its nested "New ticket", and Knowledge — and
     * no administration items.
     */
    public function test_plain_user_sees_only_operation_items(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $items = navigation_service::get_catalogue();
        $this->assertSame(['tickets', 'dashboard', 'knowledge'], $this->keys($items));
        // Dashboard carries the nested "New ticket".
        $dashboard = $items[1];
        $this->assertSame('dashboard', $dashboard->key);
        $this->assertSame(['newticket'], $this->keys($dashboard->children));
    }

    /**
     * On a Starter site, an admin sees the base items but NOT the Pro/Enterprise
     * gated screens (Reports, Automations, Teams, Custom Fields, Webhooks, API).
     *
     * System Health is present on Starter by design: failure detection is
     * production-readiness infrastructure rather than a licensed feature, and a
     * tier without webhooks or ingestion simply never produces incidents from
     * those sources.
     */
    public function test_admin_starter_hides_paid_items_and_keeps_order(): void {
        $this->setAdminUser();
        $this->force_starter();
        // The base's own catalogue: on the legs with the add-on, its item stays out.
        $this->redirectHook(\local_moodesk\hook\navigation_items::class, static function (): void {
        });

        $keys = $this->keys(navigation_service::get_catalogue());
        $this->stopHookRedirections();

        $this->assertSame(
            ['tickets', 'dashboard', 'knowledge', 'departments', 'agents', 'categories', 'kb', 'health', 'settings'],
            $keys,
            'Starter admin catalogue order/contents must hide every paid screen.'
        );
        foreach (['reports', 'automations', 'teams', 'customfields', 'webhooks', 'apitokens'] as $paid) {
            $this->assertNotContains($paid, $keys, "Starter must hide '{$paid}'.");
        }
    }

    /**
     * Every returned item (and child) must carry a non-empty icon token — the
     * collapsed icon-only rail of the shell sidebar depends on it.
     */
    public function test_every_item_has_an_icon_token(): void {
        $this->setAdminUser();

        $asserticons = function (array $items) use (&$asserticons): void {
            foreach ($items as $item) {
                $this->assertNotSame('', $item->icon, "Item '{$item->key}' must have an icon token.");
                $asserticons($item->children);
            }
        };
        $asserticons(navigation_service::get_catalogue());
    }

    /* ── F6: navigation model (two projections of one catalogue) ─────────────── */

    /**
     * The shell catalogue exposes the complete internal navigation, including
     * the screens that must never clutter Moodle's header (Tickets).
     */
    public function test_catalogue_exposes_full_internal_nav(): void {
        $this->setAdminUser();

        $cataloguekeys = $this->keys(navigation_service::get_catalogue());

        $this->assertContains('tickets', $cataloguekeys, 'Catalogue must expose Tickets.');
    }

    /**
     * get_shell_items() is the shell projection of the catalogue.
     */
    public function test_shell_items_is_the_catalogue(): void {
        $this->setAdminUser();
        $this->assertEquals(
            navigation_service::get_catalogue(),
            navigation_service::get_shell_items()
        );
    }

    /**
     * Every catalogue node (except the few ungrouped ones) declares a known
     * section group, so the shell can render section headers.
     */
    public function test_catalogue_nodes_declare_a_group(): void {
        $this->setAdminUser();

        $valid = [
            nav_item::GROUP_OPERATION,
            nav_item::GROUP_ADMINISTRATION,
            nav_item::GROUP_SYSTEM,
        ];
        foreach (navigation_service::get_catalogue() as $item) {
            $this->assertContains(
                $item->group,
                $valid,
                "Catalogue node '{$item->key}' must declare a valid group."
            );
        }
    }

    /**
     * The Moodle app-mode crossing point is a single node (no children).
     */
    public function test_primary_nav_node_is_single_entry(): void {
        $node = navigation_service::get_primary_nav_node();
        $this->assertSame('helpdesk', $node->key);
        $this->assertSame([], $node->children, 'App-mode primary nav must be a single crossing point.');
    }

    /**
     * The page-key → node map (nav model §4) folds detail/edit/member sub-pages
     * onto their top-level node; unknown keys pass through unchanged.
     */
    public function test_resolve_active_key_maps_subpages(): void {
        $this->assertSame('tickets', navigation_service::resolve_active_key('view'));
        $this->assertSame('categories', navigation_service::resolve_active_key('category_edit'));
        $this->assertSame('departments', navigation_service::resolve_active_key('department_members'));
        // Pass-through for a top-level key.
        $this->assertSame('dashboard', navigation_service::resolve_active_key('dashboard'));
    }

    /**
     * The catalogue, like the legacy projection, is empty for guests.
     */
    public function test_catalogue_unavailable_for_guest(): void {
        $this->setGuestUser();
        $this->assertSame([], navigation_service::get_catalogue());
        $this->assertSame([], navigation_service::get_shell_items());
    }

    /* ── KB-02: the Articles entry ───────────────────────────────────────────── */

    /**
     * Articles sits right after Categories under Administration: the articles
     * are filed under that taxonomy (roadmap decision, 2026-09-12).
     */
    public function test_articles_follows_categories(): void {
        $this->setAdminUser();

        $keys = $this->keys(navigation_service::get_catalogue());

        $this->assertContains('kb', $keys);
        $this->assertSame(
            array_search('categories', $keys, true) + 1,
            array_search('kb', $keys, true),
            'Articles must be the entry immediately after Categories.'
        );

        $kb = array_values(array_filter(
            navigation_service::get_catalogue(),
            static fn(nav_item $i): bool => $i->key === 'kb'
        ))[0];
        $this->assertSame(nav_item::GROUP_ADMINISTRATION, $kb->group);
        $this->assertStringEndsWith('/local/moodesk/kb_articles.php', $kb->url->out(false));
    }

    /**
     * `kb_enabled` is a presentation switch: off, the entry is gone and the
     * rest of the catalogue is untouched.
     */
    public function test_articles_hidden_when_kb_disabled(): void {
        $this->setAdminUser();

        set_config('kb_enabled', 0, 'local_moodesk');
        $off = $this->keys(navigation_service::get_catalogue());
        $this->assertNotContains('kb', $off);
        $this->assertNotContains('knowledge', $off);

        set_config('kb_enabled', 1, 'local_moodesk');
        $on = $this->keys(navigation_service::get_catalogue());
        $this->assertContains('kb', $on);
        $this->assertContains('knowledge', $on);

        $this->assertSame(
            array_values(array_diff($on, ['kb', 'knowledge'])),
            $off,
            'Only the two knowledge base entries may differ.'
        );
    }

    /**
     * The entry needs :editkb or :managekb — a plain requester with :viewkb
     * alone reads articles (KB-03) but does not get the authoring screens.
     */
    public function test_articles_needs_an_authoring_capability(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertNotContains('kb', $this->keys(navigation_service::get_catalogue()));

        $roleid = create_role('KB editor', 'kbeditor', '');
        assign_capability('local/moodesk:editkb', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertContains('kb', $this->keys(navigation_service::get_catalogue()));
    }

    /**
     * The article and edit screens light up the Articles node.
     */
    public function test_kb_subpages_resolve_by_verb(): void {
        // Reading lights Knowledge; writing lights Articles.
        $this->assertSame('knowledge', navigation_service::resolve_active_key('kb_article'));
        $this->assertSame('kb', navigation_service::resolve_active_key('kb_article_edit'));
        $this->assertSame('kb', navigation_service::resolve_active_key('kb'));
        $this->assertSame('knowledge', navigation_service::resolve_active_key('knowledge'));
    }

    /**
     * Knowledge sits in Operation right after Dashboard, for anyone with
     * :viewkb — a plain user included — and goes with kb_enabled.
     */
    public function test_knowledge_is_operation_for_every_reader(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $items = navigation_service::get_catalogue();
        $keys  = $this->keys($items);
        $this->assertSame(['tickets', 'dashboard', 'knowledge'], $keys);
        $knowledge = $items[2];
        $this->assertSame(nav_item::GROUP_OPERATION, $knowledge->group);
        $this->assertStringEndsWith('/local/moodesk/kb.php', $knowledge->url->out(false));

        set_config('kb_enabled', 0, 'local_moodesk');
        $this->assertNotContains('knowledge', $this->keys(navigation_service::get_catalogue()));
    }
}
