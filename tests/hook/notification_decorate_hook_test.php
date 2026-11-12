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
 * Tests for the notification_decorate hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\service\ticket_service;

/**
 * Ticket notifications offer their Reply-To to plugins before they are sent.
 *
 * @covers \local_moodesk\hook\notification_decorate
 * @covers \local_moodesk\observer
 */
final class notification_decorate_hook_test extends \advanced_testcase {
    /** @var int The ticket under test. */
    private int $ticketid;

    /** @var \stdClass An agent the ticket is assigned to. */
    private \stdClass $agent;

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
        $this->ticketid = (int) ticket_service::create()->create_ticket((object) [
            'userid' => $requester->id, 'subject' => 'Printer', 'message' => 'Body.', 'messageformat' => FORMAT_PLAIN,
        ], $requester->id)['ticketid'];

        $this->agent = $this->getDataGenerator()->create_user();
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($roleid, $this->agent->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->resetDebugging();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * Assign the ticket to the agent, which notifies them, and return what was sent.
     *
     * @return \stdClass[] The messages.
     */
    private function assign_and_collect(): array {
        $sink = $this->redirectMessages();
        ticket_service::create()->assign($this->ticketid, (int) $this->agent->id, (int) get_admin()->id);
        $messages = $sink->get_messages();
        $sink->close();
        return $messages;
    }

    public function test_the_hook_receives_the_notification_and_the_base_reply_to(): void {
        $seen = [];
        $this->redirectHook(notification_decorate::class, function (notification_decorate $hook) use (&$seen): void {
            $seen[] = [$hook->ticketid, $hook->userid, $hook->get_reply_to()];
        });

        $messages = $this->assign_and_collect();

        $this->assertNotEmpty($messages);
        $this->assertContains([$this->ticketid, (int) $this->agent->id, null], $seen);
    }

    public function test_a_plugin_reply_to_is_set_on_the_message(): void {
        $this->redirectHook(notification_decorate::class, function (notification_decorate $hook): void {
            $hook->set_reply_to('reply+abc@example.com', 'Support');
        });

        // The message sink drops the Reply-To; the email carries it. On
        // PostgreSQL every test runs inside a transaction, and Moodle holds
        // messages back until it commits, so this one must own its transaction.
        $this->preventResetByRollback();
        unset_config('noemailever');
        $sink = $this->redirectEmails();
        ticket_service::create()->assign($this->ticketid, (int) $this->agent->id, (int) get_admin()->id);
        $emails = $sink->get_messages();
        $sink->close();

        $toagent = array_values(array_filter($emails, fn($e) => $e->to === $this->agent->email));
        $this->assertNotEmpty($toagent);
        $this->assertStringContainsString('reply+abc@example.com', $toagent[0]->header);
    }

    public function test_a_plugin_message_id_is_the_email_message_id(): void {
        $this->redirectHook(notification_decorate::class, function (notification_decorate $hook): void {
            $hook->set_message_id('<signed.abcdefgh@example.com>');
        });

        // As above: the email, not the message sink, carries the header, and on
        // PostgreSQL the test must own its transaction for the mail to go out.
        $this->preventResetByRollback();
        unset_config('noemailever');
        $sink = $this->redirectEmails();
        ticket_service::create()->assign($this->ticketid, (int) $this->agent->id, (int) get_admin()->id);
        $emails = $sink->get_messages();
        $sink->close();

        $toagent = array_values(array_filter($emails, fn($e) => $e->to === $this->agent->email));
        $this->assertNotEmpty($toagent);
        $this->assertMatchesRegularExpression('/^Message-ID: <signed\.abcdefgh@example\.com>\r?$/mi', $toagent[0]->header);
        $this->assertSame(1, preg_match_all('/^Message-ID:/mi', $toagent[0]->header));
        // The shared noreply user is not changed for the next sender.
        $this->assertObjectNotHasProperty('customheaders', \core_user::get_noreply_user());
    }

    public function test_an_invalid_message_id_is_rejected(): void {
        $hook = new notification_decorate(1, 2, 'Subject', null, '');
        $this->expectException(\coding_exception::class);
        $hook->set_message_id('no-brackets@example.com');
    }

    public function test_an_invalid_address_is_rejected(): void {
        $hook = new notification_decorate(1, 2, 'Subject', null, '');
        $this->expectException(\coding_exception::class);
        $hook->set_reply_to('not an address');
    }

    public function test_clearing_drops_the_name(): void {
        $hook = new notification_decorate(1, 2, 'Subject', 'a@example.com', 'Support');
        $hook->set_reply_to(null, 'Ignored');
        $this->assertNull($hook->get_reply_to());
        $this->assertSame('', $hook->get_reply_to_name());
    }
}
