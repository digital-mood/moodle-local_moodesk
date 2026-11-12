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
 * Domain unit tests for the dashboard metric value object.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Tests for dashboard_metric.
 *
 * @covers \local_moodesk\domain\dashboard_metric
 */
final class dashboard_metric_test extends \basic_testcase {
    /**
     * A count of zero is a measurement — zero active tickets is a fact.
     */
    public function test_zero_count_has_data(): void {
        $metric = dashboard_metric::count(0);

        $this->assertTrue($metric->is_available());
        $this->assertTrue($metric->has_data());
        $this->assertSame(0, $metric->get_value());
        $this->assertSame(dashboard_metric::STATE_OK, $metric->get_state());
    }

    /**
     * An average over an empty sample is not zero — nothing was measured.
     */
    public function test_average_over_empty_sample_is_no_data(): void {
        $metric = dashboard_metric::average(null, 0);

        $this->assertTrue($metric->is_available());
        $this->assertFalse($metric->has_data());
        $this->assertNull($metric->get_value());
        $this->assertSame(dashboard_metric::STATE_NO_DATA, $metric->get_state());
    }

    /**
     * A sample size of zero yields no data even if a value was somehow passed.
     */
    public function test_average_needs_both_a_value_and_a_sample(): void {
        $this->assertFalse(dashboard_metric::average(120.0, 0)->has_data());
        $this->assertFalse(dashboard_metric::average(null, 5)->has_data());
        $this->assertTrue(dashboard_metric::average(120.0, 5)->has_data());
    }

    /**
     * An unavailable metric is distinct from one with nothing to show: the UI
     * drops the panel entirely rather than rendering an empty shell.
     */
    public function test_unavailable_is_distinct_from_no_data(): void {
        $unavailable = dashboard_metric::unavailable();
        $nodata      = dashboard_metric::no_data();

        $this->assertFalse($unavailable->is_available());
        $this->assertFalse($unavailable->has_data());

        $this->assertTrue($nodata->is_available());
        $this->assertFalse($nodata->has_data());
    }

    /**
     * Percentage change is computed against the previous period.
     */
    public function test_delta_percentage(): void {
        $this->assertEqualsWithDelta(25.0, dashboard_metric::count(25, 20)->get_delta_pct(), 0.001);
        $this->assertEqualsWithDelta(-50.0, dashboard_metric::count(10, 20)->get_delta_pct(), 0.001);
        $this->assertEqualsWithDelta(0.0, dashboard_metric::count(20, 20)->get_delta_pct(), 0.001);
    }

    /**
     * Growth from zero is undefined, not infinite — no delta is offered at all,
     * because "↑∞%" tells a reader nothing.
     */
    public function test_no_delta_against_a_zero_previous_period(): void {
        $metric = dashboard_metric::count(25, 0);

        $this->assertNull($metric->get_delta_pct());
        $this->assertFalse($metric->has_delta());
        $this->assertNull($metric->get_delta_direction());
    }

    /**
     * With no previous value there is nothing to compare — this is how active
     * and unassigned ship, both being snapshots with no history to measure.
     */
    public function test_no_delta_without_a_previous_value(): void {
        $metric = dashboard_metric::count(25);

        $this->assertFalse($metric->has_delta());
        $this->assertNull($metric->get_delta_pct());
    }

    /**
     * A metric with nothing measured offers no delta either.
     */
    public function test_no_delta_when_there_is_no_data(): void {
        $this->assertFalse(dashboard_metric::average(null, 0, 100.0)->has_delta());
    }

    /**
     * Whether a rise is good news depends on the metric, not the direction.
     */
    public function test_improvement_accounts_for_polarity(): void {
        // Resolving more tickets is good news.
        $resolved = dashboard_metric::count(30, 20, true);
        $this->assertSame(dashboard_metric::DIRECTION_UP, $resolved->get_delta_direction());
        $this->assertTrue($resolved->is_improvement());

        // Breaching more SLAs is the same direction and the opposite news.
        $breaches = dashboard_metric::count(30, 20, false);
        $this->assertSame(dashboard_metric::DIRECTION_UP, $breaches->get_delta_direction());
        $this->assertFalse($breaches->is_improvement());

        // A faster response time falls, and that is good.
        $response = dashboard_metric::average(100.0, 5, 200.0, false);
        $this->assertSame(dashboard_metric::DIRECTION_DOWN, $response->get_delta_direction());
        $this->assertTrue($response->is_improvement());
    }

    /**
     * A flat reading is neither good nor bad news.
     */
    public function test_flat_movement_is_not_an_improvement(): void {
        $metric = dashboard_metric::count(20, 20, true);

        $this->assertSame(dashboard_metric::DIRECTION_FLAT, $metric->get_delta_direction());
        $this->assertNull($metric->is_improvement());
    }

    /**
     * A negative previous period would invert the sign of every delta if the
     * divisor were not taken as an absolute value.
     */
    public function test_delta_uses_the_magnitude_of_the_previous_value(): void {
        $metric = dashboard_metric::average(5.0, 3, -10.0, true);

        // 5 is 150% above -10 in magnitude terms, and the direction is up.
        $this->assertEqualsWithDelta(150.0, $metric->get_delta_pct(), 0.001);
        $this->assertSame(dashboard_metric::DIRECTION_UP, $metric->get_delta_direction());
    }
}
