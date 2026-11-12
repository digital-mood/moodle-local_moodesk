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
 * Unit tests for the knowledge base service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\kb_article;
use local_moodesk\event\kb_article_published;
use local_moodesk\policy\kb_access_policy;

/**
 * Tests for kb_service.
 *
 * @covers \local_moodesk\service\kb_service
 */
final class kb_service_test extends \advanced_testcase {
    /** @var kb_service */
    private kb_service $service;

    /** @var int A user holding :editkb only. */
    private int $editor;

    /** @var int A user holding :managekb only. */
    private int $manager;

    /** @var int A user holding :agent, :viewall and :reply. */
    private int $agent;

    /** @var int A plain authenticated user. */
    private int $requester;

    /** @var \testing_data_generator */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->service   = kb_service::create();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $this->editor    = $this->user_holding(['local/moodesk:editkb']);
        $this->manager   = $this->user_holding(['local/moodesk:managekb']);
        $this->agent     = $this->user_holding(['local/moodesk:agent', 'local/moodesk:viewall', 'local/moodesk:reply']);
        $this->requester = (int) $this->getDataGenerator()->create_user()->id;
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * A user holding the capabilities named, on top of the user archetype.
     *
     * @param string[] $capabilities
     * @return int userid
     */
    private function user_holding(array $capabilities): int {
        $context = \context_system::instance();
        $user    = $this->getDataGenerator()->create_user();
        $roleid  = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return (int) $user->id;
    }

    /**
     * A category row.
     *
     * @param string $name
     * @param int    $visible
     * @return int
     */
    private function category(string $name, int $visible = 1): int {
        global $DB;
        return (int) $DB->insert_record('local_moodesk_category', (object) [
            'name'         => $name,
            'sortorder'    => 0,
            'visible'      => $visible,
            'timecreated'  => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * The raw row.
     *
     * @param int $id
     * @return \stdClass
     */
    private function row(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_moodesk_kb_article', ['id' => $id], '*', MUST_EXIST);
    }

    /* ----------------------------------------------------------------------
     * Create
     * -------------------------------------------------------------------- */

    /**
     * A draft is born as a draft, its own group, in the site language, with
     * search text derived from its body and the actor on both user columns.
     */
    public function test_create_draft(): void {
        global $CFG;

        $id  = $this->service->create_draft((object) [
            'title' => '  Reset your password ',
            'body'  => '<h2>Steps</h2><p>Click <em>Forgot</em>.</p>',
        ], $this->editor);
        $row = $this->row($id);

        $this->assertSame(kb_article::STATUS_DRAFT, (int) $row->status);
        $this->assertSame(kb_article::AUDIENCE_EVERYONE, (int) $row->audience);
        $this->assertSame($id, (int) $row->groupid);
        $this->assertSame($CFG->lang, $row->lang);
        $this->assertSame('Reset your password', $row->title);
        $this->assertSame('<h2>Steps</h2><p>Click <em>Forgot</em>.</p>', $row->body, 'The body is stored as typed.');
        $this->assertSame((int) FORMAT_HTML, (int) $row->bodyformat);
        $this->assertSame('steps click forgot.', $row->searchtext);
        $this->assertNull($row->categoryid);
        $this->assertNull($row->timepublished);
        $this->assertSame($this->editor, (int) $row->createdby);
        $this->assertSame($this->editor, (int) $row->usermodified);
    }

    /**
     * Managers may write too; agents and requesters may not.
     */
    public function test_only_editors_and_managers_create(): void {
        $this->service->create_draft((object) ['title' => 'By a manager', 'body' => ''], $this->manager);

        foreach ([$this->agent, $this->requester] as $actor) {
            try {
                $this->service->create_draft((object) ['title' => 'Nope', 'body' => ''], $actor);
                $this->fail("Actor $actor must not create articles.");
            } catch (\required_capability_exception $e) {
                $this->assertSame(get_capability_string('local/moodesk:editkb'), $e->a);
            }
        }
    }

    /**
     * A title is required; a body is not, for a draft.
     */
    public function test_a_draft_needs_a_title(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('kb_title_required', 'local_moodesk'));
        $this->service->create_draft((object) ['title' => '   ', 'body' => '<p>x</p>'], $this->editor);
    }

    /**
     * The title is plain text: markup typed into it does not survive.
     */
    public function test_the_title_is_plain_text(): void {
        $id = $this->service->create_draft((object) ['title' => 'A <b>bold</b> title', 'body' => ''], $this->editor);
        $this->assertSame('A bold title', $this->row($id)->title);
    }

    /**
     * The body is kept exactly as typed — a script included — and it is the
     * render, not the store, that makes it safe. This test pins both halves:
     * PARAM_RAW in, and format_text() without noclean stripping it out.
     */
    public function test_body_is_raw_in_and_clean_out(): void {
        $body = '<p>Hello</p><script>alert(1)</script><p onclick="x()">Click</p>';
        $id   = $this->service->create_draft((object) ['title' => 'Unsafe', 'body' => $body], $this->editor);
        $row  = $this->row($id);

        $this->assertSame($body, $row->body);

        $rendered = format_text($row->body, (int) $row->bodyformat, ['context' => \context_system::instance()]);
        $this->assertStringNotContainsString('<script', $rendered);
        $this->assertStringNotContainsString('onclick', $rendered);
        $this->assertStringContainsString('Hello', $rendered);
    }

    /**
     * Category: none, an active one, or an exception.
     */
    public function test_category_must_be_active_when_given(): void {
        $active   = $this->category('Billing');
        $archived = $this->category('Old', 0);

        $id = $this->service->create_draft((object) ['title' => 'Filed', 'body' => '', 'categoryid' => $active], $this->editor);
        $this->assertSame($active, (int) $this->row($id)->categoryid);

        $id = $this->service->create_draft((object) ['title' => 'Unfiled', 'body' => '', 'categoryid' => 0], $this->editor);
        $this->assertNull($this->row($id)->categoryid);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('kb_invalid_category', 'local_moodesk'));
        $this->service->create_draft((object) ['title' => 'Bad', 'body' => '', 'categoryid' => $archived], $this->editor);
    }

    /**
     * An audience the domain does not know is refused.
     */
    public function test_audience_is_validated(): void {
        $id = $this->service->create_draft(
            (object) ['title' => 'Internal', 'body' => '', 'audience' => kb_article::AUDIENCE_AGENTS],
            $this->editor
        );
        $this->assertSame(kb_article::AUDIENCE_AGENTS, (int) $this->row($id)->audience);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('kb_invalid_audience', 'local_moodesk'));
        $this->service->create_draft((object) ['title' => 'Bad', 'body' => '', 'audience' => 7], $this->editor);
    }

    /**
     * A language that is not installed falls back to the site language.
     */
    public function test_lang_falls_back_to_the_site_language(): void {
        global $CFG;
        $id = $this->service->create_draft((object) ['title' => 'X', 'body' => '', 'lang' => 'xx_notalang'], $this->editor);
        $this->assertSame($CFG->lang, $this->row($id)->lang);
    }

    /* ----------------------------------------------------------------------
     * Update
     * -------------------------------------------------------------------- */

    /**
     * Updating the body re-derives the search text, every time.
     */
    public function test_update_rederives_searchtext(): void {
        $id = $this->service->create_draft((object) ['title' => 'T', 'body' => '<p>old words</p>'], $this->editor);
        $this->service->update_article($id, (object) ['body' => '<p>New &amp; improved</p>'], $this->manager);

        $row = $this->row($id);
        $this->assertSame('<p>New &amp; improved</p>', $row->body);
        $this->assertSame('new & improved', $row->searchtext);
        $this->assertSame($this->manager, (int) $row->usermodified);
        $this->assertSame($this->editor, (int) $row->createdby, 'The author does not change.');
    }

    /**
     * Only the fields given change; status is never one of them.
     */
    public function test_update_leaves_other_fields_and_status_alone(): void {
        $article = $this->generator->create_kb_article(['title' => 'Live', 'body' => '<p>b</p>', 'status' => 'published']);
        $this->service->update_article(
            (int) $article->id,
            (object) ['title' => 'Renamed', 'status' => kb_article::STATUS_ARCHIVED],
            $this->editor
        );

        $row = $this->row((int) $article->id);
        $this->assertSame('Renamed', $row->title);
        $this->assertSame('<p>b</p>', $row->body);
        $this->assertSame(kb_article::STATUS_PUBLISHED, (int) $row->status, 'Status only moves through the transition methods.');
    }

    /**
     * An editor may set the audience of a draft, but not change the audience
     * of a live article: that is an access rule, and it takes :managekb.
     */
    public function test_audience_of_a_live_article_takes_managekb(): void {
        $draft = $this->generator->create_kb_article(['status' => 'draft']);
        $this->service->update_article((int) $draft->id, (object) ['audience' => kb_article::AUDIENCE_AGENTS], $this->editor);
        $this->assertSame(kb_article::AUDIENCE_AGENTS, (int) $this->row((int) $draft->id)->audience);

        $live = $this->generator->create_kb_article(['status' => 'published']);
        // Same value: not a change, no extra capability needed.
        $this->service->update_article((int) $live->id, (object) ['audience' => kb_article::AUDIENCE_EVERYONE], $this->editor);

        $this->service->update_article((int) $live->id, (object) ['audience' => kb_article::AUDIENCE_AGENTS], $this->manager);
        $this->assertSame(kb_article::AUDIENCE_AGENTS, (int) $this->row((int) $live->id)->audience);

        $this->expectException(\required_capability_exception::class);
        $this->service->update_article((int) $live->id, (object) ['audience' => kb_article::AUDIENCE_EVERYONE], $this->editor);
    }

    /**
     * Updating a missing article says so.
     */
    public function test_update_of_a_missing_article(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('kb_article_notfound', 'local_moodesk'));
        $this->service->update_article(999999, (object) ['title' => 'x'], $this->editor);
    }

    /* ----------------------------------------------------------------------
     * Status
     * -------------------------------------------------------------------- */

    /**
     * The full lifecycle, by a manager: draft → published → draft →
     * published → archived → draft. timepublished is set once.
     */
    public function test_lifecycle(): void {
        $id = $this->service->create_draft((object) ['title' => 'L', 'body' => '<p>body</p>'], $this->editor);

        $this->service->publish($id, $this->manager);
        $row   = $this->row($id);
        $first = (int) $row->timepublished;
        $this->assertSame(kb_article::STATUS_PUBLISHED, (int) $row->status);
        $this->assertGreaterThan(0, $first);

        $this->service->unpublish($id, $this->manager);
        $this->assertSame(kb_article::STATUS_DRAFT, (int) $this->row($id)->status);
        $this->assertSame($first, (int) $this->row($id)->timepublished, 'Unpublishing keeps the first publication date.');

        $this->service->publish($id, $this->manager);
        $this->assertSame($first, (int) $this->row($id)->timepublished, 'Republishing keeps the first publication date.');

        $this->service->archive($id, $this->manager);
        $this->assertSame(kb_article::STATUS_ARCHIVED, (int) $this->row($id)->status);

        $this->service->restore($id, $this->manager);
        $this->assertSame(kb_article::STATUS_DRAFT, (int) $this->row($id)->status);
        $this->assertSame($this->manager, (int) $this->row($id)->usermodified);
    }

    /**
     * Every illegal transition is refused and leaves the row untouched.
     */
    public function test_illegal_transitions_are_refused(): void {
        $draft    = $this->generator->create_kb_article(['status' => 'draft']);
        $archived = $this->generator->create_kb_article(['status' => 'archived']);

        $attempts = [
            [$draft, 'unpublish'],
            [$draft, 'archive'],
            [$draft, 'restore'],
            [$archived, 'publish'],
            [$archived, 'archive'],
        ];
        foreach ($attempts as [$article, $method]) {
            $before = $this->row((int) $article->id);
            try {
                $this->service->$method((int) $article->id, $this->manager);
                $this->fail("$method on status {$article->status} must be refused.");
            } catch (\moodle_exception $e) {
                $this->assertSame(get_string('kb_invalid_transition', 'local_moodesk'), $e->getMessage());
            }
            $this->assertEquals($before, $this->row((int) $article->id), "$method left a mark on a refused transition.");
        }
    }

    /**
     * Status changes take :managekb; an editor cannot publish their own draft.
     */
    public function test_status_changes_take_managekb(): void {
        $id = $this->service->create_draft((object) ['title' => 'Mine', 'body' => '<p>b</p>'], $this->editor);
        try {
            $this->service->publish($id, $this->editor);
            $this->fail('An editor must not publish.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/moodesk:managekb'), $e->a);
        }
        $this->assertSame(kb_article::STATUS_DRAFT, (int) $this->row($id)->status);
    }

    /**
     * An empty article cannot go live.
     */
    public function test_publish_needs_content(): void {
        $id = $this->service->create_draft((object) ['title' => 'Empty', 'body' => '<p>   </p>'], $this->editor);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('kb_body_required', 'local_moodesk'));
        $this->service->publish($id, $this->manager);
    }

    /**
     * Publishing fires the event, once, with the author as related user.
     * Nothing else in the lifecycle does.
     */
    public function test_publish_fires_the_event_and_nothing_else_does(): void {
        $id = $this->service->create_draft((object) ['title' => 'Announced', 'body' => '<p>b</p>'], $this->editor);

        $sink = $this->redirectEvents();
        $this->service->publish($id, $this->manager);
        $this->service->unpublish($id, $this->manager);
        $this->service->publish($id, $this->manager);
        $this->service->archive($id, $this->manager);
        $this->service->restore($id, $this->manager);
        $events = $sink->get_events();
        $sink->close();

        $published = array_values(array_filter($events, fn($e) => $e instanceof kb_article_published));
        $this->assertCount(2, $published, 'Two publications, two events.');
        $this->assertCount(2, $events, 'No other event in the lifecycle.');

        $event = $published[0];
        $this->assertSame($id, (int) $event->objectid);
        $this->assertSame($this->manager, (int) $event->userid);
        $this->assertSame($this->editor, (int) $event->relateduserid);
        $this->assertSame('Announced', $event->other['title']);
        $this->assertSame('local_moodesk_kb_article', $event->objecttable);
        $this->assertStringContainsString("kb_article.php", $event->get_url()->out(false));
        $this->assertSame(get_string('event_kb_article_published', 'local_moodesk'), $event::get_name());
        $this->assertStringContainsString("published knowledge base article $id", $event->get_description());
    }

    /* ----------------------------------------------------------------------
     * Delete
     * -------------------------------------------------------------------- */

    /**
     * Delete takes :managekb, removes the row and the files under it.
     */
    public function test_delete_removes_row_and_files(): void {
        global $DB;
        $id = $this->service->create_draft((object) ['title' => 'Gone', 'body' => '<p>b</p>'], $this->editor);

        $context = \context_system::instance();
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_moodesk',
            'filearea'  => kb_service::FILEAREA,
            'itemid'    => $id,
            'filepath'  => '/',
            'filename'  => 'shot.png',
        ], 'png-bytes');
        $fs = get_file_storage();
        $this->assertCount(1, $fs->get_area_files($context->id, 'local_moodesk', kb_service::FILEAREA, $id, 'id', false));

        try {
            $this->service->delete($id, $this->editor);
            $this->fail('An editor must not delete.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/moodesk:managekb'), $e->a);
        }

        $this->service->delete($id, $this->manager);
        $this->assertFalse($DB->record_exists('local_moodesk_kb_article', ['id' => $id]));
        $this->assertCount(0, $fs->get_area_files($context->id, 'local_moodesk', kb_service::FILEAREA, $id, 'id', false));
    }

    /* ----------------------------------------------------------------------
     * Files
     * -------------------------------------------------------------------- */

    /**
     * A draft area handed in at creation lands under the article, and the
     * body's draft URLs are rewritten to the plugin file area — with the
     * search text derived from the rewritten body.
     */
    public function test_draft_files_move_under_the_article(): void {
        global $USER;
        $this->setUser($this->editor);

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($USER->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => 'diagram.png',
        ], 'png-bytes');
        $draftbody = '<p>See <img src="' . \moodle_url::make_draftfile_url($draftitemid, '/', 'diagram.png')->out(false) . '"></p>';

        $id  = $this->service->create_draft(
            (object) ['title' => 'Illustrated', 'body' => $draftbody, 'draftitemid' => $draftitemid],
            $this->editor
        );
        $row = $this->row($id);

        $this->assertStringContainsString('@@PLUGINFILE@@/diagram.png', $row->body);
        $this->assertStringNotContainsString('draftfile.php', $row->body);
        $this->assertSame('see', $row->searchtext);

        $contextid = \context_system::instance()->id;
        $files     = get_file_storage()->get_area_files($contextid, 'local_moodesk', kb_service::FILEAREA, $id, 'id', false);
        $this->assertCount(1, $files);
        $this->assertSame('diagram.png', reset($files)->get_filename());
    }

    /* ----------------------------------------------------------------------
     * Read
     * -------------------------------------------------------------------- */

    /**
     * get_article_for tells a missing article from a forbidden one, and a
     * requester gets the forbidden answer for a draft.
     */
    public function test_get_article_for(): void {
        $draft = $this->generator->create_kb_article(['status' => 'draft']);
        $live  = $this->generator->create_kb_article(['status' => 'published']);

        $this->assertSame((int) $live->id, (int) $this->service->get_article_for((int) $live->id, $this->requester)->id);
        $this->assertSame((int) $draft->id, (int) $this->service->get_article_for((int) $draft->id, $this->editor)->id);

        try {
            $this->service->get_article_for((int) $draft->id, $this->requester);
            $this->fail('A requester must not read a draft.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/moodesk:viewkb'), $e->a);
        }

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('kb_article_notfound', 'local_moodesk'));
        $this->service->get_article_for(999999, $this->editor);
    }

    /**
     * Readers list what they may see; editors list everything.
     */
    public function test_list_visible_and_list_for_manage(): void {
        $this->generator->create_kb_article(['status' => 'draft']);
        $this->generator->create_kb_article(['status' => 'published']);
        $this->generator->create_kb_article(['status' => 'published', 'audience' => 'agents']);
        $this->generator->create_kb_article(['status' => 'archived']);

        $this->assertCount(1, $this->service->list_visible($this->requester));
        $this->assertSame(1, $this->service->count_visible($this->requester));
        $this->assertCount(2, $this->service->list_visible($this->agent));
        $this->assertCount(4, $this->service->list_visible($this->editor));

        $this->assertCount(4, $this->service->list_for_manage($this->editor));
        $this->assertSame(4, $this->service->count_for_manage($this->manager));
        $this->assertCount(1, $this->service->list_for_manage($this->editor, ['status' => kb_article::STATUS_ARCHIVED]));

        $this->expectException(\required_capability_exception::class);
        $this->service->list_for_manage($this->agent);
    }

    /**
     * Counts per category follow the actor.
     */
    public function test_count_visible_by_category(): void {
        $billing = $this->category('Billing');
        $this->generator->create_kb_article(['status' => 'published', 'categoryid' => $billing]);
        $this->generator->create_kb_article(['status' => 'draft', 'categoryid' => $billing]);

        $this->assertSame([$billing => 1], $this->service->count_visible_by_category($this->requester));
        $this->assertSame([$billing => 2], $this->service->count_visible_by_category($this->editor));
    }

    /**
     * A reading surface shows live articles only, whoever is reading: the
     * publishedonly option narrows an editor's everything-scope to the
     * published, on every read the landing makes — list, count, count per
     * category and search. Fixed here because KB-04 builds on the same rule.
     */
    public function test_publishedonly_narrows_a_wide_scope_to_the_published(): void {
        $billing   = $this->category('Billing');
        $filed     = ['categoryid' => $billing, 'body' => '<p>How an invoice is paid.</p>'];
        $published = $this->generator->create_kb_article(['title' => 'Invoice paid', 'status' => 'published'] + $filed);
        $this->generator->create_kb_article(['title' => 'Invoice draft', 'status' => 'draft'] + $filed);
        $this->generator->create_kb_article(['title' => 'Invoice archived', 'status' => 'archived'] + $filed);
        $only = ['publishedonly' => true];

        // The editor's scope really is everything.
        $this->assertCount(3, $this->service->list_visible($this->editor));
        $this->assertCount(3, $this->service->search('invoice', $this->editor));

        $listed = $this->service->list_visible($this->editor, $only);
        $this->assertSame([(int) $published->id], array_map('intval', array_keys($listed)));
        $this->assertSame(1, $this->service->count_visible($this->editor, $only));
        $this->assertSame([$billing => 1], $this->service->count_visible_by_category($this->editor, $only));
        $this->assertSame([(int) $published->id], array_keys($this->service->search('invoice', $this->editor, $only)));

        // Combined with the other reader filters, not instead of them.
        $this->assertSame(
            [$billing => 1],
            $this->service->count_visible_by_category($this->editor, $only + ['lang' => 'en'])
        );
        $this->assertSame([], $this->service->search('invoice', $this->editor, $only + ['lang' => 'es']));

        // Off, or absent, changes nothing for a requester: the policy already
        // hides drafts and archived articles from them.
        $this->assertCount(1, $this->service->list_visible($this->requester, $only));
        $this->assertCount(1, $this->service->list_visible($this->requester, ['publishedonly' => false]));
    }

    /* ----------------------------------------------------------------------
     * Search
     * -------------------------------------------------------------------- */

    /**
     * Tokenisation: lower-cased, short words dropped, duplicates dropped,
     * capped, order kept.
     */
    public function test_tokenize(): void {
        $this->assertSame(['reset', 'password'], kb_service::tokenize('  Reset MY password  '));
        $this->assertSame(['reset', 'password'], kb_service::tokenize('reset password Reset'));
        $this->assertSame([], kb_service::tokenize('a b of'));
        $this->assertSame([], kb_service::tokenize(''));
        $this->assertSame(['contraseña'], kb_service::tokenize('CONTRASEÑA'));
        $this->assertCount(kb_service::MAX_TOKENS, kb_service::tokenize('one two three four five six seven eight'));
        $this->assertSame(
            ['one', 'two', 'three', 'four', 'five', 'six'],
            kb_service::tokenize('one two three four five six seven eight')
        );
    }

    /**
     * Title matches outrank body matches; ties go to the newest publication.
     */
    public function test_search_ranks_title_over_body_then_newest(): void {
        $published = ['status' => 'published'];
        $bodyonly  = $this->generator->create_kb_article(
            ['title' => 'Account help', 'body' => '<p>reset your password here</p>', 'timepublished' => 300] + $published
        );
        $olderhit  = $this->generator->create_kb_article(
            ['title' => 'Password reset', 'body' => '<p>x</p>', 'timepublished' => 100] + $published
        );
        $newerhit  = $this->generator->create_kb_article(
            ['title' => 'Reset a lost password', 'body' => '<p>x</p>', 'timepublished' => 200] + $published
        );
        $this->generator->create_kb_article(['title' => 'Unrelated', 'body' => '<p>nothing</p>', 'status' => 'published']);

        $results = $this->service->search('reset password', $this->requester);

        $this->assertSame(
            [(int) $newerhit->id, (int) $olderhit->id, (int) $bodyonly->id],
            array_keys($results)
        );
    }

    /**
     * Search never shows a requester a draft or an agents-only article, and
     * an actor with no reader capability gets nothing without a query.
     */
    public function test_search_respects_the_scope(): void {
        $this->generator->create_kb_article(['title' => 'password public', 'status' => 'published']);
        $this->generator->create_kb_article(['title' => 'password internal', 'status' => 'published', 'audience' => 'agents']);
        $this->generator->create_kb_article(['title' => 'password draft', 'status' => 'draft']);

        $this->assertCount(1, $this->service->search('password', $this->requester));
        $this->assertCount(2, $this->service->search('password', $this->agent));
        $this->assertCount(3, $this->service->search('password', $this->editor));
        $this->assertSame([], $this->service->search('password', 0));
    }

    /**
     * Category, language and limit options.
     */
    public function test_search_options(): void {
        $billing = $this->category('Billing');
        $filed = ['status' => 'published', 'categoryid' => $billing];
        $this->generator->create_kb_article(['title' => 'invoice one', 'lang' => 'en'] + $filed);
        $this->generator->create_kb_article(['title' => 'invoice two', 'lang' => 'es'] + $filed);
        $this->generator->create_kb_article(['title' => 'invoice three', 'status' => 'published']);

        $this->assertCount(3, $this->service->search('invoice', $this->requester));
        $this->assertCount(2, $this->service->search('invoice', $this->requester, ['categoryid' => $billing]));
        $this->assertCount(1, $this->service->search('invoice', $this->requester, ['categoryid' => $billing, 'lang' => 'es']));
        $this->assertCount(1, $this->service->search('invoice', $this->requester, ['limit' => 1]));
    }

    /**
     * A query with nothing usable in it returns nothing and touches no table.
     */
    public function test_search_with_no_usable_token(): void {
        $this->generator->create_kb_article(['title' => 'anything', 'status' => 'published']);
        $this->assertSame([], $this->service->search('a', $this->requester));
        $this->assertSame([], $this->service->search('%%%', $this->requester));
    }

    /* ----------------------------------------------------------------------
     * Suggest
     * -------------------------------------------------------------------- */

    /**
     * The chosen category's articles come first, whatever the global ranking
     * would have said, and the global ranking fills what the category lacks.
     */
    public function test_suggest_prefers_the_category_then_fills_globally(): void {
        $access  = $this->category('Access');
        $general = $this->category('General');
        $live    = ['status' => 'published', 'body' => '<p>x</p>'];

        // Newest first is the global order, so the General article would
        // outrank the Access one on its own.
        $accessone  = $this->generator->create_kb_article(
            ['title' => 'Password reset in Access', 'categoryid' => $access, 'timepublished' => 100] + $live
        );
        $generalone = $this->generator->create_kb_article(
            ['title' => 'Password reset in General', 'categoryid' => $general, 'timepublished' => 300] + $live
        );
        $unfiled    = $this->generator->create_kb_article(
            ['title' => 'Password reset unfiled', 'timepublished' => 200] + $live
        );

        $this->assertSame(
            [(int) $accessone->id, (int) $generalone->id, (int) $unfiled->id],
            array_keys($this->service->suggest('password reset', $this->requester, $access))
        );
        $this->assertSame(
            [(int) $generalone->id, (int) $unfiled->id, (int) $accessone->id],
            array_keys($this->service->suggest('password reset', $this->requester, $general))
        );
        // No category: the global order, untouched.
        $this->assertSame(
            [(int) $generalone->id, (int) $unfiled->id, (int) $accessone->id],
            array_keys($this->service->suggest('password reset', $this->requester, null))
        );
    }

    /**
     * An article the category half already returned is not returned again by
     * the global half, and the whole list stops at the limit.
     */
    public function test_suggest_deduplicates_and_caps(): void {
        $billing = $this->category('Billing');
        $live    = ['status' => 'published', 'body' => '<p>x</p>'];

        $filed = [];
        for ($i = 1; $i <= 3; $i++) {
            $filed[] = (int) $this->generator->create_kb_article(
                ['title' => "Invoice filed $i", 'categoryid' => $billing, 'timepublished' => $i] + $live
            )->id;
        }
        for ($i = 1; $i <= 6; $i++) {
            $this->generator->create_kb_article(['title' => "Invoice loose $i", 'timepublished' => 100 + $i] + $live);
        }

        $ids = array_keys($this->service->suggest('invoice', $this->requester, $billing));

        $this->assertCount(kb_service::SUGGEST_LIMIT, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        // The three filed ones first, newest first, then two loose ones.
        $this->assertSame(array_reverse($filed), array_slice($ids, 0, 3));

        $this->assertCount(2, $this->service->suggest('invoice', $this->requester, $billing, 2));
    }

    /**
     * Never a draft, never an archived article, whoever is asking — including
     * an editor who could open either on the management screens.
     */
    public function test_suggest_offers_published_articles_only(): void {
        $live = $this->generator->create_kb_article(['title' => 'Printer jam live', 'status' => 'published']);
        $this->generator->create_kb_article(['title' => 'Printer jam draft', 'status' => 'draft']);
        $this->generator->create_kb_article(['title' => 'Printer jam archived', 'status' => 'archived']);

        foreach ([$this->requester, $this->agent, $this->editor, $this->manager] as $actor) {
            $this->assertSame([(int) $live->id], array_keys($this->service->suggest('printer jam', $actor)));
        }
    }

    /**
     * The audience policy holds: a requester is not offered an agents-only
     * article, an agent is.
     */
    public function test_suggest_respects_the_audience(): void {
        $public   = $this->generator->create_kb_article(['title' => 'VPN public', 'status' => 'published']);
        $internal = $this->generator->create_kb_article(
            ['title' => 'VPN internal', 'status' => 'published', 'audience' => 'agents']
        );

        $this->assertSame([(int) $public->id], array_keys($this->service->suggest('vpn', $this->requester)));
        $this->assertEqualsCanonicalizing(
            [(int) $public->id, (int) $internal->id],
            array_keys($this->service->suggest('vpn', $this->agent))
        );
        $this->assertSame([], $this->service->suggest('vpn', 0));
    }

    /**
     * A category that is archived, unknown or zero is the same as none: the
     * global list, not an error and not an empty answer.
     */
    public function test_suggest_treats_an_invalid_category_as_none(): void {
        $archived = $this->category('Legacy', 0);
        $live     = ['status' => 'published', 'body' => '<p>x</p>'];
        $filed    = $this->generator->create_kb_article(
            ['title' => 'Wifi in Legacy', 'categoryid' => $archived, 'timepublished' => 100] + $live
        );
        $loose    = $this->generator->create_kb_article(['title' => 'Wifi anywhere', 'timepublished' => 200] + $live);

        $global = [(int) $loose->id, (int) $filed->id];
        $this->assertSame($global, array_keys($this->service->suggest('wifi', $this->requester, $archived)));
        $this->assertSame($global, array_keys($this->service->suggest('wifi', $this->requester, 999999)));
        $this->assertSame($global, array_keys($this->service->suggest('wifi', $this->requester, 0)));
    }

    /**
     * Nothing usable typed, nothing suggested; and search() is untouched by
     * the category preference — it still filters.
     */
    public function test_suggest_with_no_usable_subject_and_search_unchanged(): void {
        $billing = $this->category('Billing');
        $live    = ['status' => 'published', 'body' => '<p>x</p>'];
        $this->generator->create_kb_article(['title' => 'Invoice filed', 'categoryid' => $billing] + $live);
        $this->generator->create_kb_article(['title' => 'Invoice loose'] + $live);

        $this->assertSame([], $this->service->suggest('', $this->requester, $billing));
        $this->assertSame([], $this->service->suggest('in', $this->requester, $billing));

        $this->assertCount(2, $this->service->suggest('invoice', $this->requester, $billing));
        $this->assertCount(1, $this->service->search('invoice', $this->requester, ['categoryid' => $billing]));
    }

    /*
     * -------------------------------------------------------------------------
     * KB-05 — search() scope cap.
     * -------------------------------------------------------------------------
     */

    /**
     * A cap narrows the actor's scope to the recipient's and never widens it.
     */
    public function test_search_scopecap_narrows_and_never_widens(): void {
        $live = ['status' => 'published', 'body' => '<p>x</p>'];
        $forall    = $this->generator->create_kb_article(['title' => 'Wifi for everyone', 'audience' => 'everyone'] + $live);
        $foragents = $this->generator->create_kb_article(['title' => 'Wifi for agents', 'audience' => 'agents'] + $live);
        $draft     = $this->generator->create_kb_article(['title' => 'Wifi draft', 'status' => 'draft', 'body' => '<p>x</p>']);

        // Absent: today's behaviour. The agent sees both live articles.
        $this->assertEqualsCanonicalizing(
            [(int) $forall->id, (int) $foragents->id],
            array_keys($this->service->search('wifi', $this->agent))
        );

        // Capped below the actor's scope: the agent gets the requester's list.
        $this->assertSame(
            [(int) $forall->id],
            array_keys($this->service->search('wifi', $this->agent, ['scopecap' => kb_access_policy::SCOPE_PUBLIC]))
        );

        // Capped above the actor's scope: the requester's own scope wins; no
        // cap can hand them the agents-only article.
        $this->assertSame(
            [(int) $forall->id],
            array_keys($this->service->search('wifi', $this->requester, ['scopecap' => kb_access_policy::SCOPE_ALL]))
        );

        // An editor capped to the agent scope stops seeing their own draft.
        $this->assertEqualsCanonicalizing(
            [(int) $forall->id, (int) $foragents->id],
            array_keys($this->service->search('wifi', $this->editor, ['scopecap' => kb_access_policy::SCOPE_AGENT]))
        );
        $this->assertContains((int) $draft->id, array_keys($this->service->search('wifi', $this->editor)));

        // Capped to nothing, nothing.
        $this->assertSame([], $this->service->search('wifi', $this->editor, ['scopecap' => kb_access_policy::SCOPE_NONE]));
    }

    /*
     * -------------------------------------------------------------------------
     * KB-05 — search_for_reply().
     * -------------------------------------------------------------------------
     */

    /**
     * A ticket record the way search_for_reply() receives it: already visible
     * to the actor, so only the fields it reads are needed.
     *
     * @param string   $subject
     * @param int|null $categoryid
     * @return \stdClass
     */
    private function ticket_stub(string $subject, ?int $categoryid = null): \stdClass {
        return (object) ['id' => 1, 'subject' => $subject, 'categoryid' => $categoryid];
    }

    /*
     * -------------------------------------------------------------------------
     * KB-05 — draft_defaults_from_ticket().
     * -------------------------------------------------------------------------
     */

    /**
     * A ticket for the draft tests, raised by the requester, with a reply and
     * a note whose text must never come back.
     *
     * @param int|null $categoryid
     * @return \stdClass
     */
    private function ticket_with_private_content(?int $categoryid = null): \stdClass {
        $record = [
            'userid'  => $this->requester,
            'subject' => 'Cannot open the payroll export',
            'message' => 'PRIVATE-DESCRIPTION my salary is shown wrong',
        ];
        if ($categoryid) {
            $record['categoryid'] = $categoryid;
        }
        $ticket = $this->generator->create_ticket($record);
        $staff  = $this->user_holding(['local/moodesk:viewall', 'local/moodesk:reply', 'local/moodesk:addnote']);
        $this->generator->create_reply(['ticketid' => $ticket->id, 'userid' => $staff,
            'message' => 'PRIVATE-REPLY here is your personal file']);
        $this->generator->create_reply(['ticketid' => $ticket->id, 'userid' => $staff,
            'message' => 'PRIVATE-NOTE requester is difficult', 'type' => 'note']);
        return $ticket;
    }

    /**
     * How many articles exist.
     *
     * @return int
     */
    private function count_articles(): int {
        global $DB;
        return $DB->count_records('local_moodesk_kb_article');
    }
}
