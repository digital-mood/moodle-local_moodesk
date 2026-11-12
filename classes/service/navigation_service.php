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

namespace local_moodesk\service;

use local_moodesk\output\kb_ui;

/**
 * Single source of truth for mooDesk navigation.
 *
 * Builds the navigation tree once, applying the same capability and edition
 * gating for every consumer, and returns it as {@see nav_item} value objects.
 *
 * Two renderers consume this service:
 *   - {@see \local_moodesk\hook_callbacks} injects a single "mooDesk" entry
 *     point into Moodle's primary navigation and hangs these items beneath it
 *     (the only crossing point with Moodle's chrome);
 *   - the standalone application-shell sidebar (phase F2) renders the same
 *     items as its own independent navigation.
 *
 * Extracting this out of the hook (phase F0) is behaviour-preserving: the
 * Moodle primary-navigation output is unchanged. The added value is (a) a
 * reusable, testable nav definition and (b) the per-item icon token the shell
 * sidebar needs — including its collapsed icon-only rail.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class navigation_service {
    /**
     * Whether mooDesk navigation should be shown at all for the current user.
     *
     * Mirrors the base guard the primary-navigation hook has always applied:
     * never for guests or unauthenticated users, and only when the user holds
     * at least one base helpdesk capability (:viewown defaults to the 'user'
     * archetype, so every logged-in non-guest qualifies).
     *
     * @param \context|null $context System context by default.
     * @return bool
     */
    public static function is_available(?\context $context = null): bool {
        if (!isloggedin() || isguestuser()) {
            return false;
        }

        $context = $context ?? \context_system::instance();

        return has_capability('local/moodesk:viewown', $context)
            || has_capability('local/moodesk:agent', $context);
    }

    /**
     * The single entry-point node ("mooDesk").
     *
     * Used as the crossing point in Moodle's primary navigation and as the
     * shell's branding/home target. Links to the ticket list.
     *
     * @return nav_item
     */
    public static function get_entry_point(): nav_item {
        return new nav_item(
            'helpdesk',
            get_string('nav_helpdesk', 'local_moodesk'),
            new \moodle_url('/local/moodesk/index.php'),
            'tickets',
        );
    }

    /**
     * The full mooDesk navigation catalogue — the single source of truth for the
     * application shell.
     *
     * The catalogue is the *complete* internal navigation (Tickets, Dashboard,
     * the admin screens, Reports, License, Settings), with each node tagged by a
     * section {@see nav_item::$group} so the shell sidebar can render grouped
     * sections. Capability + edition gating is applied here once, so every
     * projection ({@see get_shell_items()}, {@see get_primary_nav_node()})
     * inherits it. Returns an empty array when the user has no access.
     *
     * @param \context|null $context System context by default.
     * @return nav_item[]
     */
    public static function get_catalogue(?\context $context = null): array {
        $context = $context ?? \context_system::instance();

        if (!self::is_available($context)) {
            return [];
        }

        $items = [];

        /* ── Operation ─────────────────────────────────────────────────────── */
        // Ticket list — closes the F3 gap where index/view highlighted no node.
        $items[] = new nav_item(
            'tickets',
            get_string('nav_tickets', 'local_moodesk'),
            new \moodle_url('/local/moodesk/index.php'),
            'tickets',
            [],
            false,
            nav_item::GROUP_OPERATION,
        );

        // Dashboard with the nested "New ticket" action.
        $dashchildren = [];
        if (has_capability('local/moodesk:submit', $context)) {
            $dashchildren[] = new nav_item(
                'newticket',
                get_string('nav_newticket', 'local_moodesk'),
                new \moodle_url('/local/moodesk/submit.php'),
                'newticket',
                [],
                false,
                nav_item::GROUP_OPERATION,
            );
        }
        $items[] = new nav_item(
            'dashboard',
            get_string('nav_dashboard', 'local_moodesk'),
            new \moodle_url('/local/moodesk/dashboard.php'),
            'dashboard',
            $dashchildren,
            false,
            nav_item::GROUP_OPERATION,
        );

        // Knowledge — reading and searching the knowledge base. Operation,
        // because it is what a requester does instead of opening a ticket;
        // Administration → Articles (below) is where the same articles are
        // written. Two words, two verbs. :viewkb is every authenticated user's,
        // so the switch that matters here is the site's `kb_enabled`.
        if (kb_ui::is_enabled() && has_capability('local/moodesk:viewkb', $context)) {
            $items[] = new nav_item(
                'knowledge',
                get_string('nav_knowledge', 'local_moodesk'),
                new \moodle_url('/local/moodesk/kb.php'),
                'kb',
                [],
                false,
                nav_item::GROUP_OPERATION,
            );
        }

        /* ── Administration ────────────────────────────────────────────────── */
        if (has_capability('local/moodesk:managedepartment', $context)) {
            $items[] = new nav_item(
                'departments',
                get_string('nav_departments', 'local_moodesk'),
                new \moodle_url('/local/moodesk/departments.php'),
                'departments',
                [],
                false,
                nav_item::GROUP_ADMINISTRATION,
            );
        }

        // Agents — the in-shell agent directory (Agent Management A0). Replaces
        // the former external deep link into Moodle's role management: onboarding
        // an agent no longer means leaving the product.
        if (has_capability('local/moodesk:manageagents', $context)) {
            $items[] = new nav_item(
                'agents',
                get_string('nav_agents', 'local_moodesk'),
                new \moodle_url('/local/moodesk/agents.php'),
                'users',
                [],
                false,
                nav_item::GROUP_ADMINISTRATION,
            );
        }

        if (has_capability('local/moodesk:managecategory', $context)) {
            $items[] = new nav_item(
                'categories',
                get_string('nav_categories', 'local_moodesk'),
                new \moodle_url('/local/moodesk/categories.php'),
                'categories',
                [],
                false,
                nav_item::GROUP_ADMINISTRATION,
            );
        }

        // Articles — the knowledge base's authoring screens. Under
        // Administration, right after Categories, because articles are filed
        // under that same taxonomy (roadmap decision, 2026-09-12). Starter and
        // up, so no edition gate; `kb_enabled` is the site's own switch for
        // whether the screens appear at all.
        if (
            kb_ui::is_enabled()
                && (has_capability('local/moodesk:editkb', $context)
                    || has_capability('local/moodesk:managekb', $context))
        ) {
            $items[] = new nav_item(
                'kb',
                get_string('nav_kb', 'local_moodesk'),
                new \moodle_url('/local/moodesk/kb_articles.php'),
                'kb',
                [],
                false,
                nav_item::GROUP_ADMINISTRATION,
            );
        }

        /* ── System ────────────────────────────────────────────────────────── */
        // System health — operational diagnostics. Sits beside Reports and
        // License because it answers "is the plugin working", not "how is the
        // desk doing". Not edition-gated: failure detection is production
        // readiness, and a tier without webhooks or ingestion simply never
        // produces incidents from those sources.
        if (has_capability('local/moodesk:viewhealth', $context)) {
            $items[] = new nav_item(
                'health',
                get_string('nav_health', 'local_moodesk'),
                new \moodle_url('/local/moodesk/health.php'),
                'health',
                [],
                false,
                nav_item::GROUP_SYSTEM,
            );
        }

        // Settings — crosses into Moodle's admin tree (legitimate sysadmin boundary).
        if (has_capability('local/moodesk:managesettings', $context) || is_siteadmin()) {
            $items[] = new nav_item(
                'settings',
                get_string('nav_settings', 'local_moodesk'),
                new \moodle_url('/admin/settings.php', ['section' => 'local_moodesk']),
                'settings',
                [],
                true,
                nav_item::GROUP_SYSTEM,
            );
        }

        // Plugins add their own entries, already gated for this user.
        $hook = new \local_moodesk\hook\navigation_items($context, $items);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        return $hook->get_items();
    }

    /**
     * Projection for the application-shell sidebar: the complete internal
     * navigation, grouped. Identity over the catalogue today; kept as a named
     * projection so the shell consumer is explicit and future-proof.
     *
     * @param \context|null $context System context by default.
     * @return nav_item[]
     */
    public static function get_shell_items(?\context $context = null): array {
        return self::get_catalogue($context);
    }

    /**
     * Projection for Moodle's primary navigation: the single "mooDesk" crossing
     * point, no children.
     *
     * Realises the Accepted navigation model (docs/ui-navigation-model.md): the
     * product's full navigation lives in the shell sidebar, so Moodle's header
     * keeps exactly one entry point ("Moodle invisible").
     *
     * @return nav_item
     */
    public static function get_primary_nav_node(): nav_item {
        return self::get_entry_point();
    }

    /**
     * Resolve a page key to the top-level catalogue node that should light up.
     *
     * Most screens already pass their parent node key to {@see app_page} (e.g.
     * category_edit passes 'categories'), so this is largely identity; the map
     * covers the detail/edit/member sub-keys explicitly (nav model §4) and keeps
     * active-state robust if a screen ever passes its own granular key.
     *
     * @param string $pagekey The page key passed to app_page.
     * @return string The catalogue node key to mark active.
     */
    public static function resolve_active_key(string $pagekey): string {
        static $map = [
            'view'               => 'tickets',
            'category_edit'      => 'categories',
            'department_edit'    => 'departments',
            'department_members' => 'departments',
            // Reading an article lights Knowledge, whoever reads it; only the
            // edit screen lights Articles. Never decided by referrer.
            'kb_article'         => 'knowledge',
            'kb_article_edit'    => 'kb',
        ];

        return $map[$pagekey] ?? $pagekey;
    }
}
