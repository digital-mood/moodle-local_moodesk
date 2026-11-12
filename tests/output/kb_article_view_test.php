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

namespace local_moodesk\output;

use local_moodesk\domain\kb_article;
use local_moodesk\service\edition_service;
use local_moodesk\service\kb_service;

/**
 * One article, three kinds of viewer: the layers each one gets.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\output\kb_article_view
 */
final class kb_article_view_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $reader;

    /** @var \stdClass Holds :editkb (agent role), not :managekb. */
    private \stdClass $editor;

    /** @var \stdClass Holds :managekb. */
    private \stdClass $manager;

    /** @var int */
    private int $access;

    /** @var int Archived after use. */
    private int $legacy;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $this->reader  = $gen->create_user();
        $this->editor  = $gen->create_user(['firstname' => 'Elena', 'lastname' => 'Editora']);
        $this->manager = $gen->create_user();
        $ctx = \context_system::instance();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'mhd_agent']), $this->editor->id, $ctx->id);
        role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $this->manager->id, $ctx->id);

        $now = time();
        $this->access = $DB->insert_record('local_moodesk_category', (object) [
            'name' => 'Access', 'sortorder' => 5, 'visible' => 1, 'timecreated' => $now, 'usermodified' => 2,
        ]);
        $this->legacy = $DB->insert_record('local_moodesk_category', (object) [
            'name' => 'Legacy', 'sortorder' => 9, 'visible' => 0, 'timecreated' => $now, 'usermodified' => 2,
        ]);
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * Create an article and build its view for an actor.
     *
     * @param \stdClass $actor
     * @param array     $fields Generator fields.
     * @return kb_article_view
     */
    private function view(\stdClass $actor, array $fields = []): kb_article_view {
        $article = $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_kb_article($fields + [
            'title' => 'Tom & Jerry', 'body' => '<p>Click Forgot password.</p>', 'status' => 'published',
            'categoryid' => $this->access, 'usermodified' => $this->editor->id, 'createdby' => $this->editor->id,
        ]);
        return $this->view_of($article, $actor);
    }

    /**
     * The view of a given row for an actor, reading in the language set by
     * multilingual() — set after setUser(), which starts a fresh session.
     *
     * @param \stdClass $article
     * @param \stdClass $actor
     * @return kb_article_view
     */
    private function view_of(\stdClass $article, \stdClass $actor): kb_article_view {
        global $SESSION;
        $this->setUser($actor);
        if ($this->readinglang !== '') {
            $SESSION->lang = $this->readinglang;
        }
        return kb_article_view::create($article, (int) $actor->id);
    }

    /**
     * A requester gets the text and the way out, and not one editorial layer.
     */
    public function test_reader_sees_no_editorial_ui(): void {
        $view = $this->view($this->reader);
        $this->assertFalse($view->is_editor());

        $header = $view->header()->to_context();
        $this->assertFalse($header['hasactions'], 'No pill, no actions.');
        $this->assertSame('Tom & Jerry', $header['title'], 'Unescaped here; the template escapes.');

        $ctx = $view->export();
        $this->assertFalse($ctx['iseditor']);
        $this->assertFalse($ctx['haseditor']);
        $this->assertSame('', $ctx['editorname']);
        $this->assertFalse($ctx['haspublished']);
        $this->assertFalse($ctx['haslifecycle']);
        foreach (['canpublish', 'canunpublish', 'canarchive', 'canrestore', 'candelete'] as $flag) {
            $this->assertFalse($ctx[$flag], $flag);
        }
        $this->assertSame('Access', $ctx['categoryname']);
        $this->assertStringContainsString('Forgot password', $ctx['renderedbody']);

        $crumbs = array_column($ctx['breadcrumb']['items'], 'label');
        $this->assertSame(['Knowledge base', 'Access', 'Tom & Jerry'], $crumbs);
        $this->assertTrue($ctx['breadcrumb']['items'][2]['current']);
        $this->assertStringEndsWith('kb.php?category=' . $this->access, $ctx['breadcrumb']['items'][1]['url']);

        $this->assertTrue($ctx['cta']['show'], 'A requester holds :submit.');
        $this->assertStringEndsWith('submit.php?categoryid=' . $this->access, $ctx['cta']['url']);
    }

    /**
     * An editor without :managekb: the status, the names, Edit — and no
     * transition anywhere.
     */
    public function test_editor_sees_status_and_names_but_no_transitions(): void {
        $view = $this->view($this->editor);
        $this->assertTrue($view->is_editor());

        $header = $view->header()->to_context();
        $this->assertStringContainsString('Published', $header['meta']);
        $this->assertSame([], $header['actions']);
        $this->assertSame('Edit article', $header['primaryaction']['label']);

        $ctx = $view->export();
        $this->assertTrue($ctx['haseditor']);
        $this->assertSame('Elena Editora', $ctx['editorname']);
        $this->assertTrue($ctx['haspublished']);
        $this->assertFalse($ctx['haslifecycle']);
        $this->assertFalse($ctx['canunpublish']);
    }

    /**
     * A manager: the header's next step by status, the footer's transitions.
     */
    public function test_manager_transitions_follow_the_status(): void {
        $published = $this->view($this->manager);
        $this->assertSame(
            ['publish' => false, 'unpublish' => true, 'archive' => true, 'restore' => false, 'delete' => true],
            $published->transitions()
        );
        $header = $published->header()->to_context();
        $this->assertSame('Edit article', $header['primaryaction']['label']);
        $this->assertTrue($published->export()['haslifecycle']);

        $draft = $this->view($this->manager, ['status' => 'draft']);
        $header = $draft->header()->to_context();
        $this->assertSame('Publish', $header['primaryaction']['label']);
        $this->assertSame('mhd-kb-action-publish', $header['primaryaction']['form']);
        $this->assertSame('Edit article', $header['actions'][0]['label']);
        $this->assertSame(
            ['publish' => true, 'unpublish' => false, 'archive' => false, 'restore' => false, 'delete' => true],
            $draft->transitions()
        );

        $archived = $this->view($this->manager, ['status' => 'archived']);
        $this->assertSame('Restore', $archived->header()->to_context()['primaryaction']['label']);
    }

    /**
     * An archived category keeps its name on the page and in the breadcrumb;
     * only the ticket's preselection lets go of it. No category reads "No category".
     */
    public function test_archived_and_missing_categories(): void {
        $legacy = $this->view($this->reader, ['categoryid' => $this->legacy])->export();
        $this->assertSame('Legacy', $legacy['categoryname']);
        $this->assertSame('Legacy', $legacy['breadcrumb']['items'][1]['label']);
        $this->assertStringEndsWith('kb.php?category=' . $this->legacy, $legacy['breadcrumb']['items'][1]['url']);
        $this->assertStringEndsWith('/submit.php', $legacy['cta']['url'], 'No preselection of an archived category.');

        $none = $this->view($this->reader, ['categoryid' => null])->export();
        $this->assertSame('No category', $none['categoryname']);
        $this->assertStringEndsWith('kb.php?category=none', $none['categoryurl']);
        $this->assertStringEndsWith('/submit.php', $none['cta']['url']);
    }

    /**
     * No :submit, no call to action.
     */
    public function test_cta_needs_submit(): void {
        global $DB;
        $userrole = $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability('local/moodesk:submit', CAP_PROHIBIT, $userrole, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $ctx = $this->view($this->reader)->export();
        $this->assertFalse($ctx['cta']['show']);
    }

    /**
     * The template, for a reader and for a manager: the article is labelled
     * by the page title, and what must be absent is absent.
     */
    public function test_template_renders_both_layers(): void {
        global $OUTPUT;

        $reader = $this->view($this->reader);
        $ctx = $reader->export();
        $ctx['pageheader'] = $reader->header()->to_context();
        $html = $OUTPUT->render_from_template('local_moodesk/kb_article', $ctx);
        $this->assertStringContainsString('<article class="mhd-kb-article__surface" aria-labelledby="mhd-page-title">', $html);
        $this->assertStringContainsString('id="mhd-page-title">Tom &amp; Jerry</h1>', $html);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('Open a ticket', $html);
        $this->assertStringNotContainsString('Manage status', $html);
        $this->assertStringNotContainsString('Elena', $html);
        $this->assertStringNotContainsString('First published', $html);
        // No transition form for a reader.
        foreach (['publish', 'unpublish', 'archive', 'restore', 'delete'] as $action) {
            $this->assertStringNotContainsString('value="' . $action . '"', $html);
        }

        $manager = $this->view($this->manager);
        $ctx = $manager->export();
        $ctx['pageheader'] = $manager->header()->to_context();
        $html = $OUTPUT->render_from_template('local_moodesk/kb_article', $ctx);
        $this->assertStringContainsString('Manage status', $html);
        $this->assertStringContainsString('by Elena Editora', $html);
        $this->assertStringContainsString('First published', $html);
        $this->assertStringContainsString('Open a ticket', $html);
    }

    /* ----------------------------------------------------------------------
     * Language (KB-06).
     * -------------------------------------------------------------------- */

    /**
     * Fake packs, Pro, and the reader reading in $lang.
     *
     * @param string $lang
     */
    private function multilingual(string $lang): void {
        global $DB;
        if (!$DB->record_exists('config', ['name' => 'local_moodesk_test_packs'])) {
            $gen = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
            $gen->create_language_pack(['code' => 'es', 'name' => 'Español', 'iso' => 'es']);
            $gen->create_language_pack(['code' => 'pt_br', 'name' => 'Português - Brasil', 'iso' => 'pt']);
            $gen->create_language_pack(['code' => 'fr', 'name' => 'Français', 'iso' => 'fr']);
            $gen->create_language_pack(['code' => 'de', 'name' => 'Deutsch', 'iso' => 'de']);
            set_config('local_moodesk_test_packs', 1);
        }
        edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
        $this->readinglang = $lang;
    }

    /** @var string The language the reader reads in, applied by view_of(). */
    private string $readinglang = '';

    /**
     * A row of the group, through the generator.
     *
     * @param int    $groupid
     * @param string $lang
     * @param string $status
     * @return \stdClass
     */
    private function sibling(int $groupid, string $lang, string $status = 'published'): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_kb_article([
            'title' => "Tom & Jerry ($lang)", 'body' => '<p>…</p>', 'status' => $status, 'lang' => $lang,
            'groupid' => $groupid, 'categoryid' => $this->access,
        ]);
    }

    /**
     * The raw row.
     *
     * @param int $id
     * @return \stdClass
     */
    private function article_row(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_moodesk_kb_article', ['id' => $id], '*', MUST_EXIST);
    }
}
