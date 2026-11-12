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

namespace local_moodesk\output;

use local_moodesk\service\nav_item;
use local_moodesk\service\navigation_service;
use renderable;
use renderer_base;
use templatable;

/**
 * The standalone mooDesk application shell: own top bar + sidebar + content slot.
 *
 * Rendered on Moodle's `embedded` pagelayout (via {@see app_page}). Navigation
 * comes from {@see navigation_service}, so the shell sidebar and Moodle's
 * single primary-navigation crossing point stay in lockstep. The shell paints
 * 100% of what the user sees; Moodle remains the substrate only.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class app_shell implements renderable, templatable {
    /** @var string Already-rendered inner page content. */
    protected string $content;

    /** @var string Active navigation/page key (e.g. 'dashboard'). */
    protected string $activekey;

    /** @var bool Whether the sidebar starts collapsed (per-user preference). */
    protected bool $collapsed;

    /**
     * Build the application shell around already-rendered content.
     *
     * @param string $content   Inner page HTML to mount in the content slot.
     * @param string $activekey Page key of the current screen, for active state.
     * @param bool   $collapsed Initial collapsed state of the sidebar.
     */
    public function __construct(string $content, string $activekey, bool $collapsed = false) {
        $this->content = $content;
        $this->activekey = $activekey;
        $this->collapsed = $collapsed;
    }

    /**
     * Build the template context.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        $entry = navigation_service::get_entry_point();
        $activekey = navigation_service::resolve_active_key($this->activekey);

        // The full internal navigation, grouped into the shell's sections.
        // Settings is pinned to the sidebar footer (matching the design
        // reference); everything else is rendered under its section header.
        $grouped = [
            nav_item::GROUP_OPERATION      => [],
            nav_item::GROUP_ADMINISTRATION => [],
            nav_item::GROUP_SYSTEM         => [],
        ];
        $footeritems = [];
        foreach (navigation_service::get_shell_items() as $item) {
            $exported = $this->export_item($item, $activekey);
            if ($item->key === 'settings') {
                $footeritems[] = $exported;
                continue;
            }
            $group = array_key_exists($item->group, $grouped)
                ? $item->group
                : nav_item::GROUP_OPERATION;
            $grouped[$group][] = $exported;
        }

        $grouplabels = [
            nav_item::GROUP_OPERATION      => get_string('shell_group_operation', 'local_moodesk'),
            nav_item::GROUP_ADMINISTRATION => get_string('shell_group_administration', 'local_moodesk'),
            nav_item::GROUP_SYSTEM         => get_string('shell_group_system', 'local_moodesk'),
        ];
        $notices = $this->export_notices($output);

        $navgroups = [];
        foreach ($grouped as $key => $items) {
            if (empty($items)) {
                continue;
            }
            $navgroups[] = [
                'key'   => $key,
                'label' => $grouplabels[$key],
                'items' => $items,
            ];
        }

        return [
            'brand' => [
                'url' => $entry->url->out(false),
                'name' => get_string('pluginname', 'local_moodesk'),
                'tagline' => get_string('shell_brand_tagline', 'local_moodesk'),
            ],
            'navlabel' => get_string('shell_nav_label', 'local_moodesk'),
            'navgroups' => $navgroups,
            'footeritems' => $footeritems,
            'returnurl' => (new \moodle_url('/'))->out(false),
            'returnlabel' => get_string('shell_return_to_moodle', 'local_moodesk'),
            'togglelabel' => get_string('shell_toggle_nav', 'local_moodesk'),
            'mainlabel' => get_string('shell_main_label', 'local_moodesk'),
            'skiplabel' => get_string('shell_skip_to_content', 'local_moodesk'),
            'closedrawerlabel' => get_string('shell_close_nav', 'local_moodesk'),
            'collapsed' => $this->collapsed,
            'notifications' => $this->export_notifications(),
            'user' => $this->export_user($output),
            'notices' => $notices,
            'hasnotices' => !empty($notices),
            'content' => $this->content,
        ];
    }

    /**
     * Render the notices plugins add above the content (hook shell_notices).
     *
     * @param renderer_base $output
     * @return array<int, array{html: string}> One entry per notice, in order.
     */
    protected function export_notices(renderer_base $output): array {
        $hook = new \local_moodesk\hook\shell_notices($this->activekey);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        $notices = [];
        foreach ($hook->get_notices() as $notice) {
            $notices[] = ['html' => $output->render_from_template($notice['template'], $notice['context'])];
        }
        return $notices;
    }

    /**
     * Export the notifications affordance for the top bar (phase F7).
     *
     * Surfaces Moodle's own notification stream — chrome the embedded pagelayout
     * removes — as a bell with an unread-count badge linking to Moodle's
     * notifications page. No new data or store: the count comes from Moodle's
     * popup-notifications API when present (guarded for cross-version safety).
     *
     * @return array
     */
    protected function export_notifications(): array {
        global $USER;

        $count = 0;
        if (class_exists('\message_popup\api')) {
            $count = (int) \message_popup\api::count_unread_popup_notifications($USER->id);
        }

        return [
            'url' => (new \moodle_url('/message/output/popup/notifications.php'))->out(false),
            'label' => get_string('shell_notifications', 'local_moodesk'),
            'hascount' => $count > 0,
            'countlabel' => $count > 99 ? '99+' : (string) $count,
        ];
    }

    /**
     * Export a single nav item (and its children) for the template.
     *
     * @param nav_item $item
     * @param string $activekey Resolved active page key to match against.
     * @return array
     */
    protected function export_item(nav_item $item, string $activekey): array {
        $children = array_map(
            fn(nav_item $child): array => $this->export_item($child, $activekey),
            $item->children
        );
        $childactive = (bool) array_filter($children, static fn(array $c): bool => $c['active'] || $c['activetrail']);

        return [
            'key' => $item->key,
            'label' => $item->label,
            'url' => $item->url->out(false),
            'icon' => $item->icon,
            'external' => $item->external,
            'active' => $item->key === $activekey,
            'activetrail' => $childactive,
            'haschildren' => !empty($children),
            'children' => $children,
        ];
    }

    /**
     * Export the current-user block for the top bar user menu.
     *
     * @param renderer_base $output
     * @return array
     */
    protected function export_user(renderer_base $output): array {
        global $USER;

        $context = \context_system::instance();
        $rolelabel = '';
        if (is_siteadmin()) {
            $rolelabel = get_string('shell_role_admin', 'local_moodesk');
        } else if (
            has_capability('local/moodesk:agent', $context)
                || has_capability('local/moodesk:viewall', $context)
        ) {
            $rolelabel = get_string('shell_role_agent', 'local_moodesk');
        }

        return [
            'fullname' => fullname($USER),
            'rolelabel' => $rolelabel,
            'avatar' => $output->user_picture($USER, ['size' => 36, 'link' => false]),
            'menulabel' => get_string('shell_user_menu', 'local_moodesk'),
            'profileurl' => (new \moodle_url('/user/profile.php', ['id' => $USER->id]))->out(false),
            'profilelabel' => get_string('shell_view_profile', 'local_moodesk'),
            'preferencesurl' => (new \moodle_url('/user/preferences.php'))->out(false),
            'preferenceslabel' => get_string('shell_preferences', 'local_moodesk'),
            'logouturl' => (new \moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false),
            'logoutlabel' => get_string('shell_logout', 'local_moodesk'),
        ];
    }
}
