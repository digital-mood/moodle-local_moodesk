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
 * Tests for the navigation_items hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\service\nav_item;
use local_moodesk\service\navigation_service;

/**
 * Plugins add navigation entries to the base catalogue, in place.
 *
 * @covers \local_moodesk\hook\navigation_items
 * @covers \local_moodesk\service\navigation_service::get_catalogue
 */
final class navigation_items_hook_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * A navigation entry for the tests.
     *
     * @param string $key
     * @return nav_item
     */
    private function item(string $key): nav_item {
        return new nav_item(
            $key,
            $key,
            new \moodle_url('/local/moodesk/index.php'),
            'tickets',
            [],
            false,
            nav_item::GROUP_OPERATION,
        );
    }

    /**
     * The keys of a catalogue, in order.
     *
     * @param nav_item[] $items
     * @return string[]
     */
    private function keys(array $items): array {
        return array_map(static fn(nav_item $item): string => $item->key, $items);
    }

    /**
     * The catalogue with no plugin listening, including an installed add-on.
     *
     * @return string[]
     */
    private function base_keys(): array {
        $this->redirectHook(navigation_items::class, static function (): void {
        });
        $keys = $this->keys(navigation_service::get_catalogue());
        $this->stopHookRedirections();
        return $keys;
    }

    public function test_without_a_callback_the_catalogue_is_the_base_one(): void {
        $this->setAdminUser();
        $base = $this->base_keys();

        $seen = null;
        $this->redirectHook(navigation_items::class, function (navigation_items $hook) use (&$seen): void {
            $seen = $this->keys($hook->get_items());
        });

        $this->assertSame($base, $this->keys(navigation_service::get_catalogue()));
        $this->assertSame($base, $seen);
    }

    public function test_a_callback_adds_items_after_a_key_or_at_the_end(): void {
        $this->setAdminUser();
        $base = $this->base_keys();

        $this->redirectHook(navigation_items::class, function (navigation_items $hook): void {
            $hook->add_item($this->item('pro_after_tickets'), 'tickets');
            $hook->add_item($this->item('pro_at_end'));
            $hook->add_item($this->item('pro_unknown_anchor'), 'no_such_key');
        });

        $keys = $this->keys(navigation_service::get_catalogue());
        $this->assertSame('pro_after_tickets', $keys[array_search('tickets', $keys, true) + 1]);
        $this->assertSame(['pro_at_end', 'pro_unknown_anchor'], array_slice($keys, -2));
        $this->assertCount(count($base) + 3, $keys);
    }

    public function test_the_shell_shows_the_added_items(): void {
        global $PAGE;
        $this->setAdminUser();
        $this->redirectHook(navigation_items::class, function (navigation_items $hook): void {
            $hook->add_item($this->item('pro_entry'), 'dashboard');
        });

        $ctx = (new \local_moodesk\output\app_shell('', 'dashboard'))->export_for_template($PAGE->get_renderer('core'));

        $operation = array_values(array_filter($ctx['navgroups'], static fn(array $g): bool => $g['key'] === 'operation'))[0];
        $this->assertContains('pro_entry', array_column($operation['items'], 'key'));
    }

    public function test_not_dispatched_without_access(): void {
        $calls = 0;
        $this->redirectHook(navigation_items::class, function () use (&$calls): void {
            $calls++;
        });

        $this->assertSame([], navigation_service::get_catalogue());
        $this->assertSame(0, $calls);
    }

    public function test_a_duplicate_key_is_rejected(): void {
        $hook = new navigation_items(\context_system::instance(), [$this->item('tickets')]);
        $this->expectException(\coding_exception::class);
        $hook->add_item($this->item('tickets'));
    }
}
