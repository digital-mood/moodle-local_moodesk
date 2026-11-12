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
 * Unit tests for the new ticket drawer helper.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

/**
 * Guards what the drawer promises the rest of the product: it is capability
 * gated, and the form it builds posts to submit.php so sesskey, server-side
 * validation and the success redirect stay on the existing page.
 *
 * @covers \local_moodesk\output\new_ticket_drawer
 */
final class new_ticket_drawer_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_create_returns_null_without_the_submit_capability(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'local/moodesk:submit',
            CAP_PROHIBIT,
            $roleid,
            \context_system::instance()->id,
            true
        );

        $this->assertNull(new_ticket_drawer::create());
    }

    public function test_create_builds_the_form_for_a_user_who_may_submit(): void {
        $this->setAdminUser();

        $drawer = new_ticket_drawer::create();

        $this->assertNotNull($drawer);
        $this->assertInstanceOf(\local_moodesk\form\submit_ticket_form::class, $drawer->get_form());
    }

    public function test_the_form_posts_to_submit_php(): void {
        $this->setAdminUser();

        $html = new_ticket_drawer::create()->get_form()->render();

        $this->assertStringContainsString('/local/moodesk/submit.php', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
    }

    public function test_a_course_context_is_carried_through_the_form(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $html   = new_ticket_drawer::create($course->id)->get_form()->render();

        $this->assertMatchesRegularExpression(
            '/name="courseid"[^>]*value="' . $course->id . '"/',
            $html
        );
    }

    public function test_the_drawer_labels_its_own_cta_without_changing_the_page(): void {
        $this->setAdminUser();

        $drawer = new_ticket_drawer::create()->get_form()->render();
        $page   = new_ticket_drawer::create(0, get_string('ticket_submit', 'local_moodesk'))
            ->get_form()->render();

        $this->assertStringContainsString(
            'value="' . get_string('ticket_create', 'local_moodesk') . '"',
            $drawer
        );
        $this->assertStringContainsString(
            'value="' . get_string('ticket_submit', 'local_moodesk') . '"',
            $page
        );
    }

    public function test_attachment_options_follow_the_plugin_settings(): void {
        $this->setAdminUser();

        set_config('attachments_enabled', 1, 'local_moodesk');
        set_config('max_files_per_attachment', 3, 'local_moodesk');

        $drawer = new_ticket_drawer::create();

        $this->assertTrue($drawer->attachments_enabled());
        $this->assertSame(3, $drawer->get_attachment_options()['maxfiles']);
    }

    /**
     * The message box is a plain textarea, not whichever editor the site has
     * configured — the same box the reply composer writes into.
     */
    public function test_the_message_is_a_plain_textarea(): void {
        $this->setAdminUser();

        $html = new_ticket_drawer::create()->get_form()->render();

        $this->assertMatchesRegularExpression('/<textarea[^>]*name="message"/', $html);
        // The editor element named its field message_editor and shipped a
        // toolbar with it; neither should survive anywhere in the form.
        $this->assertStringNotContainsString('message_editor', $html);
        $this->assertStringNotContainsString('editor_atto', $html);
        $this->assertStringNotContainsString('editor_tiny', $html);
    }

    /**
     * Attachments are a hidden draft item id and nothing else. The widget is
     * gone; the contract with file_save_draft_area_files() is not.
     */
    public function test_attachments_are_a_hidden_draft_itemid_not_a_file_manager(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $html = new_ticket_drawer::create()->get_form()->render();

        // Lookaheads rather than a fixed order: moodleform emits the attributes
        // in whatever order the element built them, and that is not a contract.
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*name="attachments")(?=[^>]*type="hidden")[^>]*value="[1-9][0-9]*"/',
            $html
        );
        // What the widget leaves in the markup, rather than the word itself: the
        // picker options the attach control carries name `filemanager` as their
        // environment, and always did — that is the env core's own file manager
        // opens the picker in.
        $this->assertStringNotContainsString('data-fieldtype="filemanager"', $html);
        $this->assertStringNotContainsString('id="filemanager-', $html);
        $this->assertStringNotContainsString('fp-content', $html);
    }

    /**
     * With uploads off site-wide there is no field, no draft area and no picker
     * config — the endpoints refuse the scope too (ajax.php).
     */
    public function test_nothing_about_attachments_renders_when_uploads_are_disabled(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 0, 'local_moodesk');

        $drawer = new_ticket_drawer::create();

        $this->assertNull($drawer->attachments_config());
        $this->assertStringNotContainsString('name="attachments"', $drawer->get_form()->render());
    }

    /**
     * The picker config and the form's hidden field must name the same draft
     * area: that is what makes an upload land where the submit will read it.
     */
    public function test_the_picker_and_the_form_name_the_same_draft_area(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');
        set_config('max_files_per_attachment', 3, 'local_moodesk');

        $drawer = new_ticket_drawer::create();
        $config = $drawer->attachments_config();
        $html   = $drawer->get_form()->render();

        $this->assertGreaterThan(0, $config['draftitemid']);
        $this->assertSame($config['draftitemid'], (int) $config['filepicker']->itemid);
        $this->assertMatchesRegularExpression(
            '/name="attachments"[^>]*value="' . $config['draftitemid'] . '"/',
            $html
        );
        $this->assertSame(3, $config['maxfiles']);
        // No ticket exists yet; the endpoint reads this as the new-ticket scope
        // and gates on :submit rather than on a ticket's own visibility.
        $this->assertSame(0, $config['ticketid']);
    }

    /**
     * Two composers can share a screen — this drawer and the ticket detail
     * drawer's reply. The picker caches instances by client id and only
     * refreshes their callback on later shows, so a shared id would upload one
     * composer's file into the other's draft.
     */
    public function test_each_render_gets_its_own_picker_client_id(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $first  = new_ticket_drawer::create()->attachments_config();
        $second = new_ticket_drawer::create()->attachments_config();

        $this->assertNotSame($first['clientid'], $second['clientid']);
        // Same reason, one level up: the module is handed the id of the region
        // it owns, so two attachment regions on one screen stay separable.
        $this->assertNotSame($first['rootid'], $second['rootid']);
    }

    /**
     * The attach control is on screen, it says what it does in words, and the
     * region the module wires is the one the config names.
     */
    public function test_the_form_renders_an_attach_control(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $drawer = new_ticket_drawer::create();
        $config = $drawer->attachments_config();
        $html   = $drawer->get_form()->render();

        $this->assertStringContainsString('data-region="mhd-composer-attachments"', $html);
        $this->assertStringContainsString('id="' . $config['rootid'] . '"', $html);
        $this->assertStringContainsString('data-action="mhd-attach-file"', $html);
        // The module wires from what the element carries, so the draft the
        // picker uploads into has to be on the element.
        $this->assertMatchesRegularExpression(
            '/data-config="[^"]*draftitemid[^"]*' . $config['draftitemid'] . '/',
            $html
        );
        // The paperclip is decoration; the word is the meaning. A control that
        // only draws an icon names nothing for a screen reader and little for
        // anyone else.
        $this->assertStringContainsString(
            get_string('attachment_attach_files', 'local_moodesk'),
            $html
        );
        $this->assertStringContainsString('#mhd-icon-paperclip', $html);
    }

    /**
     * Attaching a file belongs to describing the problem, so the control sits
     * under the description — not in the footer, and not after the buttons.
     *
     * Both mounts get this from the form itself, which is why it cannot drift
     * between the drawer and submit.php.
     */
    public function test_the_attach_control_sits_between_the_message_and_the_buttons(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $html = new_ticket_drawer::create()->get_form()->render();

        $message = strpos($html, 'name="message"');
        $attach  = strpos($html, 'data-region="mhd-composer-attachments"');
        $buttons = strpos($html, 'buttonar');

        $this->assertNotFalse($message);
        $this->assertNotFalse($attach);
        $this->assertNotFalse($buttons);
        $this->assertGreaterThan($message, $attach);
        $this->assertLessThan($buttons, $attach);
    }

    /**
     * With uploads off there is no control to render, and nothing that promises
     * an upload the form would not accept.
     */
    public function test_no_attach_control_renders_when_uploads_are_disabled(): void {
        $this->setAdminUser();
        set_config('attachments_enabled', 0, 'local_moodesk');

        $html = new_ticket_drawer::create()->get_form()->render();

        $this->assertStringNotContainsString('data-region="mhd-composer-attachments"', $html);
        $this->assertStringNotContainsString('data-action="mhd-attach-file"', $html);
    }

    /**
     * The control is inert markup without the module that drives it, so both
     * mounts have to queue the same behaviour. submit.php calls this method for
     * the bare form; the drawer calls it from render().
     */
    public function test_both_mounts_queue_the_same_composer_behaviour(): void {
        global $PAGE;

        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url(new \moodle_url('/local/moodesk/submit.php'));

        new_ticket_drawer::create()->require_composer_js($page);

        $requires = $page->requires->get_end_code();

        $this->assertStringContainsString('composer_autogrow', $requires);
        $this->assertStringContainsString('composer_attachments', $requires);

        unset($PAGE);
    }

    /**
     * The whole point of the rework, checked end to end: a file uploaded into
     * the draft the form names ends up on the ticket that form creates.
     *
     * This walks submit.php's own path — the draft item id the form carries,
     * ticket_service::create_ticket(), then file_save_draft_area_files() into
     * reply_attachment against the first reply. The file area and the itemid are
     * exactly what the file manager produced before this rework, which is what
     * makes tickets raised either side of it indistinguishable.
     */
    public function test_a_file_in_the_draft_ends_up_on_the_created_ticket(): void {
        global $CFG, $USER;

        require_once($CFG->libdir . '/filelib.php');

        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $drawer      = new_ticket_drawer::create();
        $config      = $drawer->attachments_config();
        $draftitemid = $config['draftitemid'];

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => 'server.log',
        ], 'connection refused');

        $ticketdata                = new \stdClass();
        $ticketdata->userid        = $USER->id;
        $ticketdata->subject       = 'Cannot log in';
        $ticketdata->message       = 'It refuses my password.';
        $ticketdata->messageformat = FORMAT_PLAIN;

        $result = \local_moodesk\service\ticket_service::create()
            ->create_ticket($ticketdata, $USER->id);

        file_save_draft_area_files(
            $draftitemid,
            \context_system::instance()->id,
            'local_moodesk',
            'reply_attachment',
            $result['replyid'],
            $drawer->get_attachment_options()
        );

        $saved = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_moodesk',
            'reply_attachment',
            $result['replyid'],
            'filename',
            false
        );

        $this->assertCount(1, $saved);
        $this->assertSame('server.log', reset($saved)->get_filename());
    }

    /**
     * A ticket raised with nothing attached is still a ticket. The draft is
     * empty, the save is a no-op, and nothing about the path complains.
     */
    public function test_a_ticket_with_no_attachments_still_saves_cleanly(): void {
        global $CFG, $USER;

        require_once($CFG->libdir . '/filelib.php');

        $this->setAdminUser();
        set_config('attachments_enabled', 1, 'local_moodesk');

        $drawer = new_ticket_drawer::create();

        $ticketdata                = new \stdClass();
        $ticketdata->userid        = $USER->id;
        $ticketdata->subject       = 'Just a question';
        $ticketdata->message       = 'No files needed.';
        $ticketdata->messageformat = FORMAT_PLAIN;

        $result = \local_moodesk\service\ticket_service::create()
            ->create_ticket($ticketdata, $USER->id);

        file_save_draft_area_files(
            $drawer->attachments_config()['draftitemid'],
            \context_system::instance()->id,
            'local_moodesk',
            'reply_attachment',
            $result['replyid'],
            $drawer->get_attachment_options()
        );

        $this->assertEmpty(get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'local_moodesk',
            'reply_attachment',
            $result['replyid'],
            'filename',
            false
        ));
        $this->assertGreaterThan(0, $result['ticketid']);
    }

    /* ── KB-03: a category to start from ─────────────────────────────────────── */

    /**
     * A preselected category is honoured only when it is one the form offers:
     * an archived one or a made-up id falls back to the site default.
     */
    public function test_category_preselection_is_checked_against_active_categories(): void {
        global $DB;
        $this->setAdminUser();

        $now = time();
        $default = (int) get_config('local_moodesk', 'defaultcategoryid');
        $active  = $DB->insert_record('local_moodesk_category', (object) [
            'name' => 'Access', 'sortorder' => 5, 'visible' => 1, 'timecreated' => $now, 'usermodified' => 2,
        ]);
        $archived = $DB->insert_record('local_moodesk_category', (object) [
            'name' => 'Legacy', 'sortorder' => 9, 'visible' => 0, 'timecreated' => $now, 'usermodified' => 2,
        ]);

        $selected = static function (string $html): int {
            preg_match('~<option value="(\d+)"[^>]*\bselected\b~', $html, $m);
            return (int) ($m[1] ?? 0);
        };

        $this->assertSame($active, $selected(new_ticket_drawer::create(0, null, $active)->get_form()->render()));
        $this->assertSame(
            $default,
            $selected(new_ticket_drawer::create(0, null, $archived)->get_form()->render()),
            'An archived category is not preselected.'
        );
        $this->assertSame(
            $default,
            $selected(new_ticket_drawer::create(0, null, 999999)->get_form()->render()),
            'An unknown id is not trusted.'
        );
        $this->assertSame($default, $selected(new_ticket_drawer::create()->get_form()->render()));
    }

    /* ----------------------------------------------------------------------
     * Suggested articles (KB-04)
     * -------------------------------------------------------------------- */

    /**
     * The block is in the form, between the category row and the description,
     * on both mounts — it comes through the form, so the two cannot differ.
     */
    public function test_the_suggestions_block_sits_between_category_and_description(): void {
        global $DB;

        $this->setAdminUser();
        $now = time();
        foreach (['Access', 'Billing'] as $i => $name) {
            $DB->insert_record('local_moodesk_category', (object) [
                'name' => $name, 'sortorder' => $i, 'visible' => 1, 'timecreated' => $now, 'usermodified' => 2,
            ]);
        }

        $drawer = new_ticket_drawer::create();
        $this->assertTrue($drawer->suggestions_enabled());

        foreach ([$drawer, new_ticket_drawer::create(0, get_string('ticket_submit', 'local_moodesk'))] as $mount) {
            $html = $mount->get_form()->render();

            $block    = strpos($html, 'data-region="mhd-kb-suggest"');
            $category = strpos($html, 'id="id_categoryid"');
            $message  = strpos($html, 'data-region="mhd-composer-input"');

            $this->assertNotFalse($block);
            $this->assertGreaterThan($category, $block, 'The block follows the category field.');
            $this->assertLessThan($message, $block, 'The block precedes the description.');
            $this->assertStringContainsString('data-subject-id="id_subject"', $html);
            $this->assertStringContainsString('data-category-name="categoryid"', $html);
            $this->assertStringContainsString('aria-live="polite"', $html);
            $this->assertStringContainsString(get_string('kb_suggest_heading', 'local_moodesk'), $html);
        }
    }

    /**
     * The block goes with the knowledge base as the reader sees it: off with
     * kb_enabled, absent without :viewkb. Then neither the markup nor the
     * module is emitted.
     */
    public function test_no_suggestions_without_the_knowledge_base(): void {
        global $PAGE;

        $this->setAdminUser();
        set_config('kb_enabled', 0, 'local_moodesk');

        $drawer = new_ticket_drawer::create();
        $this->assertFalse($drawer->suggestions_enabled());
        $this->assertStringNotContainsString('mhd-kb-suggest', $drawer->get_form()->render());

        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url(new \moodle_url('/local/moodesk/submit.php'));
        $drawer->require_composer_js($page);
        $this->assertStringNotContainsString('kb_suggestions', $page->requires->get_end_code());
        unset($PAGE);

        set_config('kb_enabled', 1, 'local_moodesk');

        // A requester who may submit but may not read the knowledge base.
        $user   = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/moodesk:viewkb', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $drawer = new_ticket_drawer::create();
        $this->assertNotNull($drawer, 'The user may still submit.');
        $this->assertFalse($drawer->suggestions_enabled());
        $this->assertStringNotContainsString('mhd-kb-suggest', $drawer->get_form()->render());
    }

    /**
     * With the block rendered, the module that fills it is queued by the one
     * method both mounts call.
     */
    public function test_the_suggestions_module_is_queued_with_the_block(): void {
        global $PAGE;

        $this->setAdminUser();

        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url(new \moodle_url('/local/moodesk/submit.php'));

        new_ticket_drawer::create()->require_composer_js($page);

        $this->assertStringContainsString('local_moodesk/kb_suggestions', $page->requires->get_end_code());
        unset($PAGE);
    }
}
