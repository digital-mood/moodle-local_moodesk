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
 * Tests for the query cost of the ticket list.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\ticket;
use local_moodesk\service\ticket_service;

/**
 * The list's read count does not grow with the departments on the page.
 *
 * Before 2.31.0 the SLA evaluation of each row loaded its department to
 * resolve the department's overrides, cached per request: one read per
 * distinct department on the page — 9 to 12 reads for one page on the
 * scale bench (docs/lab/scale-bench-2026-09-16.md §7.1).
 * These cases pin the prefetch by counting reads, not milliseconds.
 *
 * @covers \local_moodesk\output\ticket_list_results
 * @covers \local_moodesk\service\sla_service
 */
final class ticket_list_reads_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $requester;

    /** @var \stdClass Agent holding :viewall, the acting user. */
    private \stdClass $agent;

    /** @var int Batches of tickets raised so far; each one is newer than the last. */
    private int $batch = 0;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent     = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($roleid, $this->agent->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->agent);
    }

    /**
     * Create $count departments, each with its own response-time override.
     *
     * @param int $count
     * @return int[] Department ids.
     */
    private function departments(int $count): array {
        global $DB;

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int) $DB->insert_record('local_moodesk_department', (object) [
                'name'                      => 'Department ' . $i,
                'isactive'                  => 1,
                'timecreated'               => time(),
                'timemodified'              => time(),
                'usermodified'              => $this->agent->id,
            ]);
        }

        return $ids;
    }

    /**
     * Raise $count open tickets spread round-robin over the given departments,
     * each batch newer than the last so the latest batch fills page one.
     *
     * @param int   $count
     * @param int[] $departmentids
     */
    private function tickets(int $count, array $departmentids): void {
        global $DB;

        $now = time() - HOURSECS + $this->batch++ * 1000;
        for ($i = 0; $i < $count; $i++) {
            $DB->insert_record('local_moodesk_ticket', (object) [
                'userid'       => $this->requester->id,
                'createdby'    => $this->requester->id,
                'subject'      => 'Ticket ' . $i,
                'status'       => ticket::STATUS_OPEN,
                'priority'     => ticket::PRIORITY_NORMAL,
                'source'       => ticket::SOURCE_WEB,
                'departmentid' => $departmentids[$i % count($departmentids)],
                'timecreated'  => $now + $i,
                'timemodified' => $now + $i,
                'usermodified' => $this->requester->id,
            ]);
        }
    }

    /**
     * Reads spent by one build of the list, after a warm-up build so caches
     * (roles, strings, config) do not count.
     *
     * @param string $querystring
     * @return array{reads: int, context: array}
     */
    private function measure(string $querystring = 'view=all'): array {
        global $DB;

        $query = ticket_list_query::from_query_string($querystring, true);
        ticket_list_results::create($query, (int) $this->agent->id, true)->get_context();

        $before  = $DB->perf_get_reads();
        $context = ticket_list_results::create($query, (int) $this->agent->id, true)->get_context();

        return ['reads' => $DB->perf_get_reads() - $before, 'context' => $context];
    }

    /**
     * The same number of reads for a page drawn from 2 departments and for
     * one drawn from 8: what changes with the departments is rows in one
     * query, not queries sent.
     */
    public function test_reads_do_not_grow_with_the_departments_on_the_page(): void {
        $this->tickets(ticket_service::PAGE_SIZE, $this->departments(2));
        $few = $this->measure();

        // A newer full page over eight departments: page one is now them.
        $this->tickets(ticket_service::PAGE_SIZE, $this->departments(8));
        $many = $this->measure();

        $this->assertCount(ticket_service::PAGE_SIZE, $few['context']['tickets']);
        $this->assertCount(ticket_service::PAGE_SIZE, $many['context']['tickets']);
        $this->assertSame($few['reads'], $many['reads']);
    }

    /**
     * The warning filter evaluates every ticket of the view, not just the
     * page, and the prefetch covers that pass too.
     */
    public function test_warning_filter_reads_do_not_grow_with_the_departments(): void {
        $this->tickets(ticket_service::PAGE_SIZE, $this->departments(2));
        $few = $this->measure('view=all&sla=2');

        $this->tickets(ticket_service::PAGE_SIZE, $this->departments(8));
        $many = $this->measure('view=all&sla=2');

        $this->assertSame($few['reads'], $many['reads']);
    }

    /**
     * And the overrides still apply per department: a row's SLA label is
     * computed against its own department's threshold, not the global one.
     */
    public function test_department_overrides_still_apply(): void {
        global $DB;

        set_config('sla_response_hours_normal', 1, 'local_moodesk');
        [$strict, $lenient] = $this->departments(2);
        $this->redirectHook(\local_moodesk\hook\sla_overrides::class, static function ($hook) use ($strict, $lenient): void {
            $hook->set_hours($strict, 'response_normal', 10);
            $hook->set_hours($lenient, 'response_normal', 11);
        });
        // Department 0 answers in 10 h, department 1 in 11 h; the ticket is 2 h
        // old, so under the global 1 h threshold both would already be breached.
        $this->tickets(2, [$strict, $lenient]);
        $DB->set_field('local_moodesk_ticket', 'timecreated', time() - 2 * HOURSECS, []);

        $rows = $this->measure()['context']['tickets'];

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertFalse($row['sla_breach'], 'Department override must win over the global threshold');
        }
    }
}
