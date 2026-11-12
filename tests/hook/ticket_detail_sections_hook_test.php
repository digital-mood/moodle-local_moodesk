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
 * Tests for the ticket_detail_sections hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\output\ticket_detail;
use local_moodesk\service\ticket_service;

/**
 * Plugin sections reach their slot of the ticket detail, and nothing changes without them.
 *
 * @covers \local_moodesk\hook\ticket_detail_sections
 * @covers \local_moodesk\output\ticket_detail
 */
final class ticket_detail_sections_hook_test extends \advanced_testcase {
    /** @var int The ticket under test. */
    private int $ticketid;

    /** @var \stdClass Who raised it. */
    private \stdClass $requester;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$categoryid) {
            $categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid'     => null,
                'name'         => 'General',
                'sortorder'    => 0,
                'visible'      => 1,
                'timecreated'  => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $result = ticket_service::create()->create_ticket((object) [
            'userid'        => $this->requester->id,
            'subject'       => 'Cannot access my course',
            'message'       => 'Body.',
            'messageformat' => FORMAT_PLAIN,
        ], $this->requester->id);
        $this->ticketid = (int) $result['ticketid'];
        $this->resetDebugging();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * Add one marked section to every slot.
     */
    private function redirect_one_per_slot(): void {
        $this->redirectHook(ticket_detail_sections::class, function (ticket_detail_sections $hook): void {
            $slots = [
                ticket_detail_sections::SLOT_BANNER,
                ticket_detail_sections::SLOT_SIDEBAR,
                ticket_detail_sections::SLOT_COMPOSER,
            ];
            foreach ($slots as $slot) {
                $hook->add_section($slot, 'core/notification_info', ['message' => "PLUGIN-{$slot}"]);
            }
        });
    }

    /**
     * The detail rendered for a user.
     *
     * @param int $userid
     * @return string
     */
    private function render_for(int $userid): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template(
            'local_moodesk/ticket_detail',
            ticket_detail::create($this->ticketid, $userid)->export_for_template()
        );
    }

    public function test_without_a_section_every_slot_is_empty(): void {
        $this->setAdminUser();
        $this->redirectHook(ticket_detail_sections::class, static function (): void {
        });
        $ctx = ticket_detail::create($this->ticketid, (int) get_admin()->id)->export_for_template();

        foreach (['banner', 'sidebar', 'composer'] as $slot) {
            $this->assertSame([], $ctx['pluginsections' . $slot]);
            $this->assertFalse($ctx['haspluginsections' . $slot]);
        }
        $this->assertStringNotContainsString('mhd-plugin-sections-banner', $this->render_for((int) get_admin()->id));
    }

    public function test_the_hook_receives_the_ticket_and_viewer(): void {
        $this->setAdminUser();
        $seen = null;
        $this->redirectHook(ticket_detail_sections::class, function (ticket_detail_sections $hook) use (&$seen): void {
            $seen = [(int) $hook->ticket->id, $hook->userid, $hook->canmanage, $hook->isdrawer];
        });

        ticket_detail::create($this->ticketid, (int) get_admin()->id)->export_for_template(ticket_detail::VARIANT_DRAWER);

        $this->assertSame([$this->ticketid, (int) get_admin()->id, true, true], $seen);
    }

    public function test_an_agent_sees_each_section_once_in_its_slot(): void {
        $this->setAdminUser();
        $this->redirect_one_per_slot();

        $html = $this->render_for((int) get_admin()->id);

        foreach (['banner', 'sidebar', 'composer'] as $slot) {
            $this->assertSame(1, substr_count($html, "PLUGIN-{$slot}"), "Slot {$slot}");
        }
        $this->assertLessThan(strpos($html, 'mhd-detail__rail'), strpos($html, 'PLUGIN-banner'));
        $this->assertGreaterThan(strpos($html, 'mhd-detail__rail'), strpos($html, 'PLUGIN-sidebar'));
        $this->assertGreaterThan(strpos($html, 'mhd-detail__composer'), strpos($html, 'PLUGIN-composer'));
    }

    public function test_without_a_rail_sidebar_sections_render_in_the_main_column(): void {
        $this->setUser($this->requester);
        $this->redirect_one_per_slot();

        $html = $this->render_for((int) $this->requester->id);

        $this->assertStringNotContainsString('mhd-detail__rail', $html);
        $this->assertSame(1, substr_count($html, 'PLUGIN-sidebar'));
        $this->assertSame(1, substr_count($html, 'PLUGIN-banner'));
    }

    public function test_an_unknown_slot_is_rejected(): void {
        $hook = new ticket_detail_sections((object) ['id' => 1], 2, false, false);
        $this->expectException(\coding_exception::class);
        $hook->add_section('footer', 'core/notification_info');
    }
}
