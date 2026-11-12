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
 * Tests for the notification observers.
 *
 * Every handler in \local_moodesk\observer swallows its own exceptions by
 * design, so a broken handler produces no error anywhere — the notification
 * simply never arrives. That makes these tests the only thing standing between
 * a regression and a silent loss of notifications in production, and it is why
 * the guard clauses are asserted individually rather than through a single
 * happy-path smoke test.
 *
 * Handlers are invoked directly with a hand-built event instead of being driven
 * through ticket_service/reply_service. The service path exercises far more
 * code than the observer and cannot reach cases like "the ticket row vanished
 * between the event and the handler".
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;
use local_moodesk\service\edition_service;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;

/**
 * Observer test.
 *
 * @covers \local_moodesk\observer
 */
final class observer_test extends \advanced_testcase {
    /** @var ticket_service */
    private ticket_service $ticketservice;

    /** @var reply_service */
    private reply_service $replyservice;

    /** @var \stdClass Ticket requester — no agent capabilities. */
    private \stdClass $requester;

    /** @var \stdClass Agent holding local/moodesk:viewall. */
    private \stdClass $agent;

    /** @var \stdClass Second agent, to prove broadcasts reach more than one. */
    private \stdClass $agenttwo;

    /** @var int */
    private int $categoryid;

    /** @var int Role id of the bundled agent role. */
    private int $agentroleid;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->ticketservice = ticket_service::create();
        $this->replyservice  = reply_service::create();

        // Enabled by default so each test only has to state the setting it is
        // actually exercising. The "disabled" tests turn their own flag off.
        set_config('notify_agents_new', 1, 'local_moodesk');
        set_config('notify_requester_reply', 1, 'local_moodesk');
        set_config('notify_assignee_assign', 1, 'local_moodesk');
        set_config('notify_sla_breach', 1, 'local_moodesk');

        $this->categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$this->categoryid) {
            $this->categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid' => null,
                'name' => 'General',
                'sortorder' => 0,
                'visible' => 1,
                'timecreated' => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $this->categoryid, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent = $this->getDataGenerator()->create_user();
        $this->agenttwo = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $this->agentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($this->agentroleid, $this->agent->id, $systemcontext->id);
        role_assign($this->agentroleid, $this->agenttwo->id, $systemcontext->id);

        accesslib_clear_all_caches_for_unit_testing();

        // The message_send() call writes outside the test transaction, so the
        // usual rollback-based reset cannot be used in this suite.
        $this->preventResetByRollback();
    }

    protected function tearDown(): void {
        // Leaking the override would silently change the edition seen by every
        // test that runs after this one in the same process.
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    // Handler: ticket_created.

    /**
     * An unassigned new ticket is broadcast to every agent.
     */
    public function test_ticket_created_broadcasts_to_all_agents(): void {
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_created($this->build_created_event($ticket->id, $this->requester->id));

        $this->assertEqualsCanonicalizing(
            [(int) $this->agent->id, (int) $this->agenttwo->id],
            $this->recipients($sink)
        );
        $sink->close();
    }

    /**
     * With notify_agents_new off, nothing is sent.
     */
    public function test_ticket_created_respects_disabled_setting(): void {
        set_config('notify_agents_new', 0, 'local_moodesk');
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_created($this->build_created_event($ticket->id, $this->requester->id));

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * A ticket that already has an assignee needs no broadcast — somebody owns
     * it, so waking every agent would be noise.
     */
    public function test_ticket_created_skips_broadcast_when_already_assigned(): void {
        $ticket = $this->make_ticket(['assigneeid' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_created($this->build_created_event($ticket->id, $this->requester->id));

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * An agent who opened the ticket themselves is excluded from the broadcast,
     * but the other agents still get it.
     */
    public function test_ticket_created_excludes_agent_who_is_the_requester(): void {
        $ticket = $this->make_ticket(['userid' => $this->agent->id, 'createdby' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_created($this->build_created_event($ticket->id, $this->agent->id));

        $this->assertSame([(int) $this->agenttwo->id], $this->recipients($sink));
        $sink->close();
    }

    /**
     * A deleted ticket row must not raise — the handler returns quietly.
     */
    public function test_ticket_created_handles_missing_ticket(): void {
        $sink = $this->redirectMessages();

        observer::ticket_created($this->build_created_event(999999, $this->requester->id));

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    // Handler: ticket_replied.

    /**
     * A public agent reply notifies the requester, and nobody else.
     */
    public function test_ticket_replied_notifies_requester(): void {
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_replied($this->build_replied_event($ticket->id, $this->agent->id));

        $this->assertSame([(int) $this->requester->id], $this->recipients($sink));
        $sink->close();
    }

    /**
     * With notify_requester_reply off, nothing is sent.
     */
    public function test_ticket_replied_respects_disabled_setting(): void {
        set_config('notify_requester_reply', 0, 'local_moodesk');
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_replied($this->build_replied_event($ticket->id, $this->agent->id));

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * Internal notes are agent-only. Forwarding one to the requester would leak
     * private triage discussion, so the type guard is a confidentiality
     * boundary rather than a preference.
     */
    public function test_ticket_replied_never_forwards_internal_notes(): void {
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_replied(
            $this->build_replied_event($ticket->id, $this->agent->id, reply::TYPE_INTERNAL_NOTE)
        );

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * The anti-echo guard: requesters replying on their own ticket must not be
     * notified about their own message.
     *
     * This is the guard that made "replies are not being delivered" look like a
     * bug during lab validation — the tester was signed in as both requester
     * and agent, so every notification was correctly suppressed.
     */
    public function test_ticket_replied_does_not_echo_back_to_the_author(): void {
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_replied($this->build_replied_event($ticket->id, $this->requester->id));

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * When the replying agent's account is gone, the requester is still
     * notified and the body falls back to the "Deleted user" label.
     */
    public function test_ticket_replied_falls_back_when_agent_was_deleted(): void {
        global $DB;

        $ticket = $this->make_ticket();
        $DB->delete_records('user', ['id' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_replied($this->build_replied_event($ticket->id, $this->agent->id));

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame((int) $this->requester->id, (int) $messages[0]->useridto);
        $this->assertStringContainsString(
            get_string('deleteduser', 'local_moodesk'),
            $messages[0]->fullmessage
        );
        $sink->close();
    }

    // Handler: ticket_assigned.

    /**
     * The new assignee is notified.
     */
    public function test_ticket_assigned_notifies_new_assignee(): void {
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_assigned(
            $this->build_assigned_event($ticket->id, $this->agent->id, $this->agent->id)
        );

        $this->assertSame([(int) $this->agent->id], $this->recipients($sink));
        $sink->close();
    }

    /**
     * With notify_assignee_assign off, nothing is sent.
     */
    public function test_ticket_assigned_respects_disabled_setting(): void {
        set_config('notify_assignee_assign', 0, 'local_moodesk');
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_assigned(
            $this->build_assigned_event($ticket->id, $this->agent->id, $this->agent->id)
        );

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * Unassignment carries a null newassigneeid and must notify nobody — there
     * is no new owner to tell.
     */
    public function test_ticket_assigned_ignores_unassignment(): void {
        $ticket = $this->make_ticket(['assigneeid' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_assigned(
            $this->build_assigned_event($ticket->id, null, $this->agent->id, $this->agent->id)
        );

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * A newassigneeid pointing at a deleted account must not raise.
     */
    public function test_ticket_assigned_handles_missing_assignee(): void {
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_assigned(
            $this->build_assigned_event($ticket->id, 999999, $this->agent->id)
        );

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    // Handler: ticket_sla_breached.

    /**
     * A breach on an assigned ticket goes to its assignee only — the other
     * agents are not pulled in.
     */
    public function test_sla_breach_notifies_only_the_assignee(): void {
        $ticket = $this->make_ticket(['assigneeid' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_sla_breached($this->build_sla_event($ticket->id, $this->agent->id));

        $this->assertSame([(int) $this->agent->id], $this->recipients($sink));
        $sink->close();
    }

    /**
     * A breach on an unassigned ticket is broadcast so somebody picks it up.
     */
    public function test_sla_breach_broadcasts_when_unassigned(): void {
        $ticket = $this->make_ticket();
        $sink = $this->redirectMessages();

        observer::ticket_sla_breached($this->build_sla_event($ticket->id, 0));

        $this->assertEqualsCanonicalizing(
            [(int) $this->agent->id, (int) $this->agenttwo->id],
            $this->recipients($sink)
        );
        $sink->close();
    }

    /**
     * With notify_sla_breach off, nothing is sent.
     */
    public function test_sla_breach_respects_disabled_setting(): void {
        set_config('notify_sla_breach', 0, 'local_moodesk');
        $ticket = $this->make_ticket(['assigneeid' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_sla_breached($this->build_sla_event($ticket->id, $this->agent->id));

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * A breach naming an assignee whose account no longer exists stops there —
     * it must not fall through to the unassigned branch and broadcast the
     * breach to every agent.
     */
    public function test_sla_breach_with_deleted_assignee_does_not_fall_back_to_broadcast(): void {
        $ticket = $this->make_ticket(['assigneeid' => 999999]);
        $sink = $this->redirectMessages();

        observer::ticket_sla_breached($this->build_sla_event($ticket->id, 999999));

        $this->assertSame([], $this->recipients($sink));
        $sink->close();
    }

    /**
     * Suspended accounts are skipped. Somebody who has left the organisation
     * should stop receiving ticket traffic without having to be deleted.
     */
    public function test_suspended_recipient_is_skipped(): void {
        global $DB;

        $ticket = $this->make_ticket();
        $DB->set_field('user', 'suspended', 1, ['id' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_created($this->build_created_event($ticket->id, $this->requester->id));

        $this->assertSame([(int) $this->agenttwo->id], $this->recipients($sink));
        $sink->close();
    }

    /**
     * Deleted accounts are skipped.
     */
    public function test_deleted_recipient_is_skipped(): void {
        global $DB;

        $ticket = $this->make_ticket();
        $DB->set_field('user', 'deleted', 1, ['id' => $this->agent->id]);
        $sink = $this->redirectMessages();

        observer::ticket_created($this->build_created_event($ticket->id, $this->requester->id));

        $this->assertSame([(int) $this->agenttwo->id], $this->recipients($sink));
        $sink->close();
    }

    // Regression: partial user records handed to message_send().

    /**
     * Regression test: every notification observer must hand message_send() a
     * fully-loaded user record. Otherwise Moodle raises
     * "Necessary properties missing in userto object, fetching full record"
     * in developer mode every time a notification fires.
     *
     * This one deliberately drives the real service path — it is guarding the
     * shape of the record that reaches message_send() in production, so
     * bypassing the services would defeat its purpose.
     */
    public function test_observers_do_not_emit_userto_missing_properties_warning(): void {
        set_debugging(DEBUG_DEVELOPER, true);

        // Use a message sink so message_send() succeeds without trying to talk
        // to mail/push transports during unit tests.
        $sink = $this->redirectMessages();

        try {
            // 1. ticket_created → notify_agents_new fires for every agent.
            $ticketid = $this->create_basic_ticket();

            // 2. ticket_replied → notify_requester_reply fires (agent replies on
            // the requester's ticket).
            $reply = new \stdClass();
            $reply->message = 'Looking into it.';
            $reply->messageformat = FORMAT_PLAIN;
            $this->replyservice->add_public_reply($ticketid, $reply, $this->agent->id);

            // 3. ticket_assigned → notify_assignee_assign fires.
            $this->ticketservice->assign($ticketid, $this->agent->id, $this->agent->id);
        } finally {
            $sink->close();
        }

        $debugmessages = $this->getDebuggingMessages();
        foreach ($debugmessages as $msg) {
            $text = is_object($msg) ? ($msg->message ?? '') : (string) $msg;
            $this->assertStringNotContainsString(
                'Necessary properties missing in userto object',
                $text,
                'message_send() received a partial user record: ' . $text
            );
        }
        $this->resetDebugging();

        // Sanity check: the observer pipeline really ran message_send() — if
        // the sink is empty the test never exercised the code path under guard.
        $this->assertGreaterThan(
            0,
            $sink->count(),
            'No notifications were sent — observer guards likely short-circuited and the test is not exercising message_send().'
        );
    }

    // Helpers.

    /**
     * Insert a ticket row directly.
     *
     * Bypassing ticket_service keeps each test's starting state explicit and
     * avoids firing the very events under test as a side effect of setup.
     *
     * @param array $overrides Column values to override on the default row.
     * @return \stdClass The inserted record.
     */
    private function make_ticket(array $overrides = []): \stdClass {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'userid' => $this->requester->id,
            'createdby' => $this->requester->id,
            'assigneeid' => null,
            'categoryid' => $this->categoryid,
            'subject' => 'Observer coverage ticket',
            'status' => ticket::STATUS_NEW,
            'priority' => 1,
            'source' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'sla_breached' => 0,
            'usermodified' => $this->requester->id,
        ], $overrides);

        $record->id = (int) $DB->insert_record('local_moodesk_ticket', $record);

        return $record;
    }

    /*
     * -------------------------------------------------------------------------
     * Ticket merge (F3)
     * -------------------------------------------------------------------------
     */

    /**
     * Build a ticket_created event without triggering it.
     *
     * @param int $ticketid
     * @param int $actorid User the event is attributed to.
     * @return \local_moodesk\event\ticket_created
     */
    private function build_created_event(int $ticketid, int $actorid): \local_moodesk\event\ticket_created {
        return \local_moodesk\event\ticket_created::create([
            'objectid' => $ticketid,
            'context' => \context_system::instance(),
            'userid' => $actorid,
        ]);
    }

    /**
     * Build a ticket_replied event without triggering it.
     *
     * @param int $ticketid
     * @param int $actorid Author of the reply.
     * @param int|null $type reply::TYPE_* constant; defaults to a public reply.
     * @return \local_moodesk\event\ticket_replied
     */
    private function build_replied_event(
        int $ticketid,
        int $actorid,
        ?int $type = null
    ): \local_moodesk\event\ticket_replied {
        return \local_moodesk\event\ticket_replied::create([
            'objectid' => $ticketid,
            'context' => \context_system::instance(),
            'userid' => $actorid,
            'other' => [
                'replyid' => 1,
                'type' => $type ?? reply::TYPE_PUBLIC_REPLY,
            ],
        ]);
    }

    /**
     * Build a ticket_assigned event without triggering it.
     *
     * @param int $ticketid
     * @param int|null $newassigneeid Null means unassignment.
     * @param int $actorid User performing the assignment.
     * @param int|null $oldassigneeid
     * @return \local_moodesk\event\ticket_assigned
     */
    private function build_assigned_event(
        int $ticketid,
        ?int $newassigneeid,
        int $actorid,
        ?int $oldassigneeid = null
    ): \local_moodesk\event\ticket_assigned {
        return \local_moodesk\event\ticket_assigned::create([
            'objectid' => $ticketid,
            'context' => \context_system::instance(),
            'userid' => $actorid,
            'other' => [
                'oldassigneeid' => $oldassigneeid,
                'newassigneeid' => $newassigneeid,
            ],
        ]);
    }

    /**
     * Build a ticket_sla_breached event without triggering it.
     *
     * @param int $ticketid
     * @param int $assigneeid Assignee user ID, or 0 when unassigned.
     * @return \local_moodesk\event\ticket_sla_breached
     */
    private function build_sla_event(int $ticketid, int $assigneeid): \local_moodesk\event\ticket_sla_breached {
        return \local_moodesk\event\ticket_sla_breached::create([
            'objectid' => $ticketid,
            'context' => \context_system::instance(),
            'userid' => $assigneeid ?: $this->requester->id,
            'other' => [
                'priority' => 1,
                'assigneeid' => $assigneeid,
            ],
        ]);
    }

    /**
     * Build a ticket_status_changed event without triggering it.
     *
     * @param int $ticketid
     * @param int $newstatus ticket::STATUS_* constant.
     * @param int|null $oldstatus
     * @return \local_moodesk\event\ticket_status_changed
     */
    private function build_status_event(
        int $ticketid,
        int $newstatus,
        ?int $oldstatus = null
    ): \local_moodesk\event\ticket_status_changed {
        return \local_moodesk\event\ticket_status_changed::create([
            'objectid' => $ticketid,
            'context' => \context_system::instance(),
            'userid' => $this->agent->id,
            'other' => [
                'oldstatus' => $oldstatus ?? ticket::STATUS_NEW,
                'newstatus' => $newstatus,
            ],
        ]);
    }

    /**
     * Recipient user IDs captured by a message sink.
     *
     * Asserting on who was notified rather than on a message count keeps the
     * tests readable and makes a failure name the wrong recipient directly.
     *
     * @param \phpunit_message_sink $sink
     * @return int[]
     */
    private function recipients(\phpunit_message_sink $sink): array {
        return array_map(
            static fn($message) => (int) $message->useridto,
            $sink->get_messages()
        );
    }

    /**
     * Create a ticket through the real service, firing the real events.
     *
     * @return int Ticket ID.
     */
    private function create_basic_ticket(): int {
        $data = new \stdClass();
        $data->userid = $this->requester->id;
        $data->subject = 'Observer userto regression';
        $data->message = 'Body.';
        $data->messageformat = FORMAT_PLAIN;

        $result = $this->ticketservice->create_ticket($data, $this->requester->id);
        return (int) $result['ticketid'];
    }
}
