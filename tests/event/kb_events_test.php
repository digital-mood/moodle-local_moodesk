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
 * Tests for the knowledge base events a plugin can observe.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\event;

use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;

/**
 * Viewed, searched and linked are standard events on every edition.
 *
 * @covers \local_moodesk\event\kb_article_viewed
 * @covers \local_moodesk\event\kb_searched
 * @covers \local_moodesk\event\kb_article_linked
 * @covers \local_moodesk\service\reply_service
 */
final class kb_events_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_viewed_and_searched_carry_their_payload(): void {
        $sink = $this->redirectEvents();
        kb_article_viewed::create([
            'context' => \context_system::instance(), 'objectid' => 12, 'other' => ['origin' => 'suggest'],
        ])->trigger();
        kb_searched::from_result_count(0)->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertInstanceOf(kb_article_viewed::class, $events[0]);
        $this->assertSame('suggest', $events[0]->other['origin']);
        $this->assertStringContainsString('kb_article.php?id=12', $events[0]->get_url()->out(false));
        $this->assertInstanceOf(kb_searched::class, $events[1]);
        $this->assertStringContainsString('found 0 articles', $events[1]->get_description());
    }

    /**
     * The log names the user, so what was typed must never reach it.
     */
    public function test_a_search_reaches_the_log_with_its_count_and_no_words(): void {
        $this->assertSame(['resultcount' => 0], kb_searched::from_result_count(0)->other);
        $this->assertSame(['resultcount' => 3], kb_searched::from_result_count(3)->other);
        $this->assertSame(['resultcount' => 0], kb_searched::from_result_count(-1)->other);
    }

    public function test_a_note_linking_articles_fires_one_event_per_existing_article(): void {
        global $CFG, $DB;
        $categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$categoryid) {
            $categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid' => null, 'name' => 'General', 'sortorder' => 0, 'visible' => 1,
                'timecreated' => time(), 'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');
        $requester = $this->getDataGenerator()->create_user();
        $ticketid = (int) ticket_service::create()->create_ticket((object) [
            'userid' => $requester->id, 'subject' => 'Help', 'message' => 'Body.', 'messageformat' => FORMAT_PLAIN,
        ], $requester->id)['ticketid'];
        $article = $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_kb_article([
            'title' => 'Reset your password', 'body' => '<p>Steps.</p>', 'status' => 'published',
        ]);
        $this->resetDebugging();

        $link = $CFG->wwwroot . '/local/moodesk/kb_article.php?id=';
        $body = "See {$link}{$article->id} and again {$link}{$article->id}, not {$link}999999.";

        $sink = $this->redirectEvents();
        $replyid = reply_service::create()->add_internal_note(
            $ticketid,
            (object) ['message' => $body, 'messageformat' => FORMAT_PLAIN],
            (int) get_admin()->id
        );
        $linked = array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof kb_article_linked));
        $sink->close();

        $this->assertCount(1, $linked);
        $this->assertSame((int) $article->id, (int) $linked[0]->objectid);
        $this->assertSame(['replyid' => $replyid, 'ticketid' => $ticketid, 'internal' => true], $linked[0]->other);
    }
}
