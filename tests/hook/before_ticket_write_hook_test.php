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
 * Tests for the before_ticket_write hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\domain\ticket;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;

/**
 * Writes are offered to plugins before they happen, and a veto stops them.
 *
 * @covers \local_moodesk\hook\before_ticket_write
 * @covers \local_moodesk\service\ticket_service
 * @covers \local_moodesk\service\reply_service::add_public_reply
 */
final class before_ticket_write_hook_test extends \advanced_testcase {
    /** @var int The ticket under test. */
    private int $ticketid;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

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

        $requester = $this->getDataGenerator()->create_user();
        $result = ticket_service::create()->create_ticket((object) [
            'userid'        => $requester->id,
            'subject'       => 'Cannot access my course',
            'message'       => 'Body.',
            'messageformat' => FORMAT_PLAIN,
        ], $requester->id);
        $this->ticketid = (int) $result['ticketid'];
        $this->resetDebugging();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    public function test_without_a_veto_the_write_goes_ahead(): void {
        global $DB;
        $seen = null;
        $this->redirectHook(before_ticket_write::class, function (before_ticket_write $hook) use (&$seen): void {
            $seen = [(int) $hook->ticket->id, $hook->operation, $hook->actorid];
        });

        ticket_service::create()->set_priority($this->ticketid, ticket::PRIORITY_HIGH, (int) get_admin()->id);

        $this->assertSame([$this->ticketid, before_ticket_write::OP_PRIORITY, (int) get_admin()->id], $seen);
        $priority = (int) $DB->get_field('local_moodesk_ticket', 'priority', ['id' => $this->ticketid]);
        $this->assertSame(ticket::PRIORITY_HIGH, $priority);
    }

    public function test_a_veto_stops_a_ticket_write_with_its_string(): void {
        global $DB;
        $before = (int) $DB->get_field('local_moodesk_ticket', 'priority', ['id' => $this->ticketid]);
        $this->redirectHook(before_ticket_write::class, function (before_ticket_write $hook): void {
            $hook->veto('nopermissions', 'error', 'test');
        });

        try {
            ticket_service::create()->set_priority($this->ticketid, ticket::PRIORITY_HIGH, (int) get_admin()->id);
            $this->fail('The vetoed write went ahead.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
            $this->assertSame('error', $e->module);
        }
        $this->assertSame($before, (int) $DB->get_field('local_moodesk_ticket', 'priority', ['id' => $this->ticketid]));
    }

    public function test_a_veto_stops_a_public_reply(): void {
        global $DB;
        $replies = $DB->count_records('local_moodesk_reply', ['ticketid' => $this->ticketid]);
        $this->redirectHook(before_ticket_write::class, function (before_ticket_write $hook): void {
            if ($hook->operation === before_ticket_write::OP_PUBLIC_REPLY) {
                $hook->veto('nopermissions', 'error', 'test');
            }
        });

        try {
            reply_service::create()->add_public_reply(
                $this->ticketid,
                (object) ['message' => 'Hello', 'messageformat' => FORMAT_PLAIN],
                (int) get_admin()->id
            );
            $this->fail('The vetoed reply was saved.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame($replies, $DB->count_records('local_moodesk_reply', ['ticketid' => $this->ticketid]));
    }

    public function test_the_first_veto_wins(): void {
        $hook = new before_ticket_write((object) ['id' => 1], before_ticket_write::OP_STATUS, 2);
        $this->assertFalse($hook->is_vetoed());
        $hook->veto('first', 'local_moodesk');
        $hook->veto('second', 'local_moodesk');
        $this->assertTrue($hook->is_vetoed());

        $this->redirectHook(before_ticket_write::class, function (before_ticket_write $hook): void {
            $hook->veto('nopermissions', 'error', 'one');
            $hook->veto('invalidrecord', 'error');
        });
        $this->expectExceptionMessage(get_string('nopermissions', 'error', 'one'));
        before_ticket_write::check((object) ['id' => 1], before_ticket_write::OP_STATUS, 2);
    }
}
