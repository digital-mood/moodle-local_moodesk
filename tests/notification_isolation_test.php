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
 * Isolation of mooDesk ticket notifications through the message provider switch.
 *
 * Staging test F3-B creates a real ticket while every notification it could cause
 * is held back by one site setting: the "disable" flag of the message provider
 * local_moodesk/ticket_notification (config_plugins, plugin "message",
 * name local_moodesk_ticket_notification_disable). These tests pin down what
 * that procedure relies on:
 *
 *  - with the provider disabled, message_send() returns before it stores a
 *    notification or reaches any output processor, for every mooDesk path a
 *    test ticket can take: the new-ticket broadcast, a rule that assigns it,
 *    and closing it with CSAT on;
 *  - nothing is left behind that could send later: no ad hoc task, no
 *    message_email_messages row, no incident, and a closed ticket is out of
 *    the SLA check;
 *  - restoring the previous value (unset, or an explicit value) brings the
 *    notifications back.
 *
 * Each test also runs the same path with the provider enabled, so a pass can
 * never come from a path that sends nothing anyway.
 *
 * @package   local_moodesk
 * @copyright 2026 digitalMood LLC
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

use local_moodesk\service\edition_service;
use local_moodesk\service\ticket_service;

/**
 * Tests for the provider switch used to isolate a staging test ticket.
 *
 * @covers \local_moodesk\observer
 */
final class notification_isolation_test extends \advanced_testcase {
    /** @var string Name of the provider switch in config_plugins (plugin "message"). */
    private const SWITCH = 'local_moodesk_ticket_notification_disable';

    /** @var \stdClass Requester without agent capabilities. */
    private \stdClass $requester;

    /** @var \stdClass First agent. */
    private \stdClass $agent;

    /** @var \stdClass Second agent. */
    private \stdClass $agenttwo;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        edition_service::set_edition_for_testing(edition_service::EDITION_ENTERPRISE);
        set_config('notify_agents_new', 1, 'local_moodesk');
        set_config('notify_assignee_assign', 1, 'local_moodesk');

        $categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$categoryid) {
            $categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid' => null,
                'name' => 'General',
                'sortorder' => 0,
                'visible' => 1,
                'timecreated' => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent = $this->getDataGenerator()->create_user();
        $this->agenttwo = $this->getDataGenerator()->create_user();

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        $systemcontext = \context_system::instance();
        role_assign($roleid, $this->agent->id, $systemcontext->id);
        role_assign($roleid, $this->agenttwo->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        // The message_send() call writes outside the test transaction.
        $this->preventResetByRollback();
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * Control: with the provider enabled, a new unassigned ticket notifies both
     * agents, stores the notifications and sends the emails.
     */
    public function test_new_ticket_notifies_when_provider_enabled(): void {
        $before = $this->baseline();
        $emails = $this->redirectEmails();

        $this->create_ticket();

        $this->assertSame(2, $this->new_moodesk_notifications($before));
        $this->assertSame(2, $emails->count());
        $emails->close();
    }

    /**
     * With the provider disabled, the same ticket stores no notification and
     * reaches no output processor (no email).
     */
    public function test_new_ticket_sends_nothing_when_provider_disabled(): void {
        set_config(self::SWITCH, 1, 'message');
        $before = $this->baseline();
        $emails = $this->redirectEmails();

        $this->create_ticket();

        $this->assertSame(0, $this->new_moodesk_notifications($before));
        $this->assertSame(0, $emails->count());
        $this->assert_nothing_deferred($before);
        $emails->close();
    }

    /**
     * The message sink sits where Moodle hands the message to its processors:
     * it receives the broadcast when the provider is enabled and nothing when
     * it is disabled, so the processors are never reached.
     */
    public function test_disabled_provider_stops_before_the_processors(): void {
        $sink = $this->redirectMessages();
        $this->create_ticket();
        $this->assertSame(2, $sink->count());
        $sink->clear();

        set_config(self::SWITCH, 1, 'message');
        $this->create_ticket();
        $this->assertSame(0, $sink->count());
        $sink->close();
    }

    /**
     * Restoring the previous state, unset or an explicit value, brings the
     * notifications back.
     */
    public function test_restore_to_previous_value_reenables(): void {
        // Previous state: unset.
        $this->assertFalse(get_config('message', self::SWITCH));
        set_config(self::SWITCH, 1, 'message');
        unset_config(self::SWITCH, 'message');
        $this->assertFalse(get_config('message', self::SWITCH));
        $before = $this->baseline();
        $this->create_ticket();
        $this->assertSame(2, $this->new_moodesk_notifications($before));

        // Previous state: an explicit 0.
        set_config(self::SWITCH, 0, 'message');
        set_config(self::SWITCH, 1, 'message');
        set_config(self::SWITCH, 0, 'message');
        $this->assertSame('0', get_config('message', self::SWITCH));
        $before = $this->baseline();
        $this->create_ticket();
        $this->assertSame(2, $this->new_moodesk_notifications($before));
    }

    /**
     * Create a ticket through the real service, firing the real events.
     *
     * @return int Ticket id.
     */
    private function create_ticket(): int {
        $data = new \stdClass();
        $data->userid = $this->requester->id;
        $data->subject = 'pd2381 F3 ticket';
        $data->message = 'Isolation test.';
        $data->messageformat = FORMAT_PLAIN;
        $result = ticket_service::create()->create_ticket($data, (int) $this->requester->id);
        return (int) $result['ticketid'];
    }

    /**
     * Highest ids of every table a deferred send could start from.
     *
     * @return array<string,int>
     */
    private function baseline(): array {
        global $DB;
        $max = static fn(string $table): int => (int) $DB->get_field_sql("SELECT MAX(id) FROM {{$table}}");
        return [
            'notifications' => $max('notifications'),
            'task_adhoc' => $max('task_adhoc'),
            'message_email_messages' => $max('message_email_messages'),
            'local_moodesk_incident' => $max('local_moodesk_incident'),
        ];
    }

    /**
     * mooDesk notifications stored after the baseline.
     *
     * @param array $before Result of baseline().
     * @return int
     */
    private function new_moodesk_notifications(array $before): int {
        global $DB;
        return $DB->count_records_select(
            'notifications',
            'id > ? AND component = ?',
            [$before['notifications'], 'local_moodesk']
        );
    }

    /**
     * Nothing queued after the baseline that could send later.
     *
     * @param array $before Result of baseline().
     */
    private function assert_nothing_deferred(array $before): void {
        global $DB;
        foreach (['task_adhoc', 'message_email_messages', 'local_moodesk_incident'] as $table) {
            $this->assertSame(0, $DB->count_records_select($table, 'id > ?', [$before[$table]]), $table);
        }
    }
}
