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
 * Unit tests for multivalue URL parameter handling.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\util;

/**
 * Tests for url_params.
 *
 * Regression cover for the ticket list status filter: a multi-select status
 * travels as repeated status[] parameters, and every list URL built while it is
 * active used to hand that array straight to moodle_url, which raises
 * "Url parameters values can not be arrays!" on Moodle 4.5.
 *
 * @covers \local_moodesk\util\url_params
 */
final class url_params_test extends \basic_testcase {
    /**
     * Read a built URL back the way PHP reads an inbound request, so the
     * assertions check the contract optional_param_array() actually sees.
     *
     * @param \moodle_url $url URL under test.
     * @return array Parsed query string.
     */
    private function query_of(\moodle_url $url): array {
        $parsed = [];
        parse_str(parse_url($url->out(false), PHP_URL_QUERY) ?? '', $parsed);

        return $parsed;
    }

    public function test_single_status_builds_a_valid_url(): void {
        $url = url_params::to_url('/local/moodesk/index.php', ['status' => [1]]);

        $this->assertStringContainsString('status%5B0%5D=1', $url->out(false));
        $this->assertSame(['status' => ['1']], $this->query_of($url));
    }

    public function test_multiple_statuses_all_survive_the_round_trip(): void {
        $url = url_params::to_url('/local/moodesk/index.php', ['status' => [1, 2, 3]]);

        $this->assertSame(['status' => ['1', '2', '3']], $this->query_of($url));
    }

    public function test_status_zero_is_not_dropped(): void {
        // Status 0 is a real status (the first one), so it must not be treated as empty.
        $url = url_params::to_url('/local/moodesk/index.php', ['status' => [0, 2]]);

        $this->assertSame(['status' => ['0', '2']], $this->query_of($url));
    }

    public function test_no_array_value_ever_reaches_moodle_url(): void {
        // The sentinel for this bug. Moodle 4.5 throws "Url parameters values can
        // not be arrays!" from \core\url::params(); Moodle 5.x accepts arrays and
        // encodes them itself. Asserting on the stored params instead of on the
        // exception makes the guard hold on every supported release.
        $url = url_params::to_url('/local/moodesk/index.php', [
            'view'     => 'all',
            'status'   => [1, 2],
            'priority' => 3,
        ]);

        foreach ($url->params() as $key => $value) {
            $this->assertIsNotArray($value, "parameter {$key} reached moodle_url as an array");
        }
    }

    public function test_scalar_params_pass_through_untouched(): void {
        $url = url_params::to_url('/local/moodesk/index.php', [
            'view'     => 'all',
            'priority' => 2,
            'search'   => 'test',
        ]);

        $this->assertSame(
            ['view' => 'all', 'priority' => '2', 'search' => 'test'],
            $this->query_of($url)
        );
    }

    public function test_no_filters_builds_a_bare_url(): void {
        $url = url_params::to_url('/local/moodesk/index.php', []);

        $this->assertSame([], $this->query_of($url));
        $this->assertStringEndsWith('/local/moodesk/index.php', $url->out(false));
    }

    public function test_dismissing_one_status_keeps_the_others(): void {
        $params = url_params::without_value(['status' => [1, 2, 3]], 'status', 2);

        $this->assertSame(['status' => [1, 3]], $params);
        $this->assertSame(
            ['status' => ['1', '3']],
            $this->query_of(url_params::to_url('/local/moodesk/index.php', $params))
        );
    }

    public function test_dismissing_the_last_status_removes_the_parameter(): void {
        $params = url_params::without_value(['status' => [2]], 'status', 2);

        $this->assertArrayNotHasKey('status', $params);
        $this->assertSame([], $this->query_of(url_params::to_url('/local/moodesk/index.php', $params)));
    }

    public function test_dismissing_a_status_leaves_every_other_filter_alone(): void {
        $active = [
            'view'     => 'all',
            'search'   => 'test',
            'status'   => [1, 3],
            'priority' => 2,
        ];

        $params = url_params::without_value($active, 'status', 1);

        $this->assertSame(
            ['view' => 'all', 'search' => 'test', 'status' => ['3'], 'priority' => '2'],
            $this->query_of(url_params::to_url('/local/moodesk/index.php', $params))
        );
    }

    public function test_status_array_alongside_another_filter_does_not_raise(): void {
        // The original crash: any other chip inherited the status array and blew up
        // while building its own dismiss URL.
        $active = ['view' => 'all', 'status' => [1, 2], 'priority' => 3];
        $dismissparams = $active;
        unset($dismissparams['priority']);

        $this->assertSame(
            ['view' => 'all', 'status' => ['1', '2']],
            $this->query_of(url_params::to_url('/local/moodesk/index.php', $dismissparams))
        );
    }

    public function test_without_value_on_a_scalar_param_drops_it(): void {
        $this->assertSame(
            ['view' => 'all'],
            url_params::without_value(['view' => 'all', 'priority' => 2], 'priority', 2)
        );
    }

    public function test_without_value_on_a_missing_param_changes_nothing(): void {
        $this->assertSame(
            ['view' => 'all'],
            url_params::without_value(['view' => 'all'], 'status', 1)
        );
    }

    public function test_flatten_reindexes_gaps_left_by_filtering(): void {
        // Filtering on the caller's side can leave holes in the array; the keys
        // must still come out as status[0], status[1] so PHP rebuilds a clean list.
        $flat = url_params::flatten(['status' => [0 => 1, 2 => 3]]);

        $this->assertSame(['status[0]' => 1, 'status[1]' => 3], $flat);
    }

    public function test_flatten_handles_nested_arrays(): void {
        $flat = url_params::flatten(['a' => [['x'], ['y']]]);

        $this->assertSame(['a[0][0]' => 'x', 'a[1][0]' => 'y'], $flat);
    }
}
