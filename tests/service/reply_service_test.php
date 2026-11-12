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
 * Integration tests for the reply application service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;

/**
 * Tests for reply_service.
 *
 * @covers \local_moodesk\service\reply_service
 */
final class reply_service_test extends \advanced_testcase {
    /** @var reply_service */
    private reply_service $replyservice;

    /** @var ticket_service */
    private ticket_service $ticketservice;

    /** @var \stdClass Requester (plain authenticated user). */
    private \stdClass $requester;

    /** @var \stdClass Agent (mhd_agent role). */
    private \stdClass $agent;

    /** @var \stdClass Plain user who neither owns the ticket nor is an agent. */
    private \stdClass $stranger;

    /** @var int Default category id seeded for tickets. */
    private int $categoryid;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->replyservice  = reply_service::create();
        $this->ticketservice = ticket_service::create();

        // Default category required by ticket_service::create_ticket().
        $this->categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$this->categoryid) {
            $this->categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid'    => null,
                'name'        => 'General',
                'sortorder'   => 0,
                'visible'     => 1,
                'timecreated' => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $this->categoryid, 'local_moodesk');

        // Silence outbound notifications — they go through message_send(),
        // which has its own (independent) "Necessary properties missing in
        // userto object" debugging surface and would mask the fullname()
        // signal we are guarding here.
        set_config('notify_agents_new', 0, 'local_moodesk');
        set_config('notify_requester_reply', 0, 'local_moodesk');
        set_config('notify_assignee_assign', 0, 'local_moodesk');
        set_config('notify_sla_breach', 0, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent     = $this->getDataGenerator()->create_user();
        $this->stranger  = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $agentroleid   = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($agentroleid, $this->agent->id, $systemcontext->id);

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Regression test: get_thread() must select every name field that fullname()
     * uses, otherwise developer mode raises "The following name fields are missing
     * from the user object: firstnamephonetic, lastnamephonetic, middlename,
     * alternatename" — which broke ticket view pages in dev environments.
     *
     * We scope the assertion to the exact fullname() warning so the test stays
     * green even if unrelated observers raise their own debugging during the
     * triggering events.
     */
    public function test_get_thread_does_not_emit_fullname_name_fields_warning(): void {
        set_debugging(DEBUG_DEVELOPER, true);

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        // An agent reply ensures the thread has more than the auto-created
        // requester reply, and forces another fullname() decoration pass.
        $reply           = new \stdClass();
        $reply->message  = 'Looking into it.';
        $reply->messageformat = FORMAT_PLAIN;
        $this->replyservice->add_public_reply($ticketid, $reply, $this->agent->id);

        // Drain any debugging emitted by the triggering events so the
        // post-get_thread() check observes only get_thread()'s own output.
        $this->resetDebugging();

        $thread = $this->replyservice->get_thread($ticketid, $this->agent->id);

        $this->assert_no_name_fields_warning();

        // Sanity check: the decoration actually happened.
        $this->assertNotEmpty($thread);
        foreach ($thread as $row) {
            // Using property_exists() rather than assertObjectHasProperty(), which
            // only exists in the PHPUnit shipped with Moodle 4.5+ (the 4.1/4.3
            // CI matrix runs an older PHPUnit without it).
            $this->assertTrue(property_exists($row, 'authorname'));
            $this->assertTrue(property_exists($row, 'authorid'));
            $this->assertNotSame('', $row->authorname);
        }
    }

    /**
     * Same regression guard for users that have the phonetic / middle / alternate
     * name columns actually populated — the warning fires regardless of value,
     * but populating them documents intent and protects against a future
     * implementation that decides to read those fields.
     */
    public function test_get_thread_does_not_emit_fullname_name_fields_warning_for_phonetic_user(): void {
        global $DB;

        set_debugging(DEBUG_DEVELOPER, true);

        // Populate all the name fields fullname() looks at.
        $DB->update_record('user', (object) [
            'id'                => $this->agent->id,
            'firstnamephonetic' => 'Aɡənt',
            'lastnamephonetic'  => 'Nɛɪm',
            'middlename'        => 'Middle',
            'alternatename'     => 'Alt Name',
        ]);

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $reply           = new \stdClass();
        $reply->message  = 'Reply with phonetic author.';
        $reply->messageformat = FORMAT_PLAIN;
        $this->replyservice->add_public_reply($ticketid, $reply, $this->agent->id);

        $this->resetDebugging();

        $this->replyservice->get_thread($ticketid, $this->agent->id);

        $this->assert_no_name_fields_warning();
    }

    /**
     * Assert that no captured debugging message contains the "name fields are
     * missing from the user object" string — the exact phrase fullname() emits.
     */
    private function assert_no_name_fields_warning(): void {
        $messages = $this->getDebuggingMessages();
        $needle   = 'name fields are missing from the user object';

        foreach ($messages as $msg) {
            $text = is_object($msg) ? ($msg->message ?? '') : (string) $msg;
            $this->assertStringNotContainsString(
                $needle,
                $text,
                'fullname() warned about missing name fields: ' . $text
            );
        }
    }

    /**
     * Helper — create a ticket the same way ticket_service_test does, so this
     * file does not couple to private repo internals.
     *
     * @param int $requesterid User the ticket is raised for.
     */
    private function create_basic_ticket_for(int $requesterid): int {
        $data                = new \stdClass();
        $data->userid        = $requesterid;
        $data->subject       = 'Reply thread regression';
        $data->message       = 'Body.';
        $data->messageformat = FORMAT_PLAIN;

        $result = $this->ticketservice->create_ticket($data, $requesterid);
        return (int) $result['ticketid'];
    }

    /**
     * Build a reply payload.
     *
     * @param string $message
     * @return \stdClass
     */
    private function payload(string $message): \stdClass {
        $data                = new \stdClass();
        $data->message       = $message;
        $data->messageformat = FORMAT_HTML;
        return $data;
    }

    /**
     * Insert a system-event reply directly (no public creator path exists) so the
     * visibility tests can assert it is shown to both roles.
     *
     * @param int $ticketid
     * @return int
     */
    private function insert_system_event(int $ticketid): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('local_moodesk_reply', (object) [
            'ticketid'      => $ticketid,
            'userid'        => $this->agent->id,
            'message'       => 'Status changed to in progress.',
            'messageformat' => FORMAT_PLAIN,
            'type'          => reply::TYPE_SYSTEM_EVENT,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'usermodified'  => $this->agent->id,
        ]);
    }

    /**
     * A public agent reply persists as TYPE_PUBLIC_REPLY and notifies the requester.
     */
    public function test_public_reply_persists_and_notifies_requester(): void {
        global $DB;
        set_config('notify_requester_reply', 1, 'local_moodesk');
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $sink     = $this->redirectMessages();
        $replyid  = $this->replyservice->add_public_reply($ticketid, $this->payload('Here is the fix.'), $this->agent->id);
        $messages = $sink->get_messages();
        $sink->close();
        $this->resetDebugging();

        $this->assertSame(
            reply::TYPE_PUBLIC_REPLY,
            (int) $DB->get_field('local_moodesk_reply', 'type', ['id' => $replyid], MUST_EXIST)
        );
        $torequester = array_filter($messages, fn($m) => (int) $m->useridto === (int) $this->requester->id);
        $this->assertCount(1, $torequester);
    }

    /**
     * An internal note persists as TYPE_INTERNAL_NOTE and sends NO notification to
     * the requester — even with requester notifications enabled, the type guard
     * (observer::ticket_replied) must suppress it. This is the dangerous leak path.
     */
    public function test_internal_note_persists_and_does_not_notify_requester(): void {
        global $DB;
        set_config('notify_requester_reply', 1, 'local_moodesk');
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $sink     = $this->redirectMessages();
        $replyid  = $this->replyservice->add_internal_note($ticketid, $this->payload('Caller is abusive.'), $this->agent->id);
        $messages = $sink->get_messages();
        $sink->close();
        $this->resetDebugging();

        $this->assertSame(
            reply::TYPE_INTERNAL_NOTE,
            (int) $DB->get_field('local_moodesk_reply', 'type', ['id' => $replyid], MUST_EXIST)
        );
        $torequester = array_filter($messages, fn($m) => (int) $m->useridto === (int) $this->requester->id);
        $this->assertCount(0, $torequester);
    }

    /**
     * A requester's thread NEVER contains an internal note: only public replies
     * and system events on their own ticket.
     */
    public function test_requester_thread_excludes_internal_notes(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $this->replyservice->add_public_reply($ticketid, $this->payload('Public answer.'), $this->agent->id);
        $noteid   = $this->replyservice->add_internal_note($ticketid, $this->payload('Secret note.'), $this->agent->id);
        $systemid = $this->insert_system_event($ticketid);
        $this->resetDebugging();

        $thread = $this->replyservice->get_thread($ticketid, $this->requester->id);

        $types = array_unique(array_map(static fn($r) => (int) $r->type, $thread));
        $ids   = array_map(static fn($r) => (int) $r->id, $thread);

        $this->assertNotContains(reply::TYPE_INTERNAL_NOTE, $types, 'Requester thread must never expose internal notes.');
        $this->assertNotContains($noteid, $ids, 'The internal note id leaked into the requester thread.');
        $this->assertContains($systemid, $ids, 'System events should be visible to the requester.');
        // Only requester-visible types are present.
        $this->assertEmpty(array_diff($types, reply::requester_visible_types()));
    }

    /**
     * An agent's thread contains all three reply types, including internal notes.
     */
    public function test_agent_thread_includes_all_types(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $this->replyservice->add_public_reply($ticketid, $this->payload('Public answer.'), $this->agent->id);
        $noteid   = $this->replyservice->add_internal_note($ticketid, $this->payload('Secret note.'), $this->agent->id);
        $systemid = $this->insert_system_event($ticketid);
        $this->resetDebugging();

        $thread = $this->replyservice->get_thread($ticketid, $this->agent->id);

        $types = array_unique(array_map(static fn($r) => (int) $r->type, $thread));
        $ids   = array_map(static fn($r) => (int) $r->id, $thread);

        $this->assertContains(reply::TYPE_PUBLIC_REPLY, $types);
        $this->assertContains(reply::TYPE_INTERNAL_NOTE, $types);
        $this->assertContains(reply::TYPE_SYSTEM_EVENT, $types);
        $this->assertContains($noteid, $ids);
        $this->assertContains($systemid, $ids);
    }

    /**
     * A user who neither owns the ticket nor holds :viewall is denied the thread.
     */
    public function test_thread_denied_for_unrelated_user(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\required_capability_exception::class);
        $this->replyservice->get_thread($ticketid, $this->stranger->id);
    }

    /**
     * Without :reply (and not owning the ticket) a user cannot post a public reply.
     */
    public function test_public_reply_denied_without_reply_capability(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\required_capability_exception::class);
        $this->replyservice->add_public_reply($ticketid, $this->payload('Not allowed.'), $this->stranger->id);
    }

    /**
     * Without :addnote a user cannot post an internal note — including the
     * requester on their own ticket.
     */
    public function test_internal_note_denied_without_addnote_capability(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\required_capability_exception::class);
        $this->replyservice->add_internal_note($ticketid, $this->payload('Not allowed.'), $this->requester->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Reach is part of the rule, not something the caller is trusted to have
     * checked. Every one of these was granted before SEC-03: the service asked
     * for a capability and never for visibility, and it held only because each
     * entry point ran get_ticket() first. The email pipeline did not.
     * -------------------------------------------------------------------------
     */

    /**
     * A user holding exactly the capabilities named.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    private function user_holding(array $capabilities): \stdClass {
        $context = \context_system::instance();
        $user    = $this->getDataGenerator()->create_user();
        $roleid  = $this->getDataGenerator()->create_role();

        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    public function test_public_reply_denied_with_reply_capability_but_no_visibility(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $halfagent = $this->user_holding(['local/moodesk:reply']);

        $this->expectException(\required_capability_exception::class);
        $this->replyservice->add_public_reply($ticketid, $this->payload('Not allowed.'), (int) $halfagent->id);
    }

    public function test_internal_note_denied_with_addnote_capability_but_no_visibility(): void {
        $ticketid  = $this->create_basic_ticket_for($this->requester->id);
        $halfagent = $this->user_holding(['local/moodesk:addnote']);

        $this->expectException(\required_capability_exception::class);
        $this->replyservice->add_internal_note($ticketid, $this->payload('Not allowed.'), (int) $halfagent->id);
    }

    /**
     * The email path's own backstop. The pipeline refuses an unauthorised
     * sender terminally before reaching this method, so this asserts the method
     * cannot be reintroduced as a way around the policy by some future caller.
     */
    public function test_a_reply_from_email_is_denied_for_an_unauthorised_sender(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $data           = new \stdClass();
        $data->ticketid = $ticketid;
        $data->message  = 'Posted by naming a Message-ID.';

        $this->expectException(\required_capability_exception::class);
        $this->replyservice->add_reply_from_email($data, (int) $this->stranger->id);
    }

    public function test_a_reply_from_email_is_allowed_for_the_requester(): void {
        global $DB;
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $data           = new \stdClass();
        $data->ticketid = $ticketid;
        $data->message  = 'Answering my own ticket.';

        $replyid = $this->replyservice->add_reply_from_email($data, (int) $this->requester->id);

        $this->assertGreaterThan(0, $replyid);
        $this->assertEquals(2, $DB->count_records('local_moodesk_reply', ['ticketid' => $ticketid]));
    }

    public function test_a_reply_from_email_is_allowed_for_an_agent_who_can_reach_the_ticket(): void {
        global $DB;
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $data           = new \stdClass();
        $data->ticketid = $ticketid;
        $data->message  = 'Answering as an agent.';

        $replyid = $this->replyservice->add_reply_from_email($data, (int) $this->agent->id);

        $this->assertGreaterThan(0, $replyid);
        $this->assertEquals(2, $DB->count_records('local_moodesk_reply', ['ticketid' => $ticketid]));
    }

    /**
     * The first public agent reply stamps timefirstresponse on the ticket.
     */
    public function test_first_agent_public_reply_sets_timefirstresponse(): void {
        global $DB;
        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $this->assertNull($DB->get_field('local_moodesk_ticket', 'timefirstresponse', ['id' => $ticketid], MUST_EXIST));

        $this->replyservice->add_public_reply($ticketid, $this->payload('First agent answer.'), $this->agent->id);
        $this->resetDebugging();

        $this->assertNotEmpty($DB->get_field('local_moodesk_ticket', 'timefirstresponse', ['id' => $ticketid], MUST_EXIST));
    }

    /**
     * An internal note must NOT stamp timefirstresponse — it is not a response to
     * the requester.
     */
    public function test_internal_note_does_not_set_timefirstresponse(): void {
        global $DB;
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->replyservice->add_internal_note($ticketid, $this->payload('Internal only.'), $this->agent->id);
        $this->resetDebugging();

        $this->assertNull($DB->get_field('local_moodesk_ticket', 'timefirstresponse', ['id' => $ticketid], MUST_EXIST));
    }

    /*
     * -------------------------------------------------------------------------
     * The message field must be text
     *
     * A hand-built POST could send `message` as an array. mform hands such a
     * value through for a scalar-typed element, and casting it to string stored
     * the literal "Array" as a reply body. The service documents message as a
     * string; these pin that it enforces it, for every caller and before any
     * write.
     * -------------------------------------------------------------------------
     */

    /**
     * Build a reply payload whose message is not text.
     *
     * @param mixed $message
     * @return \stdClass
     */
    private function raw_payload($message): \stdClass {
        $data                = new \stdClass();
        $data->message       = $message;
        $data->messageformat = FORMAT_PLAIN;
        return $data;
    }

    /**
     * Reply and history rows for one ticket.
     *
     * @param int $ticketid
     * @return int[] [replies, history]
     */
    private function counts_for(int $ticketid): array {
        global $DB;
        return [
            $DB->count_records('local_moodesk_reply', ['ticketid' => $ticketid]),
            $DB->count_records('local_moodesk_ticket_history', ['ticketid' => $ticketid]),
        ];
    }

    public function test_public_reply_accepts_a_plain_string(): void {
        global $DB;
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $replyid = $this->replyservice->add_public_reply($ticketid, $this->payload('Test reply'), (int) $this->agent->id);

        $this->assertSame('Test reply', $DB->get_field('local_moodesk_reply', 'message', ['id' => $replyid]));
    }

    public function test_public_reply_stores_a_multiline_message_verbatim(): void {
        global $DB;
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $message  = "First line\nSecond line\n\nFourth after a blank one";

        $replyid = $this->replyservice->add_public_reply($ticketid, $this->payload($message), (int) $this->agent->id);

        $this->assertSame($message, $DB->get_field('local_moodesk_reply', 'message', ['id' => $replyid]));
    }

    public function test_public_reply_stores_unicode_and_special_characters_verbatim(): void {
        global $DB;
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        // Angle brackets and tags are legitimate support content and must survive:
        // the field is PARAM_RAW in and format_text(FORMAT_PLAIN) out.
        $message = "Olá — prueba ✓ 日本語\nif a < b, use the <div> tag & \"quotes\"";

        $replyid = $this->replyservice->add_public_reply($ticketid, $this->payload($message), (int) $this->agent->id);

        $this->assertSame($message, $DB->get_field('local_moodesk_reply', 'message', ['id' => $replyid]));
    }

    public function test_public_reply_rejects_a_single_element_array_message(): void {
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\invalid_parameter_exception::class);
        $this->replyservice->add_public_reply($ticketid, $this->raw_payload(['foo']), (int) $this->agent->id);
    }

    public function test_public_reply_rejects_a_multi_element_array_message(): void {
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\invalid_parameter_exception::class);
        $this->replyservice->add_public_reply($ticketid, $this->raw_payload(['foo', 'bar']), (int) $this->agent->id);
    }

    public function test_public_reply_rejects_an_associative_array_message(): void {
        // The shape the ticket reported: message[text]=hola.
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\invalid_parameter_exception::class);
        $this->replyservice->add_public_reply($ticketid, $this->raw_payload(['text' => 'hola']), (int) $this->agent->id);
    }

    public function test_internal_note_rejects_an_array_message(): void {
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\invalid_parameter_exception::class);
        $this->replyservice->add_internal_note($ticketid, $this->raw_payload(['foo']), (int) $this->agent->id);
    }

    public function test_a_rejected_message_writes_nothing_at_all(): void {
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        [$replies, $history] = $this->counts_for($ticketid);

        foreach ([['foo'], ['foo', 'bar'], ['text' => 'hola']] as $bad) {
            try {
                $this->replyservice->add_public_reply($ticketid, $this->raw_payload($bad), (int) $this->agent->id);
                $this->fail('An array message should have been rejected.');
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('message', $e->getMessage());
            }
        }

        // No reply, no history entry, and nothing that could have half-written:
        // the guard runs ahead of the transaction and the event.
        $this->assertSame([$replies, $history], $this->counts_for($ticketid));
    }

    public function test_an_empty_message_still_reports_a_missing_parameter(): void {
        // Unchanged semantics: empty is a missing value, not a wrong type.
        $this->setUser($this->agent);
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\moodle_exception::class);
        $this->replyservice->add_public_reply($ticketid, $this->payload('   '), (int) $this->agent->id);
    }
}
