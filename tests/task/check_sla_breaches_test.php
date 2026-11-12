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
 * Integration tests for the check_sla_breaches scheduled task.
 *
 * The task carries the persisted half of the SLA verdict: the list computes
 * status live, the reports read the sla_breached column this task writes, and
 * the two are only ever as consistent as this file proves them to be.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\task;

use local_moodesk\domain\ticket;
use local_moodesk\hook\sla_overrides;

/**
 * Tests for check_sla_breaches.
 *
 * @covers \local_moodesk\task\check_sla_breaches
 */
final class check_sla_breaches_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        // Resolution only: first response is a separate dimension that does not
        // pause, and letting it fire would decide these verdicts for us.
        foreach (['low', 'normal', 'high', 'urgent'] as $suffix) {
            set_config("sla_response_hours_{$suffix}", 0, 'local_moodesk');
            set_config("sla_resolve_hours_{$suffix}", 72, 'local_moodesk');
        }
    }

    /**
     * Insert a ticket directly so the test controls the SLA-relevant columns.
     *
     * @param array $overrides Column overrides.
     * @return int New ticket id.
     */
    private function make_ticket(array $overrides = []): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_moodesk_ticket', (object) array_merge([
            'userid'             => 3,
            'createdby'          => 3,
            'subject'            => 'SLA task fixture',
            'status'             => ticket::STATUS_OPEN,
            'priority'           => ticket::PRIORITY_NORMAL,
            'source'             => ticket::SOURCE_WEB,
            'timecreated'        => $now - (10 * DAYSECS),
            'timemodified'       => $now,
            'sla_breached'       => 0,
            'sla_paused_seconds' => 0,
            'sla_paused_since'   => null,
            'usermodified'       => 3,
        ], $overrides));
    }

    /**
     * Run the task, swallowing its mtrace output.
     */
    private function run_task(): void {
        ob_start();
        (new check_sla_breaches())->execute();
        ob_end_clean();
    }

    public function test_an_overdue_ticket_is_flagged(): void {
        global $DB;

        $ticketid = $this->make_ticket();

        $this->run_task();

        $this->assertSame(1, (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid]));
    }

    public function test_in_progress_tickets_are_evaluated(): void {
        global $DB;

        // STATUS_INPROGRESS is 6, above STATUS_RESOLVED at 4. The query used to
        // select "status < resolved", so the tickets agents were actively
        // working on were the one group the task never looked at.
        $ticketid = $this->make_ticket(['status' => ticket::STATUS_INPROGRESS]);

        $this->run_task();

        $this->assertSame(1, (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid]));
    }

    public function test_a_pause_that_covers_the_overrun_clears_the_flag(): void {
        global $DB;

        // Flagged under the old arithmetic, but nine of its ten days were spent
        // waiting on the requester.
        $ticketid = $this->make_ticket([
            'sla_breached'       => 1,
            'sla_paused_seconds' => 9 * DAYSECS,
        ]);

        $this->run_task();

        $this->assertSame(0, (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid]));
    }

    public function test_an_open_pause_clears_the_flag_too(): void {
        global $DB;

        $ticketid = $this->make_ticket([
            'status'           => ticket::STATUS_PENDING,
            'sla_breached'     => 1,
            'sla_paused_since' => time() - (9 * DAYSECS),
        ]);

        $this->run_task();

        $this->assertSame(0, (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid]));
    }

    public function test_terminal_tickets_keep_their_flag(): void {
        global $DB;

        // Resolved inside its window by the paused clock, so a live evaluation
        // would clear the flag. It must not: on a closed ticket the flag is the
        // historical record the reports count, not current state.
        $resolvedid = $this->make_ticket([
            'status'             => ticket::STATUS_RESOLVED,
            'timeresolved'       => time() - DAYSECS,
            'sla_breached'       => 1,
            'sla_paused_seconds' => 9 * DAYSECS,
        ]);
        $closedid = $this->make_ticket([
            'status'       => ticket::STATUS_CLOSED,
            'timeclosed'   => time() - DAYSECS,
            'sla_breached' => 1,
        ]);

        $this->run_task();

        $this->assertSame(1, (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $resolvedid]));
        $this->assertSame(1, (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $closedid]));
    }

    public function test_department_thresholds_override_the_global_ones(): void {
        global $DB;

        // The task used to select the ticket without departmentid, so every
        // evaluation silently fell back to the global threshold while the list
        // applied the override — the two disagreed by design.
        $deptid = (int) $DB->insert_record('local_moodesk_department', (object) [
            'name'                     => 'Slow lane',
            'isactive'                 => 1,
            'timecreated'              => time(),
            'timemodified'             => time(),
            'usermodified'             => 0,
        ]);
        $this->redirectHook(sla_overrides::class, static function (sla_overrides $hook) use ($deptid): void {
            $hook->set_hours($deptid, 'resolve_normal', 24 * 30);
        });

        $ticketid = $this->make_ticket(['departmentid' => $deptid]);

        $this->run_task();

        $this->assertSame(
            0,
            (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid]),
            'a 30-day department target must not be judged against the 72h global one'
        );
    }

    public function test_every_active_ticket_is_evaluated_past_the_first_page(): void {
        global $DB;

        // One full page plus a remainder. Under a single fixed LIMIT the tail
        // never got evaluated at all, and with ORDER BY timecreated the same
        // oldest tickets monopolised every run — so the newest ids are the ones
        // worth asserting on.
        $total = check_sla_breaches::BATCH_SIZE + 25;
        $now   = time();

        $rows = [];
        for ($i = 0; $i < $total; $i++) {
            $rows[] = (object) [
                'userid'             => 3,
                'createdby'          => 3,
                'subject'            => "Bulk fixture {$i}",
                'status'             => ticket::STATUS_OPEN,
                'priority'           => ticket::PRIORITY_NORMAL,
                'source'             => ticket::SOURCE_WEB,
                // Descending age, so ordering by timecreated puts the last
                // inserted ids first and any page-one-only bug shows up as
                // untouched rows at the other end.
                'timecreated'        => $now - (10 * DAYSECS) - ($total - $i),
                'timemodified'       => $now,
                'sla_breached'       => 0,
                'sla_paused_seconds' => 0,
                'sla_paused_since'   => null,
                'usermodified'       => 3,
            ];
        }
        $DB->insert_records('local_moodesk_ticket', $rows);

        $this->run_task();

        $this->assertSame(
            $total,
            $DB->count_records('local_moodesk_ticket', ['sla_breached' => 1]),
            'every active ticket must be evaluated, not just the first page'
        );
    }
}
