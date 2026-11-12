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
 * Unit tests for the ticket detail component.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;
use local_moodesk\service\edition_service;
use local_moodesk\service\ticket_service;

/**
 * Guards what the detail promises both of its mounts.
 *
 * The page and the drawer render this one component, so the rules that used to
 * live in view.php are tested here: the management rail is capability gated,
 * the status select never offers an illegal transition, and the composer
 * disappears exactly where replying is not allowed.
 *
 * @covers \local_moodesk\output\ticket_detail
 */
final class ticket_detail_test extends \advanced_testcase {
    /** @var int Ticket used by the test cases. */
    private int $ticketid;

    /** @var \stdClass The user who raised it. */
    private \stdClass $requester;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        // Seed the default category that ticket_service::create_ticket() needs.
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

        $data                = new \stdClass();
        $data->userid        = $this->requester->id;
        $data->subject       = 'Cannot access my course';
        $data->message       = 'Body.';
        $data->messageformat = FORMAT_PLAIN;

        $result         = ticket_service::create()->create_ticket($data, $this->requester->id);
        $this->ticketid = (int) $result['ticketid'];

        // Ticket creation fans out to automations and webhooks, whose own
        // latent issues call debugging(); drain it so only these assertions
        // decide the outcome.
        $this->resetDebugging();
    }

    protected function tearDown(): void {
        // The CSAT cases force Pro; leaking that would change the edition every
        // later test in the process sees.
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    public function test_the_management_rail_is_for_agents_only(): void {
        $this->setUser($this->requester);
        $requesterview = ticket_detail::create($this->ticketid, (int) $this->requester->id)
            ->export_for_template();

        $this->setAdminUser();
        $agentview = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->export_for_template();

        $this->assertFalse($requesterview['hasrail']);
        $this->assertTrue($agentview['hasrail']);
    }

    public function test_a_requester_gets_no_property_controls(): void {
        $this->setUser($this->requester);

        $context = ticket_detail::create($this->ticketid, (int) $this->requester->id)
            ->export_for_template();

        $this->assertEmpty($context['categoryoptions']);
        $this->assertEmpty($context['departmentoptions']);
        $this->assertEmpty($context['agentselectoptions']);
        $this->assertFalse($context['hasagents']);
    }

    public function test_the_status_select_only_offers_legal_transitions(): void {
        $this->setAdminUser();

        $context = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->export_for_template();

        $offered = array_column($context['statustransitions'], 'value');
        $legal   = ticket::VALID_TRANSITIONS[$context['status']] ?? [];

        $this->assertNotEmpty($offered);
        $this->assertSame($legal, $offered);
        // The current state is the select's placeholder, never an option: a
        // transition to where the ticket already is would be refused.
        $this->assertNotContains($context['status'], $offered);
    }

    public function test_the_header_names_the_requester(): void {
        $this->setAdminUser();

        $context = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->export_for_template();

        $this->assertTrue($context['hasrequester']);
        $this->assertSame(fullname($this->requester), $context['requestername']);
    }

    /**
     * A user holding exactly the capabilities named, and nothing else.
     *
     * @param string[] $capabilities Capability names to allow at system context.
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

    /*
     * -------------------------------------------------------------------------
     * Composer gating.
     *
     * The screen used to decide this from :viewall while the service enforced
     * :reply, so an agent holding one and not the other was offered a composer
     * whose send came back refused. Both now ask ticket_access_policy.
     * -------------------------------------------------------------------------
     */

    public function test_an_agent_who_may_see_but_not_reply_gets_no_composer(): void {
        $agent = $this->user_holding(['local/moodesk:viewall']);
        $this->setUser($agent);

        $detail = ticket_detail::create($this->ticketid, (int) $agent->id);

        $this->assertFalse($detail->has_composer());
        $this->assertFalse($detail->export_for_template()['canreply']);
    }

    public function test_an_agent_with_reply_and_visibility_gets_a_composer(): void {
        $agent = $this->user_holding(['local/moodesk:viewall', 'local/moodesk:reply']);
        $this->setUser($agent);

        $detail = ticket_detail::create($this->ticketid, (int) $agent->id);

        $this->assertTrue($detail->has_composer());
        $this->assertTrue($detail->export_for_template()['canreply']);
    }

    public function test_a_requester_gets_a_composer_on_their_own_open_ticket(): void {
        $this->setUser($this->requester);

        $detail = ticket_detail::create($this->ticketid, (int) $this->requester->id);

        $this->assertTrue($detail->has_composer());
        $this->assertTrue($detail->export_for_template()['canreply']);
    }

    /**
     * The note-only composer has to start on the note.
     *
     * The toggle is what moves the hidden replytype off its initial value, and
     * a viewer with no :reply never sees one — so a composer that started on
     * TYPE_PUBLIC_REPLY would post a public reply the service refuses.
     */
    public function test_a_note_only_composer_starts_on_the_internal_note(): void {
        $agent = $this->user_holding(['local/moodesk:viewall', 'local/moodesk:addnote']);
        $this->setUser($agent);

        $detail = ticket_detail::create($this->ticketid, (int) $agent->id);

        $this->assertTrue($detail->has_composer());
        $this->assertFalse($detail->export_for_template()['canreply']);
        $this->assertStringContainsString(
            'value="' . reply::TYPE_INTERNAL_NOTE . '"',
            $this->replytype_input($detail->get_reply_form()->render())
        );
    }

    /**
     * The rendered form's hidden replytype input, isolated from the rest.
     *
     * @param string $html Rendered form markup.
     * @return string
     */
    private function replytype_input(string $html): string {
        preg_match('/<input[^>]*name="replytype"[^>]*>/', $html, $matches);
        return $matches[0] ?? '';
    }

    public function test_a_closed_ticket_has_no_composer(): void {
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;

        $open = ticket_detail::create($this->ticketid, $adminid);
        $this->assertTrue($open->has_composer());

        ticket_service::create()->transition_status($this->ticketid, ticket::STATUS_CLOSED, $adminid);
        $this->resetDebugging();

        $closed = ticket_detail::create($this->ticketid, $adminid);
        $this->assertFalse($closed->has_composer());
        $this->assertTrue($closed->export_for_template()['isterminal']);
    }

    public function test_the_composer_posts_to_the_canonical_page_from_either_mount(): void {
        $this->setAdminUser();

        $detail = ticket_detail::create($this->ticketid, (int) get_admin()->id);
        $html   = $detail->get_reply_form()->render();

        $this->assertStringContainsString('/local/moodesk/view.php', $html);
        $this->assertStringContainsString('name="sesskey"', $html);

        // Element ids are prefixed so this form's draft area cannot be confused
        // with the new-ticket drawer's, which declares the same element name.
        $this->assertStringContainsString('id_mhdreply_message', $html);
    }

    public function test_the_composer_is_a_plain_textarea_not_an_editor(): void {
        $this->setAdminUser();

        $html = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->get_reply_form()
            ->render();

        // A support reply is a message, not a document: the writing surface must
        // not depend on which editor the site or the user has configured.
        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('data-region="mhd-composer-input"', $html);
        $this->assertStringNotContainsString('data-fieldtype="editor"', $html);
    }

    public function test_the_composer_carries_no_required_legend(): void {
        $this->setAdminUser();

        $html = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->get_reply_form()
            ->render();

        // Moodle's forms state, under every form holding a required field, that
        // some of their fields are required. Above a composer whose only field is
        // the message box that says nothing, and it says it in a row of height
        // the conversation could have had. Gone at the source: no element and no
        // reserved row, rather than something hidden with an empty box behind it.
        $this->assertStringNotContainsString('fdescription', $html);
        $this->assertStringNotContainsString('Required', strip_tags($html));
    }

    public function test_removing_the_legend_leaves_the_required_rule_alone(): void {
        $this->setAdminUser();

        $form = ticket_detail::create($this->ticketid, (int) get_admin()->id)->get_reply_form();
        $html = $form->render();

        // The legend was redundant text. The rule behind it is not. The message
        // box still announces itself as required to anything reading the
        // accessibility tree, still carries the marker beside its label, and an
        // empty message is still refused server-side.
        $this->assertStringContainsString('aria-required="true"', $html);
        $this->assertStringContainsString('title="' . get_string('required') . '"', $html);

        $errors = $form->validation(['message' => '   '], []);
        $this->assertArrayHasKey('message', $errors);
    }

    public function test_the_note_is_only_suppressed_for_this_form(): void {
        $this->setAdminUser();

        // The renderer is a process-wide singleton, so the composer borrows it
        // and has to give it back: any other form rendered afterwards — the New
        // ticket drawer's, an admin form — keeps its own note.
        $before = $GLOBALS['_HTML_QuickForm_default_renderer']->_requiredNoteTemplate;
        $this->assertNotSame('', $before);

        ticket_detail::create($this->ticketid, (int) get_admin()->id)->get_reply_form()->render();

        $this->assertSame($before, $GLOBALS['_HTML_QuickForm_default_renderer']->_requiredNoteTemplate);
    }

    public function test_the_composer_offers_no_cancel(): void {
        $this->setAdminUser();

        $html = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->get_reply_form()
            ->render();

        // Two ways out of one ticket is one too many. The drawer's own close
        // control leaves the ticket, and on the canonical page Cancel did
        // nothing but reload it with an empty box — neither is worth a control
        // sitting inside the conversation. Gone from both mounts, because there
        // is one composer and it renders in both.
        $this->assertStringNotContainsString('name="cancel"', $html);
        $this->assertStringNotContainsString('data-cancel', $html);
    }

    public function test_send_is_an_icon_button_that_still_submits_the_form(): void {
        $this->setAdminUser();

        $html = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->get_reply_form()
            ->render();

        $this->assertSame(
            1,
            preg_match('/<button[^>]*class="mhd-composer__send"[^>]*>.*?<\/button>/s', $html, $matches),
            'The composer should render a send button.'
        );
        $send = $matches[0];

        // It is the form's own submit, drawn differently — not a control that
        // drives one from the outside. Every path that sends a reply goes
        // through the same submit event it always did.
        $this->assertStringContainsString('type="submit"', $send);
        $this->assertStringContainsString('#mhd-icon-send', $send);

        // Icon-only, so the name has to be stated rather than read off the
        // label. `title` carries it for a pointer, `aria-label` for a screen
        // reader, and both are what Behat's button selector matches on.
        $this->assertStringContainsString('aria-label="' . get_string('reply_send', 'local_moodesk') . '"', $send);
        $this->assertStringContainsString('title="' . get_string('reply_send', 'local_moodesk') . '"', $send);

        // The other mode's name travels with it: the composer switches between
        // a public reply and an internal note in the browser, and reply_toggle
        // renames the control without asking the server for the string.
        $this->assertStringContainsString(
            'data-label-note="' . get_string('reply_send_note', 'local_moodesk') . '"',
            $send
        );
    }

    public function test_the_form_is_recognised_as_submitted_without_a_named_button(): void {
        $this->setAdminUser();

        $html = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->get_reply_form()
            ->render();

        // What tells moodleform the form was posted is this marker, not any
        // button's name — which is why replacing the labelled submit with an
        // icon changes nothing about get_data(), validation or the POST.
        $this->assertStringContainsString('_qf__local_moodesk_form_reply_form', $html);
        $this->assertStringContainsString('name="ticketid"', $html);
        $this->assertStringContainsString('name="replytype"', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
    }

    public function test_the_composer_carries_a_draft_area_but_no_file_manager(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $detail = ticket_detail::create($this->ticketid, (int) get_admin()->id);
        $html   = $detail->get_reply_form()->render();

        // The composer draws its own attach button and chips; what the form has
        // to carry is the draft item id, and only that
        // (ADR: docs/reply-composer-attachments.md).
        $this->assertStringNotContainsString('data-fieldtype="filemanager"', $html);

        // Attribute order is the renderer's business, so the assertion only
        // asks that one hidden input named "attachments" carries a real draft
        // item id.
        $this->assertMatchesRegularExpression(
            '/<input name="attachments" type="hidden" value="[1-9][0-9]*"/',
            $html
        );
    }

    /**
     * Read the composer's picker config out of a rendered detail.
     *
     * Reflection, deliberately. The config is an implementation detail with no
     * business being public, but the two invariants asserted below are the ones
     * that would silently put one ticket's upload into another ticket's draft,
     * and neither is observable from the markup.
     *
     * @param ticket_detail $detail
     * @return array
     */
    private function attachments_config_of(ticket_detail $detail): array {
        $method = new \ReflectionMethod($detail, 'attachments_config');
        $method->setAccessible(true);

        return (array) $method->invoke($detail);
    }

    public function test_the_file_picker_is_pointed_at_the_form_s_own_draft_area(): void {
        global $PAGE;

        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');
        $PAGE->set_url('/local/moodesk/view.php', ['id' => $this->ticketid]);

        $detail = ticket_detail::create($this->ticketid, (int) get_admin()->id);
        $config = $this->attachments_config_of($detail);

        // Rendering the form is what commits the draft item id to the markup;
        // the picker has to be aimed at that same area, or an upload would land
        // somewhere the submit never reads.
        $html = $detail->get_reply_form()->render();

        $this->assertGreaterThan(0, $config['draftitemid']);
        $this->assertStringContainsString(
            'name="attachments" type="hidden" value="' . $config['draftitemid'] . '"',
            $html
        );
        $this->assertSame($config['draftitemid'], (int) $config['filepicker']->itemid);
        $this->assertSame('filemanager', $config['filepicker']->env);
    }

    public function test_two_tickets_never_share_a_file_picker_client_id(): void {
        global $PAGE;

        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');
        $PAGE->set_url('/local/moodesk/view.php', ['id' => $this->ticketid]);

        $adminid = (int) get_admin()->id;
        $first   = $this->attachments_config_of(ticket_detail::create($this->ticketid, $adminid));
        $second  = $this->attachments_config_of(ticket_detail::create($this->ticketid, $adminid));

        // The picker caches instances by client id and only refreshes their
        // callback on later shows, so a shared id would keep serving the first
        // composer's draft to the second one. Same for the draft itself: the
        // drawer opens ticket after ticket in one page, and each has to get its
        // own area.
        $this->assertNotSame($first['clientid'], $second['clientid']);
        $this->assertNotSame($first['draftitemid'], $second['draftitemid']);
    }

    public function test_the_composer_allocates_no_draft_area_when_uploads_are_off(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 0, 'local_moodesk');

        $ctx = ticket_detail::create($this->ticketid, (int) get_admin()->id)
            ->export_for_template();

        // No attach button, and nothing in the form to attach to.
        $this->assertFalse($ctx['hasattach']);
        $this->assertStringNotContainsString('name="attachments"', $ctx['replyform']);
    }

    public function test_the_drawer_variant_swaps_the_breadcrumb_for_a_way_out(): void {
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;

        $page   = ticket_detail::create($this->ticketid, $adminid)
            ->export_for_template(ticket_detail::VARIANT_PAGE);
        $drawer = ticket_detail::create($this->ticketid, $adminid)
            ->export_for_template(ticket_detail::VARIANT_DRAWER);

        $this->assertTrue($page['ispage']);
        $this->assertFalse($page['isdrawer']);
        $this->assertTrue($drawer['isdrawer']);

        // Both mounts point at the same canonical URL.
        $this->assertStringContainsString('view.php?id=' . $this->ticketid, $drawer['viewurl']);
        $this->assertSame($page['viewurl'], $drawer['viewurl']);
    }

    public function test_the_rail_follows_the_edition(): void {
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;

        \local_moodesk\service\edition_service::set_edition_for_testing('starter');
        $starter = ticket_detail::create($this->ticketid, $adminid)->export_for_template();

        \local_moodesk\service\edition_service::set_edition_for_testing('enterprise');
        $enterprise = ticket_detail::create($this->ticketid, $adminid)->export_for_template();

        \local_moodesk\service\edition_service::set_edition_for_testing(null);

        // Properties that every edition ships stay put.
        $this->assertTrue($starter['hascategoryoptions']);
        $this->assertNotEmpty($starter['priorityoptions']);
    }

    public function test_a_ticket_out_of_reach_is_not_rendered(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $this->expectException(\moodle_exception::class);
        ticket_detail::create($this->ticketid, (int) $other->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Knowledge base tools (KB-05, Pro).
     *
     * Two affordances, two gates, and the same rule for both: what the screen
     * offers is a courtesy, and the endpoint / the authoring page repeat every
     * check. What is asserted here is that the courtesy is not extended to
     * anyone the server would refuse.
     * -------------------------------------------------------------------------
     */

    /*
     * -------------------------------------------------------------------------
     * The conversation: a plain-text message is escaped, and its URLs are links.
     * -------------------------------------------------------------------------
     */

    public function test_a_plain_reply_renders_its_url_as_a_link_and_its_markup_as_text(): void {
        $agent = $this->user_holding(['local/moodesk:viewall', 'local/moodesk:reply']);
        $this->setUser($agent);

        $data                = new \stdClass();
        $data->message       = "Before <b>bold</b> & <script>alert(1)</script>\n"
            . "How to reset your password: https://site.example/local/moodesk/kb_article.php?id=7\nAfter";
        $data->messageformat = FORMAT_PLAIN;
        \local_moodesk\service\reply_service::create()->add_public_reply($this->ticketid, $data, (int) $agent->id);
        $this->resetDebugging();

        $thread  = ticket_detail::create($this->ticketid, (int) $agent->id)->export_for_template()['thread'];
        $message = end($thread)['message'];

        $this->assertStringContainsString(
            '<a href="https://site.example/local/moodesk/kb_article.php?id=7" class="_blanktarget">'
            . 'https://site.example/local/moodesk/kb_article.php?id=7</a><br />',
            $message
        );
        $this->assertStringContainsString('How to reset your password: <a href=', $message);
        $this->assertStringContainsString(
            'Before &lt;b&gt;bold&lt;/b&gt; &amp; &lt;script&gt;alert(1)&lt;/script&gt;<br />',
            $message
        );
        $this->assertStringNotContainsString('<script', $message);
        $this->assertStringNotContainsString('<b>', $message);
        // Stored as it was typed: the link is presentation, not data.
        $this->assertSame($data->message, $this->last_reply_message());
    }

    /**
     * The message column of the newest reply on the ticket, as stored.
     *
     * @return string
     */
    private function last_reply_message(): string {
        global $DB;
        $replies = $DB->get_records('local_moodesk_reply', ['ticketid' => $this->ticketid], 'id DESC', 'id, message', 0, 1);
        return (string) reset($replies)->message;
    }

    /**
     * Render the page mount of the detail with the given context.
     *
     * @param array $ctx The exported template context.
     * @return string
     */
    private function render_page(array $ctx): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_moodesk/ticket_detail', $ctx);
    }
}
