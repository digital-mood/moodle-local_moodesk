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
 * Tests for the dashboard service: scope enforcement, empty-state honesty,
 * edition gating and metric presentation.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\dashboard_filter;
use local_moodesk\domain\ticket;

/**
 * Tests for dashboard_service.
 *
 * @covers \local_moodesk\service\dashboard_service
 */
final class dashboard_service_test extends \advanced_testcase {
    /** Fixed evaluation point so every window assertion is deterministic. */
    private const NOW = 1767225600;

    /** @var dashboard_service */
    private dashboard_service $service;

    /** @var \stdClass */
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->service = new dashboard_service();
        $this->user    = $this->getDataGenerator()->create_user();
        edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /*
     * =========================================================================
     * Scope enforcement — the security boundary
     * =========================================================================
     */

    /**
     * A manager's own selections are honoured as given.
     */
    public function test_manager_filter_honours_every_criterion(): void {
        $filter = $this->service->build_filter(
            dashboard_service::VIEW_MANAGER,
            (int) $this->user->id,
            dashboard_filter::RANGE_7,
            11,
            33,
            self::NOW
        );

        $this->assertSame(11, $filter->get_departmentid());
        $this->assertSame(33, $filter->get_agentid());
        $this->assertNull($filter->get_requesterid());
        $this->assertSame(7, $filter->get_range_days());
    }

    /**
     * An agent's filter is pinned to their own tickets, and the incoming
     * criteria are discarded rather than merged. A URL parameter must not be
     * able to widen the scope its owner was granted.
     */
    public function test_agent_filter_cannot_be_widened_by_parameters(): void {
        $other = $this->getDataGenerator()->create_user();

        $filter = $this->service->build_filter(
            dashboard_service::VIEW_AGENT,
            (int) $this->user->id,
            dashboard_filter::RANGE_30,
            11,
            (int) $other->id,
            self::NOW
        );

        $this->assertSame((int) $this->user->id, $filter->get_agentid());
        $this->assertNull($filter->get_departmentid());
    }

    /**
     * A requester sees the tickets they raised, whoever is working them.
     */
    public function test_requester_filter_is_pinned_to_their_own_tickets(): void {
        $filter = $this->service->build_filter(
            dashboard_service::VIEW_REQUESTER,
            (int) $this->user->id,
            dashboard_filter::RANGE_30,
            11,
            33,
            self::NOW
        );

        $this->assertSame((int) $this->user->id, $filter->get_requesterid());
        $this->assertNull($filter->get_agentid());
        $this->assertNull($filter->get_departmentid());
    }

    /**
     * An unsupported range lands on the default rather than an error.
     */
    public function test_unsupported_range_falls_back_to_the_default(): void {
        $filter = $this->service->build_filter(
            dashboard_service::VIEW_MANAGER,
            (int) $this->user->id,
            999,
            null,
            null,
            self::NOW
        );

        $this->assertSame(dashboard_filter::RANGE_30, $filter->get_range_days());
    }

    /**
     * The requester view really does exclude other people's tickets.
     */
    public function test_requester_view_reports_only_their_own_tickets(): void {
        $other = $this->getDataGenerator()->create_user();

        $this->make_ticket(['userid' => $this->user->id]);
        $this->make_ticket(['userid' => $this->user->id]);
        $this->make_ticket(['userid' => $other->id]);

        $context = $this->service->get_dashboard(
            $this->service->build_filter(
                dashboard_service::VIEW_REQUESTER,
                (int) $this->user->id,
                dashboard_filter::RANGE_30,
                null,
                null,
                self::NOW
            ),
            dashboard_service::VIEW_REQUESTER,
            self::NOW
        );

        $this->assertSame(2, $this->kpi($context, 'active')['value']);
    }

    /*
     * =========================================================================
     * Zero versus no data
     * =========================================================================
     */

    /**
     * A count of zero is real data and renders as a number.
     */
    public function test_zero_active_tickets_renders_as_zero(): void {
        $context = $this->dashboard();
        $active  = $this->kpi($context, 'active');

        $this->assertTrue($active['has_data']);
        $this->assertSame('0', $active['display']);
    }

    /**
     * An average with no responses is not zero seconds. It is no data, and it
     * says so instead of claiming a performance nobody achieved.
     */
    public function test_response_time_with_no_responses_is_no_data(): void {
        $this->make_ticket(['timefirstresponse' => null]);

        $response = $this->kpi($this->dashboard(), 'response');

        $this->assertFalse($response['has_data']);
        $this->assertSame(0, $response['sample']);
        $this->assertNotSame('0', $response['display']);
    }

    /**
     * SLA compliance over zero resolved tickets is not zero per cent — that
     * would slander a team that simply had nothing to resolve.
     */
    public function test_sla_compliance_with_nothing_resolved_is_no_data(): void {
        $this->make_ticket(['status' => ticket::STATUS_OPEN]);

        $context = $this->dashboard();

        $this->assertFalse($this->kpi($context, 'compliance')['has_data']);
    }

    /**
     * Panels with nothing in them are available but flagged as empty, so the
     * UI can say "nothing yet" rather than render a blank box.
     */
    public function test_empty_panels_are_available_but_flagged(): void {
        $context = $this->dashboard();

        foreach (['status', 'priority', 'departments', 'aging', 'activity'] as $panel) {
            $this->assertTrue($context[$panel]['is_available'], "{$panel} should be available");
            $this->assertFalse($context[$panel]['has_data'], "{$panel} should report no data");
        }
    }

    /*
     * =========================================================================
     * Deltas
     * =========================================================================
     */

    /**
     * Active and unassigned ship without a delta: both are snapshots, and
     * mooDesk keeps no history to compare them against.
     */
    public function test_snapshot_kpis_carry_no_delta(): void {
        $this->make_ticket();

        $context = $this->dashboard();

        $this->assertFalse($this->kpi($context, 'active')['has_delta']);
        $this->assertFalse($this->kpi($context, 'unassigned')['has_delta']);
        $this->assertFalse($this->kpi($context, 'breaches')['has_delta']);
    }

    /**
     * Resolved-this-week compares against last week and knows that resolving
     * more is good news.
     */
    public function test_resolved_this_week_compares_against_last_week(): void {
        $now      = self::NOW;
        $thisweek = $this->start_of_week($now);
        $lastweek = $thisweek - (7 * DAYSECS);

        $this->make_ticket(['status' => ticket::STATUS_RESOLVED, 'timeresolved' => $thisweek + 100]);
        $this->make_ticket(['status' => ticket::STATUS_RESOLVED, 'timeresolved' => $thisweek + 200]);
        $this->make_ticket(['status' => ticket::STATUS_RESOLVED, 'timeresolved' => $lastweek + 100]);

        $resolved = $this->kpi($this->dashboard($now), 'resolvedweek');

        $this->assertSame(2, $resolved['value']);
        $this->assertTrue($resolved['has_delta']);
        $this->assertTrue($resolved['delta_up']);
        $this->assertTrue($resolved['is_improvement']);
        $this->assertTrue($resolved['delta_good']);
        $this->assertFalse($resolved['delta_bad']);
        $this->assertSame(get_string('dashboard_delta_increase', 'local_moodesk'), $resolved['delta_label']);
    }

    /**
     * A figure that did not move is neither good news nor bad, and says so.
     *
     * The template cannot work this out for itself: is_improvement is null
     * here, and an inverted section reads null as "not an improvement" — which
     * would paint an unchanged week in the failure colour.
     */
    public function test_a_delta_that_did_not_move_is_neither_good_nor_bad(): void {
        $now      = self::NOW;
        $thisweek = $this->start_of_week($now);
        $lastweek = $thisweek - (7 * DAYSECS);

        $this->make_ticket(['status' => ticket::STATUS_RESOLVED, 'timeresolved' => $thisweek + 100]);
        $this->make_ticket(['status' => ticket::STATUS_RESOLVED, 'timeresolved' => $lastweek + 100]);

        $resolved = $this->kpi($this->dashboard($now), 'resolvedweek');

        $this->assertTrue($resolved['has_delta']);
        $this->assertFalse($resolved['delta_up']);
        $this->assertFalse($resolved['delta_down']);
        $this->assertFalse($resolved['delta_good']);
        $this->assertFalse($resolved['delta_bad']);
        $this->assertSame(get_string('dashboard_delta_flat', 'local_moodesk'), $resolved['delta_label']);
    }

    /*
     * =========================================================================
     * Edition gating
     * =========================================================================
     */

    /**
     * Starter keeps a dashboard it can run a helpdesk from: KPIs, status,
     * priority, department, aging, trends and activity all stay.
     */
    public function test_starter_keeps_the_operational_panels(): void {
        edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);
        $this->make_ticket();

        $context = $this->dashboard();

        foreach (['status', 'priority', 'departments', 'aging', 'trend', 'activity'] as $panel) {
            $this->assertTrue($context[$panel]['is_available'], "{$panel} must survive in Starter");
        }
    }

    /*
     * =========================================================================
     * Panels
     * =========================================================================
     */

    /**
     * The trend fills days with no tickets, because a line chart needs a point
     * for every day even though SQL should not invent rows.
     */
    public function test_trend_fills_days_with_no_activity(): void {
        $this->make_ticket(['timecreated' => self::NOW - (2 * DAYSECS)]);

        $trend = $this->dashboard()['trend'];

        // A 30-day window covers 31 day buckets, both ends included.
        $this->assertCount(31, $trend['points']);
        $this->assertTrue($trend['has_data']);
        $this->assertSame(1, $trend['total_created']);
    }

    /**
     * The trend states whether the backlog is growing rather than making the
     * reader compare two lines by eye.
     */
    public function test_trend_reports_a_rising_backlog(): void {
        $this->make_ticket(['timecreated' => self::NOW - DAYSECS]);
        $this->make_ticket(['timecreated' => self::NOW - DAYSECS]);
        $this->make_ticket([
            'status'       => ticket::STATUS_RESOLVED,
            'timecreated'  => self::NOW - DAYSECS,
            'timeresolved' => self::NOW - DAYSECS + 60,
        ]);

        $trend = $this->dashboard()['trend'];

        $this->assertSame(3, $trend['total_created']);
        $this->assertSame(1, $trend['total_resolved']);
        $this->assertSame(2, $trend['backlog_delta']);
        $this->assertTrue($trend['backlog_rising']);
    }

    /**
     * Status rows carry both a count and its share of the active total.
     */
    public function test_status_panel_reports_shares(): void {
        $this->make_ticket(['status' => ticket::STATUS_OPEN]);
        $this->make_ticket(['status' => ticket::STATUS_OPEN]);
        $this->make_ticket(['status' => ticket::STATUS_INPROGRESS]);
        $this->make_ticket(['status' => ticket::STATUS_ON_HOLD]);

        $status = $this->dashboard()['status'];

        $this->assertSame(4, $status['total']);
        $rows = $this->index_rows($status['rows'], 'key');
        $this->assertSame(2, $rows[ticket::STATUS_OPEN]['count']);
        $this->assertSame(50, $rows[ticket::STATUS_OPEN]['pct']);
        $this->assertSame('25%', $rows[ticket::STATUS_INPROGRESS]['pct_display']);
    }

    /**
     * Department bars stack by status and rank the busiest department first.
     */
    public function test_department_panel_stacks_and_ranks(): void {
        $busy  = $this->make_department('Development');
        $quiet = $this->make_department('Billing');

        $this->make_ticket(['departmentid' => $busy, 'status' => ticket::STATUS_OPEN]);
        $this->make_ticket(['departmentid' => $busy, 'status' => ticket::STATUS_OPEN]);
        $this->make_ticket(['departmentid' => $busy, 'status' => ticket::STATUS_INPROGRESS]);
        $this->make_ticket(['departmentid' => $quiet, 'status' => ticket::STATUS_OPEN]);

        $departments = $this->dashboard()['departments'];

        $this->assertSame('Development', $departments['rows'][0]['label']);
        $this->assertSame(3, $departments['rows'][0]['total']);
        $this->assertCount(2, $departments['rows'][0]['segments']);
        $this->assertSame(100, $departments['rows'][0]['width']);
        // The quiet department's bar is shorter, but its own stack is full.
        $this->assertSame(33, $departments['rows'][1]['width']);
        $this->assertSame(100, $departments['rows'][1]['segments'][0]['pct']);
    }

    /**
     * Aging bands are labelled by age, and every band is present.
     */
    public function test_aging_panel_labels_every_band(): void {
        $this->make_ticket(['timecreated' => self::NOW - (2 * HOURSECS)]);
        $this->make_ticket(['timecreated' => self::NOW - (20 * DAYSECS)]);

        $aging = $this->dashboard()['aging'];

        $this->assertCount(5, $aging['rows']);
        $this->assertSame(2, $aging['total']);
        $this->assertSame(1, $aging['rows'][0]['count']);
        $this->assertSame(1, $aging['rows'][4]['count']);
        $this->assertStringContainsString('24', $aging['rows'][0]['label']);
        $this->assertStringContainsString('14', $aging['rows'][4]['label']);
    }

    /**
     * Recent activity links to each ticket and shows its current state.
     */
    public function test_recent_activity_links_to_tickets(): void {
        $id = $this->make_ticket([
            'subject'      => 'API integration not working',
            'priority'     => ticket::PRIORITY_HIGH,
            'timemodified' => self::NOW - 100,
        ]);

        $row = $this->dashboard()['activity']['rows'][0];

        $this->assertSame($id, $row['id']);
        $this->assertSame('#' . $id, $row['ref']);
        $this->assertSame('API integration not working', $row['subject']);
        $this->assertStringContainsString('view.php', $row['url']);
        $this->assertTrue($row['show_priority'], 'high priority is worth a badge');
    }

    /**
     * Normal and low priority do not earn a badge — only what is worth
     * interrupting for does.
     */
    public function test_recent_activity_badges_only_urgent_work(): void {
        $this->make_ticket(['priority' => ticket::PRIORITY_NORMAL, 'timemodified' => self::NOW - 100]);

        $this->assertFalse($this->dashboard()['activity']['rows'][0]['show_priority']);
    }

    /*
     * =========================================================================
     * Filters that do not apply conceptually
     * =========================================================================
     */

    /**
     * Under an agent criterion the unassigned count is zero by construction,
     * so the tile is withheld rather than shown as a number that reads as news.
     */
    public function test_unassigned_kpi_is_withheld_under_an_agent_criterion(): void {
        $agent = $this->getDataGenerator()->create_user();
        $this->make_ticket(['assigneeid' => null]);

        $filter = $this->service->build_filter(
            dashboard_service::VIEW_MANAGER,
            (int) $this->user->id,
            dashboard_filter::RANGE_30,
            null,
            (int) $agent->id,
            self::NOW
        );

        $context = $this->service->get_dashboard($filter, dashboard_service::VIEW_MANAGER, self::NOW);

        $this->assertNull($this->kpi($context, 'unassigned'));
    }

    /*
     * =========================================================================
     * Caching
     * =========================================================================
     */

    /**
     * The cache must be transparent: a second call returns the same answer.
     */
    public function test_cache_does_not_change_the_answer(): void {
        $this->make_ticket();

        $first  = $this->dashboard();
        $second = $this->dashboard();

        $this->assertEquals($first, $second);
    }

    /**
     * The cache must actually be read back.
     *
     * A dashboard's window ends at "now", so keying on the raw bounds gives
     * every render a unique key: the cache stores diligently and never once
     * hits. Bucketing the window to the minute is what fixes that, and this
     * asserts the effect rather than the mechanism — a second render of the
     * same panel must reach the database far less than the first.
     *
     * The clock is frozen inside a minute. Two renders either side of a new
     * minute rightly miss, and against the real clock this test failed whenever
     * a slow run crossed one.
     */
    public function test_cache_is_actually_hit_on_a_second_render(): void {
        global $DB;

        for ($i = 0; $i < 5; $i++) {
            $this->make_ticket();
        }

        // Twenty seconds into a minute, well clear of either boundary.
        $clock = $this->mock_clock_with_frozen((int) (floor(time() / MINSECS) * MINSECS) + 20);

        // Two renders a few seconds apart, as two page loads would be. Passing
        // no explicit "now" is the point: this exercises the service's own time
        // source, Moodle's clock.
        $filter = $this->service->build_filter(
            dashboard_service::VIEW_MANAGER,
            (int) $this->user->id,
            dashboard_filter::RANGE_30
        );

        $before = $DB->perf_get_reads();
        $this->service->get_dashboard($filter, dashboard_service::VIEW_MANAGER);
        $cold = $DB->perf_get_reads() - $before;

        $clock->bump(5);

        $warmfilter = $this->service->build_filter(
            dashboard_service::VIEW_MANAGER,
            (int) $this->user->id,
            dashboard_filter::RANGE_30
        );

        $before = $DB->perf_get_reads();
        $this->service->get_dashboard($warmfilter, dashboard_service::VIEW_MANAGER);
        $warm = $DB->perf_get_reads() - $before;

        $this->assertGreaterThan(5, $cold, 'a cold render should hit the database properly');
        $this->assertLessThan(
            $cold / 2,
            $warm,
            "second render cost {$warm} reads against a cold {$cold} — the cache is not being hit"
        );
    }

    /**
     * Two viewers with different scopes must never share a cache entry — the
     * cache cannot be allowed to widen what a user is permitted to see.
     */
    public function test_cache_never_leaks_across_scopes(): void {
        $agent = $this->getDataGenerator()->create_user();

        $this->make_ticket(['assigneeid' => $agent->id]);
        $this->make_ticket(['assigneeid' => null]);
        $this->make_ticket(['assigneeid' => null]);

        $manager = $this->service->get_dashboard(
            $this->service->build_filter(
                dashboard_service::VIEW_MANAGER,
                (int) $this->user->id,
                dashboard_filter::RANGE_30,
                null,
                null,
                self::NOW
            ),
            dashboard_service::VIEW_MANAGER,
            self::NOW
        );

        $agentview = $this->service->get_dashboard(
            $this->service->build_filter(
                dashboard_service::VIEW_AGENT,
                (int) $agent->id,
                dashboard_filter::RANGE_30,
                null,
                null,
                self::NOW
            ),
            dashboard_service::VIEW_AGENT,
            self::NOW
        );

        $this->assertSame(3, $this->kpi($manager, 'active')['value']);
        $this->assertSame(1, $this->kpi($agentview, 'active')['value']);
    }

    /**
     * The trend scales both series against the busiest day, so the two share
     * one axis and the created-versus-resolved comparison actually holds.
     */
    public function test_trend_bar_heights_share_one_scale(): void {
        $day = self::NOW - (2 * DAYSECS);

        $this->make_ticket(['timecreated' => $day]);
        $this->make_ticket(['timecreated' => $day]);
        $this->make_ticket([
            'status'       => ticket::STATUS_RESOLVED,
            'timecreated'  => $day,
            'timeresolved' => $day + 60,
        ]);

        $peakday = null;
        foreach ($this->dashboard()['trend']['points'] as $point) {
            if ($point['created'] > 0) {
                $peakday = $point;
            }
        }

        $this->assertNotNull($peakday);
        $this->assertSame(3, $peakday['created']);
        $this->assertSame(1, $peakday['resolved']);
        $this->assertSame(100, $peakday['created_pct'], 'the busiest series sets the scale');
        $this->assertSame(33, $peakday['resolved_pct'], 'and the other is measured against it');
    }

    /*
     * =========================================================================
     * Fixtures
     * =========================================================================
     */

    /**
     * Render the manager dashboard at a fixed point in time.
     *
     * @param int|null $now
     * @return array
     */
    private function dashboard(?int $now = null): array {
        $now = $now ?? self::NOW;

        $filter = $this->service->build_filter(
            dashboard_service::VIEW_MANAGER,
            (int) $this->user->id,
            dashboard_filter::RANGE_30,
            null,
            null,
            $now
        );

        return $this->service->get_dashboard($filter, dashboard_service::VIEW_MANAGER, $now);
    }

    /**
     * Find a KPI by key, or null when it was withheld.
     *
     * @param array  $context
     * @param string $key
     * @return array|null
     */
    private function kpi(array $context, string $key): ?array {
        foreach ($context['kpis'] as $kpi) {
            if ($kpi['key'] === $key) {
                return $kpi;
            }
        }

        return null;
    }

    /**
     * Index presenter rows by one of their fields.
     *
     * @param array  $rows
     * @param string $field
     * @return array
     */
    private function index_rows(array $rows, string $field): array {
        $out = [];
        foreach ($rows as $row) {
            $out[$row[$field]] = $row;
        }
        return $out;
    }

    /**
     * Start of the ISO week containing the given timestamp.
     *
     * @param int $now
     * @return int
     */
    private function start_of_week(int $now): int {
        $date = usergetdate($now);
        $dow  = (int) $date['wday'];
        $since = ($dow === 0) ? 6 : ($dow - 1);

        return mktime(0, 0, 0, $date['mon'], $date['mday'] - $since, $date['year']);
    }

    /**
     * Insert a department and return its id.
     *
     * @param string $name
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
     * Insert a ticket, overriding any of the defaults.
     *
     * @param array $overrides
     * @return int
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
            'timecreated'  => self::NOW - HOURSECS,
            'timemodified' => self::NOW - HOURSECS,
            'usermodified' => $this->user->id,
        ];

        return (int) $DB->insert_record('local_moodesk_ticket', (object) array_merge($base, $overrides));
    }
}
