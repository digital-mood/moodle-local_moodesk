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
 * Privacy API coverage for knowledge base articles.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for how the provider treats local_moodesk_kb_article.
 *
 * An article is the organisation's content: what is personal about it is
 * having written it or having last edited it. So the author and the editor
 * are found, exported by their relation to the row, and erased by clearing
 * their name — the article itself, body and images, stays for every reader
 * who had nothing to do with either of them.
 *
 * @covers \local_moodesk\privacy\provider
 */
final class kb_article_privacy_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $author;

    /** @var \stdClass */
    private \stdClass $editor;

    /** @var \stdClass */
    private \stdClass $bystander;

    /** @var \context_system */
    private \context_system $context;

    /** @var \testing_data_generator */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->context   = \context_system::instance();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $this->author    = $this->getDataGenerator()->create_user();
        $this->editor    = $this->getDataGenerator()->create_user();
        $this->bystander = $this->getDataGenerator()->create_user();
    }

    /**
     * An article written by the author and last edited by the editor, with an
     * image under it.
     *
     * @return \stdClass
     */
    private function article(): \stdClass {
        $article = $this->generator->create_kb_article([
            'title'        => 'How to reset a password',
            'body'         => '<p>Click forgot.</p>',
            'status'       => 'published',
            'createdby'    => $this->author->id,
            'usermodified' => $this->editor->id,
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'local_moodesk',
            'filearea'  => 'kb_article',
            'itemid'    => $article->id,
            'filepath'  => '/',
            'filename'  => 'shot.png',
        ], 'png-bytes');
        return $article;
    }

    /**
     * Run erasure for one user.
     *
     * @param int $userid
     */
    private function delete_for(int $userid): void {
        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($userid),
            'local_moodesk',
            [$this->context->id]
        ));
    }

    /**
     * Export for one user and return what landed under the articles node.
     *
     * @param int $userid
     * @param int $articleid
     * @return \stdClass|null
     */
    private function export_article_for(int $userid, int $articleid): ?\stdClass {
        writer::reset();
        provider::export_user_data(new approved_contextlist(
            \core_user::get_user($userid),
            'local_moodesk',
            [$this->context->id]
        ));
        $data = writer::with_context($this->context)->get_data([
            get_string('pluginname', 'local_moodesk'),
            get_string('privacy_kb_articles', 'local_moodesk'),
            $articleid,
        ]);
        return empty((array) $data) ? null : $data;
    }

    /**
     * The knowledge base events reach the log stores with the user's id, so the provider
     * declares where they go, and says the search words are not among them.
     */
    public function test_metadata_declares_the_log_stores_for_the_kb_events(): void {
        $collection = provider::get_metadata(new collection('local_moodesk'));

        $summary = null;
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\plugintype_link && $item->get_name() === 'logstore') {
                $summary = $item->get_summary();
            }
        }

        $this->assertSame('privacy:metadata:logstore', $summary);
        $this->assertStringContainsString('never hold the search words', get_string($summary, 'local_moodesk'));
    }

    /**
     * Author and editor are found; a bystander is not.
     */
    public function test_contexts_for_author_and_editor(): void {
        $this->article();

        $ids = fn(int $userid) => array_map('intval', provider::get_contexts_for_userid($userid)->get_contextids());
        $this->assertContains($this->context->id, $ids((int) $this->author->id));
        $this->assertContains($this->context->id, $ids((int) $this->editor->id));
        $this->assertSame([], $ids((int) $this->bystander->id));
    }

    /**
     * The users in the system context include both, and not the bystander.
     */
    public function test_users_in_context(): void {
        $this->article();

        $userlist = new userlist($this->context, 'local_moodesk');
        provider::get_users_in_context($userlist);
        $ids = array_map('intval', $userlist->get_userids());

        $this->assertContains((int) $this->author->id, $ids);
        $this->assertContains((int) $this->editor->id, $ids);
        $this->assertNotContains((int) $this->bystander->id, $ids);
    }

    /**
     * Each person's export says what their relation to the article is, and
     * never carries the body.
     */
    public function test_export_states_the_relation_and_omits_the_body(): void {
        $article = $this->article();

        $forauthor = $this->export_article_for((int) $this->author->id, (int) $article->id);
        $this->assertNotNull($forauthor);
        $this->assertSame('How to reset a password', $forauthor->title);
        $this->assertTrue($forauthor->author);
        $this->assertObjectNotHasProperty('lasteditor', $forauthor);
        $this->assertObjectHasProperty('timecreated', $forauthor);
        $this->assertObjectNotHasProperty('body', $forauthor);
        $this->assertStringNotContainsString('forgot', json_encode($forauthor));
        // KB-06: the row says which article it belongs to, and nothing more about the group.
        $this->assertSame((int) $article->groupid, $forauthor->groupid);
        $this->assertSame('en', $forauthor->lang);

        $foreditor = $this->export_article_for((int) $this->editor->id, (int) $article->id);
        $this->assertNotNull($foreditor);
        $this->assertTrue($foreditor->lasteditor);
        $this->assertObjectNotHasProperty('author', $foreditor);
        $this->assertObjectHasProperty('timemodified', $foreditor);

        $this->assertNull($this->export_article_for((int) $this->bystander->id, (int) $article->id));
    }

    /**
     * Erasing the author clears their name and leaves the article, the
     * editor's name and the image exactly where they were.
     */
    public function test_erasing_the_author_keeps_the_article(): void {
        global $DB;
        $article = $this->article();

        $this->delete_for((int) $this->author->id);

        $row = $DB->get_record('local_moodesk_kb_article', ['id' => $article->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->createdby);
        $this->assertSame((int) $this->editor->id, (int) $row->usermodified);
        $this->assertSame('<p>Click forgot.</p>', $row->body);
        $this->assertSame('How to reset a password', $row->title);
        $files = get_file_storage()->get_area_files($this->context->id, 'local_moodesk', 'kb_article', $article->id, 'id', false);
        $this->assertCount(1, $files);
    }

    /**
     * Erasing the editor clears the other column and nothing else.
     */
    public function test_erasing_the_editor_keeps_the_article(): void {
        global $DB;
        $article = $this->article();

        $this->delete_for((int) $this->editor->id);

        $row = $DB->get_record('local_moodesk_kb_article', ['id' => $article->id], '*', MUST_EXIST);
        $this->assertSame((int) $this->author->id, (int) $row->createdby);
        $this->assertSame(0, (int) $row->usermodified);
    }

    /**
     * The userlist path erases the same way.
     */
    public function test_erasing_by_userlist(): void {
        global $DB;
        $article = $this->article();

        provider::delete_data_for_users(new approved_userlist(
            $this->context,
            'local_moodesk',
            [(int) $this->author->id, (int) $this->editor->id]
        ));

        $row = $DB->get_record('local_moodesk_kb_article', ['id' => $article->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->createdby);
        $this->assertSame(0, (int) $row->usermodified);
    }

    /**
     * Wiping the whole context keeps the articles — the organisation's
     * answers — and takes every name off them.
     */
    public function test_the_full_wipe_keeps_articles_nameless(): void {
        global $DB;
        $article = $this->article();

        provider::delete_data_for_all_users_in_context($this->context);

        $row = $DB->get_record('local_moodesk_kb_article', ['id' => $article->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $row->createdby);
        $this->assertSame(0, (int) $row->usermodified);
        $this->assertSame(0, $DB->count_records_select('local_moodesk_kb_article', 'createdby <> 0 OR usermodified <> 0'));
    }
}
