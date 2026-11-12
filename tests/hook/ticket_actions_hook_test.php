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
 * Tests for the ticket_actions hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\controller\ticket_page_handler;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;

/**
 * Actions mooDesk does not know are offered to plugins, and only those.
 *
 * @covers \local_moodesk\hook\ticket_actions
 * @covers \local_moodesk\controller\ticket_page_handler
 */
final class ticket_actions_hook_test extends \advanced_testcase {
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
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    /**
     * The handler as the endpoints build it, for an agent who can do everything.
     *
     * @return ticket_page_handler
     */
    private function handler(): ticket_page_handler {
        return new ticket_page_handler(
            $this->ticketid,
            (int) get_admin()->id,
            ticket_service::create(),
            reply_service::create(),
            true,
            true
        );
    }

    /**
     * Put a plain POST for an action into the request.
     *
     * @param string $action
     */
    private function post(string $action): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => $action, 'sesskey' => sesskey(), 'id' => (string) $this->ticketid];
    }

    public function test_without_a_callback_an_unknown_action_is_left_alone(): void {
        $this->assertNull($this->handler()->run_plugin_action('pro_action'));

        $this->post('pro_action');
        $this->handler()->handle();
        $this->addToAssertionCount(1);
    }

    public function test_a_callback_handles_its_action(): void {
        $seen = null;
        $this->redirectHook(ticket_actions::class, function (ticket_actions $hook) use (&$seen): void {
            $seen = [$hook->action, $hook->ticketid, $hook->userid, $hook->canaddnote, $hook->canmanage];
            if ($hook->action === 'pro_action') {
                $hook->respond('Done.', ['teamid' => 3]);
            }
        });

        $result = $this->handler()->run_plugin_action('pro_action');

        $this->assertSame(['pro_action', $this->ticketid, (int) get_admin()->id, true, true], $seen);
        $this->assertSame(['message' => 'Done.', 'data' => ['teamid' => 3]], $result);
        $this->assertNull($this->handler()->run_plugin_action('other_action'));
    }

    public function test_the_page_redirects_after_a_handled_action(): void {
        $this->redirectHook(ticket_actions::class, function (ticket_actions $hook): void {
            $hook->respond('Done.');
        });
        $this->post('pro_action');

        // Under PHPUnit, redirect() reports itself by throwing.
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('redirecterrordetected', 'error'));
        $this->handler()->handle();
    }

    public function test_known_actions_are_never_offered(): void {
        $calls = 0;
        $this->redirectHook(ticket_actions::class, function () use (&$calls): void {
            $calls++;
        });
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'priority', 'sesskey' => sesskey(), 'newpriority' => '3'];

        try {
            $this->handler()->handle();
        } catch (\moodle_exception $e) {
            // The priority handler redirects; that is not what is under test.
            $this->assertSame('redirecterrordetected', $e->errorcode);
        }
        $this->assertSame(0, $calls);
    }

    public function test_respond_refuses_a_second_answer(): void {
        $hook = new ticket_actions('pro_action', 1, 2, true, true);
        $hook->respond('Done.');
        $this->expectException(\coding_exception::class);
        $hook->respond('Again.');
    }

    public function test_respond_refuses_a_reserved_key(): void {
        $hook = new ticket_actions('pro_action', 1, 2, true, true);
        $this->expectException(\coding_exception::class);
        $hook->respond('Done.', ['success' => false]);
    }
}
