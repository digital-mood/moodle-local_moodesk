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
 * Integration tests for the dashboard aggregate queries.
 *
 * Like the report repository tests, these are primarily a cross-database
 * guard: every query here is hand-written SQL with GROUP BY clauses that has
 * to behave identically on MySQL/MariaDB and PostgreSQL.
 *
 * Beyond that they pin down the two behaviours the dashboard is built on:
 * snapshot metrics ignore the date range, and every metric honours the
 * department / team / agent scope.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\dashboard_filter;
use local_moodesk\domain\ticket;

/**
 * Tests the aggregate queries backing the operational dashboard.
 *
 * @covers \local_moodesk\repository\dashboard_repository
 */
final class dashboard_repository_test extends \advanced_testcase {
    /** Fixed evaluation point so every window assertion is deterministic. */
    private const NOW = 1767225600;

    /** @var dashboard_repository */
    private dashboard_repository $repo;

    /** @var \stdClass */
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->repo = new dashboard_repository();
        $this->user = $this->getDataGenerator()->create_user();
    }

    /*
     * =========================================================================
     * Headline counters
     * =========================================================================
     */

    /**
     * Active tickets are counted; resolved and closed ones are not.
     */
    public function test_count_active_excludes_resolved_and_closed(): void {
        $this->make_ticket(['status' => ticket::STATUS_NEW]);
        $this->make_ticket(['status' => ticket::STATUS_OPEN]);
        $this->make_ticket(['status' => ticket::STATUS_INPROGRESS]);
        $this->make_ticket(['status' => ticket::STATUS_RESOLVED]);
        $this->make_ticket(['status' => ticket::STATUS_CLOSED]);

        $this->assertSame(3, $this->repo->count_active());
    }

    /**
     * Only active tickets with no assignee count as unassigned.
     */
    public function test_count_unassigned_active(): void {
        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'assigneeid' => null]);
        $this->make_ticket(['status' => ticket::STATUS_NEW, 'assigneeid' => null]);
        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'assigneeid' => $this->user->id]);
        // Unassigned but no longer active — must not count.
        $this->make_ticket(['status' => ticket::STATUS_CLOSED, 'assigneeid' => null]);

        $this->assertSame(2, $this->repo->count_unassigned_active());
    }

    /**
     * Breached counts cover active tickets only.
     */
    public function test_count_sla_breached_active(): void {
        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'sla_breached' => 1]);
        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'sla_breached' => 0]);
        $this->make_ticket(['status' => ticket::STATUS_CLOSED, 'sla_breached' => 1]);

        $this->assertSame(1, $this->repo->count_sla_breached_active());
    }

    /**
     * Resolution counting keys off timeresolved inside the window.
     */
    public function test_count_resolved_within_window(): void {
        $filter = $this->range(self::NOW, self::NOW + 1000);

        $this->make_ticket(['status' => ticket::STATUS_RESOLVED, 'timeresolved' => self::NOW + 100]);
        $this->make_ticket(['status' => ticket::STATUS_CLOSED, 'timeresolved' => self::NOW + 200]);
        // Resolved outside the window.
        $this->make_ticket(['status' => ticket::STATUS_RESOLVED, 'timeresolved' => self::NOW - 100]);
        // Never resolved.
        $this->make_ticket(['status' => ticket::STATUS_OPEN]);

        $this->assertSame(2, $this->repo->count_resolved($filter));
    }

    /**
     * A ticket closed by a merge carries timeclosed but never timeresolved, so
     * it must stay out of every resolution metric — a merge is not a fix.
     */
    public function test_merged_away_ticket_is_not_a_resolution(): void {
        $filter = $this->range(self::NOW, self::NOW + 1000);

        $this->make_ticket([
            'status'       => ticket::STATUS_CLOSED,
            'timeclosed'   => self::NOW + 100,
            'timeresolved' => null,
        ]);

        $this->assertSame(0, $this->repo->count_resolved($filter));
        $this->assertSame(0, $this->repo->get_sla_compliance($filter)->total);
    }

    /*
     * =========================================================================
     * Scope: department, team, agent
     * =========================================================================
     */

    /**
     * Each scope criterion narrows the snapshot counters independently.
     */
    public function test_scope_criteria_narrow_snapshot_counts(): void {
        $support = $this->make_department('Support');
        $billing = $this->make_department('Billing');
        $agent   = $this->getDataGenerator()->create_user();

        $this->make_ticket(['departmentid' => $support, 'assigneeid' => $agent->id]);
        $this->make_ticket(['departmentid' => $support, 'assigneeid' => null]);
        $this->make_ticket(['departmentid' => $billing, 'assigneeid' => $agent->id]);
        $this->make_ticket(['departmentid' => null, 'assigneeid' => null]);

        $this->assertSame(4, $this->repo->count_active());
        $this->assertSame(2, $this->repo->count_active($this->scope($support, null)));
        $this->assertSame(2, $this->repo->count_active($this->scope(null, $agent->id)));
        $this->assertSame(1, $this->repo->count_active($this->scope($support, $agent->id)));
    }

    /**
     * A zero department or agent criterion means "records with none set", which
     * is a question an operations panel genuinely asks: what is unrouted?
     */
    public function test_zero_criteria_select_records_with_none_set(): void {
        $support = $this->make_department('Support');
        $agent   = $this->getDataGenerator()->create_user();

        $this->make_ticket(['departmentid' => $support, 'assigneeid' => $agent->id]);
        $this->make_ticket(['departmentid' => null, 'assigneeid' => null]);
        $this->make_ticket(['departmentid' => null, 'assigneeid' => null]);

        $this->assertSame(2, $this->repo->count_active($this->scope(dashboard_filter::NONE, null)));
        $this->assertSame(2, $this->repo->count_active($this->scope(null, dashboard_filter::NONE)));
    }

    /**
     * Snapshot metrics describe the present, so the window must not touch them:
     * a ticket open for a year is exactly what a 7-day view needs to surface.
     */
    public function test_snapshot_counts_ignore_the_date_range(): void {
        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'timecreated' => self::NOW - (400 * DAYSECS)]);

        $narrow = dashboard_filter::from_days(dashboard_filter::RANGE_7, null, null, self::NOW);

        $this->assertSame(1, $this->repo->count_active($narrow));
        $this->assertSame(1, $this->repo->count_unassigned_active($narrow));
        $this->assertCount(1, $this->repo->count_active_by_status($narrow));
    }

    /*
     * =========================================================================
     * Breakdowns
     * =========================================================================
     */

    /**
     * Status breakdown groups active tickets and leaves inactive ones out.
     */
    public function test_count_active_by_status_groups_correctly(): void {
        $this->make_ticket(['status' => ticket::STATUS_NEW]);
        $this->make_ticket(['status' => ticket::STATUS_NEW]);
        $this->make_ticket(['status' => ticket::STATUS_OPEN]);
        $this->make_ticket(['status' => ticket::STATUS_CLOSED]);

        $counts = [];
        foreach ($this->repo->count_active_by_status() as $row) {
            $counts[(int) $row->status] = (int) $row->cnt;
        }

        $this->assertSame(2, $counts[ticket::STATUS_NEW]);
        $this->assertSame(1, $counts[ticket::STATUS_OPEN]);
        $this->assertArrayNotHasKey(ticket::STATUS_CLOSED, $counts);
    }

    /**
     * Priority breakdown groups active tickets by priority.
     */
    public function test_count_active_by_priority_groups_correctly(): void {
        $this->make_ticket(['priority' => ticket::PRIORITY_URGENT]);
        $this->make_ticket(['priority' => ticket::PRIORITY_URGENT]);
        $this->make_ticket(['priority' => ticket::PRIORITY_LOW]);
        $this->make_ticket(['status' => ticket::STATUS_CLOSED, 'priority' => ticket::PRIORITY_URGENT]);

        $counts = [];
        foreach ($this->repo->count_active_by_priority() as $row) {
            $counts[(int) $row->priority] = (int) $row->cnt;
        }

        $this->assertSame(2, $counts[ticket::PRIORITY_URGENT]);
        $this->assertSame(1, $counts[ticket::PRIORITY_LOW]);
    }

    /**
     * The stacked department breakdown returns one row per department AND
     * status, with department-less tickets under a null id.
     */
    public function test_count_active_by_department_status_splits_by_both(): void {
        $support = $this->make_department('Support');

        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'departmentid' => $support]);
        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'departmentid' => $support]);
        $this->make_ticket(['status' => ticket::STATUS_INPROGRESS, 'departmentid' => $support]);
        $this->make_ticket(['status' => ticket::STATUS_OPEN, 'departmentid' => null]);
        $this->make_ticket(['status' => ticket::STATUS_CLOSED, 'departmentid' => $support]);

        $segments = [];
        foreach ($this->repo->count_active_by_department_status() as $row) {
            $key = ($row->departmentid === null ? 'none' : (int) $row->departmentid) . ':' . (int) $row->status;
            $segments[$key] = (int) $row->cnt;
        }

        $this->assertSame(2, $segments["{$support}:" . ticket::STATUS_OPEN]);
        $this->assertSame(1, $segments["{$support}:" . ticket::STATUS_INPROGRESS]);
        $this->assertSame(1, $segments['none:' . ticket::STATUS_OPEN]);
        $this->assertArrayNotHasKey("{$support}:" . ticket::STATUS_CLOSED, $segments);
    }

    /**
     * Two departments may legitimately share a name — the schema puts no unique
     * constraint on local_moodesk_department.name — and both must survive into
     * the breakdown. Assert on the total so a dropped row cannot pass.
     */
    public function test_department_breakdown_keeps_same_named_departments(): void {
        $first  = $this->make_department('Support');
        $second = $this->make_department('Support');

        $this->make_ticket(['departmentid' => $first]);
        $this->make_ticket(['departmentid' => $second]);
        $this->make_ticket(['departmentid' => $second]);

        $total = 0;
        foreach ($this->repo->count_active_by_department_status() as $row) {
            $total += (int) $row->cnt;
        }

        $this->assertSame(3, $total, 'every active ticket must appear in the department breakdown');
    }

    /*
     * =========================================================================
     * Ticket aging
     * =========================================================================
     */

    /**
     * Aging bands are half-open — a ticket exactly three days old belongs to
     * the 3-7 band, not to 1-3 — and every band is always present.
     */
    public function test_count_active_by_age_buckets_on_half_open_bands(): void {
        $this->make_ticket(['timecreated' => self::NOW - (2 * HOURSECS)]);
        $this->make_ticket(['timecreated' => self::NOW - (2 * DAYSECS)]);
        $this->make_ticket(['timecreated' => self::NOW - (3 * DAYSECS)]);
        $this->make_ticket(['timecreated' => self::NOW - (30 * DAYSECS)]);
        // Resolved tickets have no age to report — they are no longer waiting.
        $this->make_ticket(['status' => ticket::STATUS_CLOSED, 'timecreated' => self::NOW - (30 * DAYSECS)]);

        $buckets = $this->repo->count_active_by_age(null, self::NOW);

        $this->assertCount(count(dashboard_repository::AGING_BUCKET_DAYS) + 1, $buckets);
        $this->assertSame(1, (int) $buckets[0]->cnt, 'under 24 hours');
        $this->assertSame(1, (int) $buckets[1]->cnt, '1-3 days');
        $this->assertSame(1, (int) $buckets[2]->cnt, '3-7 days: the boundary belongs to the upper band');
        $this->assertSame(0, (int) $buckets[3]->cnt, '7-14 days');
        $this->assertSame(1, (int) $buckets[4]->cnt, 'over 14 days');

        // The final band is open-ended and says so.
        $this->assertNull($buckets[4]->upper_days);
    }

    /**
     * With no tickets the bands still come back, all at zero, so the chart keeps
     * its shape instead of collapsing.
     */
    public function test_aging_buckets_survive_an_empty_dataset(): void {
        $buckets = $this->repo->count_active_by_age(null, self::NOW);

        $this->assertCount(count(dashboard_repository::AGING_BUCKET_DAYS) + 1, $buckets);
        foreach ($buckets as $bucket) {
            $this->assertSame(0, (int) $bucket->cnt);
        }
    }

    /*
     * =========================================================================
     * Trends
     * =========================================================================
     */

    /**
     * Created and resolved counts bucket into separate days.
     */
    public function test_created_and_resolved_bucket_by_day(): void {
        $day1 = self::NOW;
        $day2 = self::NOW + DAYSECS;
        $filter = $this->range($day1 - DAYSECS, $day2 + DAYSECS);

        $this->make_ticket(['timecreated' => $day1]);
        $this->make_ticket(['timecreated' => $day1 + 3600]);
        $this->make_ticket(['timecreated' => $day2]);
        $this->make_ticket([
            'status'       => ticket::STATUS_RESOLVED,
            'timecreated'  => $day1,
            'timeresolved' => $day2,
        ]);

        $created = $this->repo->count_created_by_day($filter);
        $this->assertCount(2, $created);
        $this->assertSame(3, (int) $created[0]->cnt);
        $this->assertSame(1, (int) $created[1]->cnt);

        $resolved = $this->repo->count_resolved_by_day($filter);
        $this->assertCount(1, $resolved);
        $this->assertSame(1, (int) $resolved[0]->cnt);
    }

    /**
     * A reopened ticket keeps the timeresolved stamp from its earlier life —
     * transition_status() never clears it — so the status guard is what stops
     * it being counted as a resolution it no longer is.
     */
    public function test_reopened_ticket_is_not_counted_as_resolved(): void {
        $filter = $this->range(self::NOW, self::NOW + DAYSECS);

        $this->make_ticket([
            'status'       => ticket::STATUS_OPEN,
            'timeresolved' => self::NOW + 100,
        ]);

        $this->assertSame(0, $this->repo->count_resolved($filter));
        $this->assertSame([], $this->repo->count_resolved_by_day($filter));
    }

    /*
     * =========================================================================
     * Durations and SLA
     * =========================================================================
     */

    /**
     * Response and resolution means report their sample size, and tickets that
     * never responded or resolved stay out of the average rather than counting
     * as zero.
     */
    public function test_duration_stats_average_only_over_real_data(): void {
        $filter = $this->range(self::NOW, self::NOW + (10 * DAYSECS));

        $this->make_ticket([
            'timecreated'       => self::NOW,
            'timefirstresponse' => self::NOW + 100,
            'timeresolved'      => self::NOW + 300,
            'status'            => ticket::STATUS_RESOLVED,
        ]);
        $this->make_ticket([
            'timecreated'       => self::NOW,
            'timefirstresponse' => self::NOW + 300,
            'timeresolved'      => self::NOW + 500,
            'status'            => ticket::STATUS_RESOLVED,
        ]);
        // Never answered, never resolved: excluded from both means.
        $this->make_ticket(['timecreated' => self::NOW, 'status' => ticket::STATUS_OPEN]);

        $response = $this->repo->get_first_response_stats($filter);
        $this->assertSame(2, $response->sample);
        $this->assertEqualsWithDelta(200.0, $response->avg_seconds, 0.001);

        $resolution = $this->repo->get_resolution_stats($filter);
        $this->assertSame(2, $resolution->sample);
        $this->assertEqualsWithDelta(400.0, $resolution->avg_seconds, 0.001);
    }

    /**
     * With nothing to average, the answer is null — "no data" must never render
     * as an average of zero seconds.
     */
    public function test_duration_stats_report_null_not_zero_when_empty(): void {
        $filter = $this->range(self::NOW, self::NOW + DAYSECS);

        $response = $this->repo->get_first_response_stats($filter);
        $this->assertSame(0, $response->sample);
        $this->assertNull($response->avg_seconds);

        $resolution = $this->repo->get_resolution_stats($filter);
        $this->assertSame(0, $resolution->sample);
        $this->assertNull($resolution->avg_seconds);
    }

    /**
     * Compliance splits tickets resolved in the window by the stored flag.
     */
    public function test_sla_compliance_totals(): void {
        $filter = $this->range(self::NOW, self::NOW + DAYSECS);

        $this->make_ticket([
            'status' => ticket::STATUS_RESOLVED, 'timeresolved' => self::NOW + 10, 'sla_breached' => 0,
        ]);
        $this->make_ticket([
            'status' => ticket::STATUS_CLOSED, 'timeresolved' => self::NOW + 20, 'sla_breached' => 0,
        ]);
        $this->make_ticket([
            'status' => ticket::STATUS_RESOLVED, 'timeresolved' => self::NOW + 30, 'sla_breached' => 1,
        ]);
        // Outside the window.
        $this->make_ticket([
            'status' => ticket::STATUS_RESOLVED, 'timeresolved' => self::NOW - 500, 'sla_breached' => 1,
        ]);

        $compliance = $this->repo->get_sla_compliance($filter);

        $this->assertSame(3, $compliance->total);
        $this->assertSame(2, $compliance->within_sla);
        $this->assertSame(1, $compliance->breached);
    }

    /*
     * =========================================================================
     * Agent workload
     * =========================================================================
     */

    /*
     * =========================================================================
     * Categories, satisfaction and activity
     * =========================================================================
     */

    /**
     * Recent activity is ordered by last touch, newest first, and limited.
     */
    public function test_recent_activity_returns_newest_first(): void {
        $filter = $this->range(self::NOW - DAYSECS, self::NOW + DAYSECS);

        $oldest = $this->make_ticket(['subject' => 'Oldest', 'timemodified' => self::NOW - 300]);
        $middle = $this->make_ticket(['subject' => 'Middle', 'timemodified' => self::NOW - 200]);
        $newest = $this->make_ticket(['subject' => 'Newest', 'timemodified' => self::NOW - 100]);

        $rows = $this->repo->get_recent_activity($filter, 2);

        $this->assertCount(2, $rows);
        $this->assertSame($newest, (int) $rows[0]->id);
        $this->assertSame($middle, (int) $rows[1]->id);
        $this->assertSame('Newest', $rows[0]->subject);
        $this->assertNotContains($oldest, array_map(static fn($r) => (int) $r->id, $rows));
    }

    /*
     * =========================================================================
     * Empty dataset
     * =========================================================================
     */

    /**
     * With no tickets at all, every aggregate returns a zero or an empty list
     * rather than failing or returning nulls the templates cannot render.
     */
    public function test_empty_dataset_returns_zeroes(): void {
        $filter = $this->range(self::NOW, self::NOW + DAYSECS);

        $this->assertSame(0, $this->repo->count_active());
        $this->assertSame(0, $this->repo->count_unassigned_active());
        $this->assertSame(0, $this->repo->count_sla_breached_active());
        $this->assertSame(0, $this->repo->count_resolved($filter));
        $this->assertSame([], $this->repo->count_active_by_status());
        $this->assertSame([], $this->repo->count_active_by_priority());
        $this->assertSame([], $this->repo->count_active_by_department_status());
        $this->assertSame([], $this->repo->count_created_by_day($filter));
        $this->assertSame([], $this->repo->count_resolved_by_day($filter));
        $this->assertSame([], $this->repo->get_recent_activity($filter));

        $compliance = $this->repo->get_sla_compliance($filter);
        $this->assertSame(0, $compliance->total);
        $this->assertSame(0, $compliance->within_sla);
        $this->assertSame(0, $compliance->breached);
    }

    /**
     * Unassigned-queue counting spans every department the agent belongs to.
     */
    public function test_count_dept_unassigned_spans_the_agents_departments(): void {
        $agent   = $this->getDataGenerator()->create_user();
        $support = $this->make_department('Support');
        $billing = $this->make_department('Billing');
        $this->add_dept_member($support, (int) $agent->id);

        $this->make_ticket(['departmentid' => $support, 'assigneeid' => null]);
        $this->make_ticket(['departmentid' => $support, 'assigneeid' => $agent->id]);
        $this->make_ticket(['departmentid' => $billing, 'assigneeid' => null]);
        $this->make_ticket(['departmentid' => null, 'assigneeid' => null]);

        $this->assertSame(1, $this->repo->count_dept_unassigned((int) $agent->id));
    }

    /*
     * =========================================================================
     * Fixtures
     * =========================================================================
     */

    /**
     * Build a filter carrying only a window, no scope.
     *
     * @param int $from
     * @param int $to
     * @return dashboard_filter
     */
    private function range(int $from, int $to): dashboard_filter {
        return new dashboard_filter($from, $to);
    }

    /**
     * Build a filter carrying only scope; the window is wide enough to be inert.
     *
     * @param int|null $departmentid
     * @param int|null $agentid
     * @return dashboard_filter
     */
    private function scope(?int $departmentid, ?int $agentid): dashboard_filter {
        return new dashboard_filter(0, self::NOW + (365 * DAYSECS), $departmentid, $agentid);
    }

    /**
     * Insert a department and return its id.
     *
     * @param string $name Department name.
     * @return int
     */
    private function make_department(string $name): int {
        global $DB;

        return (int) $DB->insert_record('local_moodesk_department', (object) [
            'name'         => $name,
            'description'  => '',
            'isactive'     => 1,
            'timecreated'  => self::NOW,
            'timemodified' => self::NOW,
            'usermodified' => $this->user->id,
        ]);
    }

    /**
     * Add an agent to a department.
     *
     * @param int $departmentid
     * @param int $userid
     */
    private function add_dept_member(int $departmentid, int $userid): void {
        global $DB;

        $DB->insert_record('local_moodesk_dept_member', (object) [
            'departmentid' => $departmentid,
            'userid'       => $userid,
            'timecreated'  => self::NOW,
            'usermodified' => $this->user->id,
        ]);
    }

    /**
     * Insert a category and return its id.
     *
     * @param string $name Category name.
     * @return int
     */
    private function make_category(string $name): int {
        global $DB;

        return (int) $DB->insert_record('local_moodesk_category', (object) [
            'name'         => $name,
            'description'  => '',
            'sortorder'    => 0,
            'visible'      => 1,
            'timecreated'  => self::NOW,
            'usermodified' => $this->user->id,
        ]);
    }

    /**
     * Insert a ticket, overriding any of the defaults.
     *
     * @param array $overrides Field values to override.
     * @return int Inserted ticket id.
     */
    private function make_ticket(array $overrides = []): int {
        global $DB;

        $base = [
            'userid'       => $this->user->id,
            'createdby'    => $this->user->id,
            'subject'      => 'Dashboard fixture',
            'status'       => ticket::STATUS_OPEN,
            'priority'     => ticket::PRIORITY_NORMAL,
            'source'       => 0,
            'sla_breached' => 0,
            'timecreated'  => self::NOW,
            'timemodified' => self::NOW,
            'usermodified' => $this->user->id,
        ];

        return (int) $DB->insert_record('local_moodesk_ticket', (object) array_merge($base, $overrides));
    }
}
