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
 * Unit tests for the application-shell renderable.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

/**
 * Verifies the shell template context: navigation, active state and user block.
 *
 * @covers \local_moodesk\output\app_shell
 */
final class app_shell_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Build the core renderer used to export the template context.
     *
     * @return \renderer_base
     */
    private function renderer(): \renderer_base {
        global $PAGE;
        return $PAGE->get_renderer('core');
    }

    public function test_content_is_passed_through_verbatim(): void {
        $this->setAdminUser();
        $shell = new app_shell('<p data-test="x">hello</p>', 'dashboard');
        $ctx = $shell->export_for_template($this->renderer());
        $this->assertSame('<p data-test="x">hello</p>', $ctx['content']);
    }

    public function test_brand_and_collapsed_flag(): void {
        $this->setAdminUser();
        $ctx = (new app_shell('', 'dashboard', true))->export_for_template($this->renderer());
        $this->assertSame('mooDesk', $ctx['brand']['name']);
        $this->assertNotSame('', $ctx['brand']['tagline']);
        $this->assertTrue($ctx['collapsed']);
    }

    /**
     * Flatten every navigation item across all groups, in order.
     *
     * @param array $ctx Exported template context.
     * @return array[] Item arrays.
     */
    private function flatten_nav(array $ctx): array {
        $items = [];
        foreach ($ctx['navgroups'] as $group) {
            foreach ($group['items'] as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    public function test_active_item_is_marked(): void {
        $this->setAdminUser();
        $ctx = (new app_shell('', 'dashboard'))->export_for_template($this->renderer());

        $dashboard = null;
        foreach ($this->flatten_nav($ctx) as $item) {
            if ($item['key'] === 'dashboard') {
                $dashboard = $item;
            }
            // No item other than the active one should be flagged.
            if ($item['key'] !== 'dashboard') {
                $this->assertFalse($item['active'], "Item {$item['key']} must not be active.");
            }
        }
        $this->assertNotNull($dashboard, 'Dashboard item must be present.');
        $this->assertTrue($dashboard['active']);
        $this->assertNotSame('', $dashboard['icon']);
    }

    /**
     * F6: the shell exposes a Tickets node (closing the F3 gap) and the ticket
     * detail page key ('tickets' / 'view') lights it up.
     */
    public function test_tickets_node_present_and_active_for_detail(): void {
        $this->setAdminUser();
        $ctx = (new app_shell('', 'view'))->export_for_template($this->renderer());

        $tickets = null;
        foreach ($this->flatten_nav($ctx) as $item) {
            if ($item['key'] === 'tickets') {
                $tickets = $item;
            }
        }
        $this->assertNotNull($tickets, 'Tickets node must exist in the shell nav.');
        $this->assertTrue($tickets['active'], "'view' must resolve active state onto Tickets.");
    }

    /**
     * F6: items are grouped into the shell sections, in order.
     */
    public function test_nav_is_grouped_into_sections(): void {
        $this->setAdminUser();
        $ctx = (new app_shell('', 'dashboard'))->export_for_template($this->renderer());

        $groupkeys = array_column($ctx['navgroups'], 'key');
        $this->assertSame(['operation', 'administration', 'system'], $groupkeys);
        foreach ($ctx['navgroups'] as $group) {
            $this->assertNotSame('', $group['label'], "Group {$group['key']} must have a label.");
            $this->assertNotEmpty($group['items'], "Group {$group['key']} must have items for an admin.");
        }
    }

    public function test_settings_is_pinned_to_footer_not_nav(): void {
        $this->setAdminUser();
        $ctx = (new app_shell('', 'dashboard'))->export_for_template($this->renderer());

        $navkeys = array_column($this->flatten_nav($ctx), 'key');
        $footerkeys = array_column($ctx['footeritems'], 'key');

        $this->assertNotContains('settings', $navkeys, 'Settings must not be in the main nav.');
        $this->assertContains('settings', $footerkeys, 'Settings must be pinned to the footer.');
    }

    public function test_user_block_has_identity_and_logout(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Alex', 'lastname' => 'Thompson']);
        $this->setUser($user);

        $ctx = (new app_shell('', 'dashboard'))->export_for_template($this->renderer());

        $this->assertSame('Alex Thompson', $ctx['user']['fullname']);
        $this->assertStringContainsString('/login/logout.php', $ctx['user']['logouturl']);
        $this->assertStringContainsString('sesskey=', $ctx['user']['logouturl']);
        // A plain user is neither admin nor agent → no role label.
        $this->assertSame('', $ctx['user']['rolelabel']);
    }

    public function test_admin_gets_admin_role_label(): void {
        $this->setAdminUser();
        $ctx = (new app_shell('', 'dashboard'))->export_for_template($this->renderer());
        $this->assertSame(
            get_string('shell_role_admin', 'local_moodesk'),
            $ctx['user']['rolelabel']
        );
    }

    /**
     * F7: the shell exposes a notifications affordance pointing at Moodle's own
     * notifications page, with a count flag (no new data store).
     */
    public function test_notifications_affordance_present(): void {
        $this->setAdminUser();
        $ctx = (new app_shell('', 'dashboard'))->export_for_template($this->renderer());

        $this->assertArrayHasKey('notifications', $ctx);
        $this->assertStringContainsString(
            '/message/output/popup/notifications.php',
            $ctx['notifications']['url']
        );
        $this->assertArrayHasKey('hascount', $ctx['notifications']);
        $this->assertNotSame('', $ctx['skiplabel'], 'A skip-link label must be present (a11y).');
    }
}
