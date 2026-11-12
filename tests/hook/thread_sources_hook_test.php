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
 * Tests for the thread_sources hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;

/**
 * A conversation reads the sources plugins give it, and only its own without them.
 *
 * @covers \local_moodesk\hook\thread_sources
 * @covers \local_moodesk\service\reply_service::get_thread_with_linked
 */
final class thread_sources_hook_test extends \advanced_testcase {
    /** @var int The ticket whose conversation is read. */
    private int $ticketid;

    /** @var int Another ticket, a candidate source. */
    private int $otherid;

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
        $service = ticket_service::create();
        $this->ticketid = (int) $service->create_ticket((object) [
            'userid' => $requester->id, 'subject' => 'Main', 'message' => 'Main body.', 'messageformat' => FORMAT_PLAIN,
        ], $requester->id)['ticketid'];
        $this->otherid = (int) $service->create_ticket((object) [
            'userid' => $requester->id, 'subject' => 'Other', 'message' => 'Other body.', 'messageformat' => FORMAT_PLAIN,
        ], $requester->id)['ticketid'];
        $this->resetDebugging();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * The ticket ids the conversation's replies come from.
     *
     * @return int[]
     */
    private function thread_ticketids(): array {
        $thread = reply_service::create()->get_thread_with_linked($this->ticketid, (int) get_admin()->id);
        return array_values(array_unique(array_map(static fn($r) => (int) $r->ticketid, $thread)));
    }

    public function test_without_a_callback_only_the_ticket_is_read(): void {
        $seen = null;
        $this->redirectHook(thread_sources::class, function (thread_sources $hook) use (&$seen): void {
            $seen = [$hook->ticketid, $hook->actorid, $hook->isagent, $hook->get_sources()];
        });

        $this->assertSame([$this->ticketid], $this->thread_ticketids());
        $this->assertSame([$this->ticketid, (int) get_admin()->id, true, []], $seen);
    }

    public function test_an_added_source_is_read_and_labelled(): void {
        $this->redirectHook(thread_sources::class, function (thread_sources $hook): void {
            $hook->add_source($this->otherid, 'Other');
        });

        $thread = reply_service::create()->get_thread_with_linked($this->ticketid, (int) get_admin()->id);

        $linked = array_values(array_filter($thread, static fn($r) => $r->islinked));
        $this->assertCount(1, $linked);
        $this->assertSame($this->otherid, $linked[0]->linkedfrom);
        $this->assertSame('Other', $linked[0]->linkedsubject);
    }

    public function test_a_source_cannot_be_the_ticket_itself(): void {
        $hook = new thread_sources(5, 2, true, []);
        $this->expectException(\coding_exception::class);
        $hook->add_source(5, 'Itself');
    }

    public function test_a_source_can_be_removed(): void {
        $hook = new thread_sources(5, 2, true, [6 => 'Six', 7 => 'Seven']);
        $hook->remove_source(6);
        $this->assertSame([7 => 'Seven'], $hook->get_sources());
    }
}
