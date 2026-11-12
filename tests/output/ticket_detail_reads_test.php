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
 * Tests for the query cost of the ticket detail.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;

/**
 * The detail's read count does not grow with the thread.
 *
 * Before 2.30.0 thread_context() asked the file store for each reply's
 * attachments one reply at a time: 13 + N reads on the bench for a thread of
 * N replies, attached files or not (launch readiness plan §10).
 * These cases pin the fix by counting reads, not milliseconds —
 * a number that does not depend on the machine.
 *
 * @covers \local_moodesk\output\ticket_detail
 */
final class ticket_detail_reads_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $requester;

    /** @var \stdClass Agent holding :viewall, the acting user. */
    private \stdClass $agent;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        set_config('attachments_enabled', 1, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent     = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($roleid, $this->agent->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->agent);
    }

    /**
     * A ticket with $replies replies, the first $withfiles of them carrying one
     * attachment each.
     *
     * @param int $replies
     * @param int $withfiles
     * @return int Ticket id.
     */
    private function thread(int $replies, int $withfiles): int {
        global $DB;

        $now = time() - 100000;
        $ticketid = (int) $DB->insert_record('local_moodesk_ticket', (object) [
            'userid'       => $this->requester->id,
            'createdby'    => $this->requester->id,
            'subject'      => 'Long thread',
            'status'       => ticket::STATUS_OPEN,
            'priority'     => ticket::PRIORITY_NORMAL,
            'source'       => ticket::SOURCE_WEB,
            'timecreated'  => $now,
            'timemodified' => $now,
            'usermodified' => $this->requester->id,
        ]);

        $fs  = get_file_storage();
        $ctx = \context_system::instance()->id;
        for ($i = 0; $i < $replies; $i++) {
            $replyid = (int) $DB->insert_record('local_moodesk_reply', (object) [
                'ticketid'      => $ticketid,
                'userid'        => $i % 2 ? $this->agent->id : $this->requester->id,
                'message'       => 'Reply ' . $i,
                'messageformat' => FORMAT_PLAIN,
                'type'          => reply::TYPE_PUBLIC_REPLY,
                'timecreated'   => $now + $i,
                'timemodified'  => $now + $i,
                'usermodified'  => $this->agent->id,
            ]);
            if ($i < $withfiles) {
                $fs->create_file_from_string([
                    'contextid' => $ctx,
                    'component' => 'local_moodesk',
                    'filearea'  => 'reply_attachment',
                    'itemid'    => $replyid,
                    'filepath'  => '/',
                    'filename'  => 'file-' . $i . '.txt',
                ], 'content ' . $i);
            }
        }

        return $ticketid;
    }

    /**
     * Reads spent by one export of the detail, after a warm-up export so
     * caches (roles, strings, config) do not count.
     *
     * @param int $ticketid
     * @return array{reads: int, context: array}
     */
    private function measure(int $ticketid): array {
        global $DB;

        ticket_detail::create($ticketid, (int) $this->agent->id)->export_for_template();

        $before  = $DB->perf_get_reads();
        $context = ticket_detail::create($ticketid, (int) $this->agent->id)->export_for_template();

        return ['reads' => $DB->perf_get_reads() - $before, 'context' => $context];
    }

    /**
     * The same number of reads for 5 replies and for 60, files on every one
     * of them: what changes with the thread is rows returned, not queries sent.
     */
    public function test_reads_do_not_grow_with_the_thread(): void {
        $short = $this->measure($this->thread(5, 5));
        $long  = $this->measure($this->thread(60, 60));

        $this->assertCount(5, $short['context']['thread']);
        $this->assertCount(60, $long['context']['thread']);
        $this->assertSame($short['reads'], $long['reads']);
    }

    /**
     * And the files are all there, on the right replies, sorted by name.
     */
    public function test_every_attachment_is_on_its_reply(): void {
        $ticketid = $this->thread(6, 3);

        $thread = $this->measure($ticketid)['context']['thread'];

        $this->assertSame([true, true, true, false, false, false], array_column($thread, 'hasattachments'));
        $this->assertSame('file-0.txt', $thread[0]['attachments'][0]['filename']);
        $this->assertSame('file-2.txt', $thread[2]['attachments'][0]['filename']);
        $this->assertSame([], $thread[5]['attachments']);
        $this->assertStringContainsString('/reply_attachment/' . $thread[1]['id'] . '/', $thread[1]['attachments'][0]['url']);
    }

    /**
     * With attachments switched off, nothing is asked of the file store at all.
     */
    public function test_disabled_attachments_cost_nothing(): void {
        set_config('attachments_enabled', 0, 'local_moodesk');

        $thread = $this->measure($this->thread(4, 4))['context']['thread'];

        $this->assertSame([false, false, false, false], array_column($thread, 'hasattachments'));
    }
}
