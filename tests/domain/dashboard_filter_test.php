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
 * Domain unit tests for the dashboard filter value object.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Tests for dashboard_filter.
 *
 * @covers \local_moodesk\domain\dashboard_filter
 */
final class dashboard_filter_test extends \basic_testcase {
    /** Fixed evaluation point so every window assertion is deterministic. */
    private const NOW = 1767225600;

    public function test_from_days_spans_the_requested_window(): void {
        $filter = dashboard_filter::from_days(
            dashboard_filter::RANGE_30,
            dashboard_filter::ANY,
            dashboard_filter::ANY,
            self::NOW
        );

        $this->assertSame(self::NOW - (30 * DAYSECS), $filter->get_from());
        $this->assertSame(self::NOW, $filter->get_to());
        $this->assertSame(30, $filter->get_range_days());
    }

    public function test_unsupported_range_falls_back_to_thirty_days(): void {
        // The range arrives from a URL parameter; a hand-edited value must land
        // on the default dashboard rather than an error page.
        $this->assertSame(dashboard_filter::RANGE_30, dashboard_filter::validate_days(45));
        $this->assertSame(dashboard_filter::RANGE_30, dashboard_filter::validate_days(0));
        $this->assertSame(dashboard_filter::RANGE_30, dashboard_filter::validate_days(-7));

        $this->assertSame(dashboard_filter::RANGE_7, dashboard_filter::validate_days(7));
        $this->assertSame(dashboard_filter::RANGE_90, dashboard_filter::validate_days(90));
    }

    public function test_reversed_window_is_normalised(): void {
        $filter = new dashboard_filter(self::NOW, self::NOW - 100);

        $this->assertSame(self::NOW - 100, $filter->get_from());
        $this->assertSame(self::NOW, $filter->get_to());
    }

    public function test_previous_period_abuts_without_overlapping(): void {
        $filter   = new dashboard_filter(1000, 1999);
        $previous = $filter->previous_period();

        // Same length, ending exactly one second before the current window opens.
        $this->assertSame(1999, $previous->get_to() + $filter->duration());
        $this->assertSame(999, $previous->get_to());
        $this->assertSame(0, $previous->get_from());
        $this->assertSame($filter->duration(), $previous->duration());
    }

    public function test_derived_filters_keep_the_scope(): void {
        $filter = new dashboard_filter(1000, 2000, 5, 9);

        foreach ([$filter->with_range(1, 2), $filter->previous_period()] as $derived) {
            $this->assertSame(5, $derived->get_departmentid());
            $this->assertSame(9, $derived->get_agentid());
        }
    }

    public function test_zero_department_and_agent_stay_meaningful(): void {
        // Zero is a real criterion for these two: tickets with no department,
        // and tickets with no assignee.
        $filter = new dashboard_filter(1000, 2000, dashboard_filter::NONE, dashboard_filter::NONE);

        $this->assertSame(dashboard_filter::NONE, $filter->get_departmentid());
        $this->assertSame(dashboard_filter::NONE, $filter->get_agentid());
        $this->assertTrue($filter->has_scope());
    }

    public function test_is_agent_scoped_only_for_a_real_agent(): void {
        $unfiltered = new dashboard_filter(1000, 2000);
        $unassigned = new dashboard_filter(1000, 2000, null, dashboard_filter::NONE);
        $oneagent   = new dashboard_filter(1000, 2000, null, 42);

        $this->assertFalse($unfiltered->is_agent_scoped());
        $this->assertFalse($unassigned->is_agent_scoped());
        $this->assertTrue($oneagent->is_agent_scoped());
    }

    public function test_duration_counts_both_ends(): void {
        $this->assertSame(1, (new dashboard_filter(500, 500))->duration());
        $this->assertSame(101, (new dashboard_filter(500, 600))->duration());
    }
}
