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
 * Tests for saved filter views.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Tests for saved_view_service.
 *
 * Regression cover for saved views silently dropping the status filter: the
 * filter bar posts its multi-select under the HTML name `status[]`, and the
 * allowlist that guarded the stored parameters only knew `status`, so
 * array_intersect_key() discarded every selection without a word.
 *
 * @covers \local_moodesk\service\saved_view_service
 */
final class saved_view_service_test extends \advanced_testcase {
    /** @var \stdClass The agent owning the views. */
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * Save a view and read it straight back, the way the page does.
     *
     * @param array $params Raw parameters as the filter bar posts them.
     * @param string $name View name.
     * @return array The stored view's parameters.
     */
    private function round_trip(array $params, string $name = 'My view'): array {
        saved_view_service::save((int) $this->user->id, $name, $params);
        $views = saved_view_service::all_for((int) $this->user->id);

        $this->assertCount(1, $views);
        $this->assertSame($name, $views[0]['name']);

        return $views[0]['params'];
    }

    public function test_a_single_status_survives_the_round_trip(): void {
        // One ticked checkbox reaches the request as a scalar, not a list.
        $this->assertSame(
            ['status' => [1]],
            $this->round_trip(['status[]' => '1'])
        );
    }

    public function test_several_statuses_all_survive_the_round_trip(): void {
        $this->assertSame(
            ['status' => [1, 2, 4]],
            $this->round_trip(['status[]' => ['1', '2', '4']])
        );
    }

    public function test_status_and_priority_are_both_kept(): void {
        $this->assertSame(
            ['status' => [1, 3], 'priority' => '2'],
            $this->round_trip(['status[]' => ['1', '3'], 'priority' => '2'])
        );
    }

    public function test_status_and_search_are_both_kept(): void {
        $this->assertSame(
            ['status' => [1, 3], 'search' => 'course'],
            $this->round_trip(['status[]' => ['1', '3'], 'search' => 'course'])
        );
    }

    public function test_the_reported_payload_keeps_every_filter(): void {
        // Verbatim what collectFilterParams() posted when the bug was found.
        $this->assertSame(
            ['status' => [1, 3], 'priority' => '2', 'search' => 'course'],
            $this->round_trip(['status[]' => ['1', '3'], 'priority' => '2', 'search' => 'course'])
        );
    }

    public function test_a_view_without_a_status_is_unaffected(): void {
        $this->assertSame(
            ['priority' => '2', 'search' => 'course', 'sla' => '1'],
            $this->round_trip(['priority' => '2', 'search' => 'course', 'sla' => '1'])
        );
    }

    public function test_status_zero_is_a_real_status(): void {
        // STATUS_NEW is 0; it must not be mistaken for an empty selection.
        $this->assertSame(
            ['status' => [0]],
            $this->round_trip(['status[]' => '0'])
        );
    }

    public function test_status_is_deduplicated_and_reindexed(): void {
        $this->assertSame(
            ['status' => [1, 2]],
            $this->round_trip(['status[]' => ['1', '2', '1']])
        );
    }

    public function test_an_empty_status_selection_drops_the_parameter(): void {
        $params = $this->round_trip(['status[]' => [], 'priority' => '2']);

        $this->assertArrayNotHasKey('status', $params);
        $this->assertSame(['priority' => '2'], $params);
    }

    public function test_keys_the_filter_bar_does_not_produce_are_discarded(): void {
        $params = $this->round_trip(['status[]' => '1', 'sesskey' => 'abc', 'page' => '4', 'view' => 'mine']);

        $this->assertSame(['status' => [1]], $params);
    }

    public function test_a_view_stored_before_this_contract_still_applies(): void {
        // Back-compat: everything already saved holds scalar filters only, since
        // the status was being dropped. Those views must keep working untouched.
        set_user_preference(
            saved_view_service::PREFIX . 'legacy',
            json_encode(['name' => 'Legacy', 'params' => ['priority' => '2', 'search' => 'course']]),
            (int) $this->user->id
        );

        $views = saved_view_service::all_for((int) $this->user->id);

        $this->assertCount(1, $views);
        $this->assertSame('Legacy', $views[0]['name']);
        $this->assertSame('legacy', $views[0]['key']);
        $this->assertSame(['priority' => '2', 'search' => 'course'], $views[0]['params']);
    }

    public function test_a_legacy_view_holding_a_scalar_status_becomes_a_list(): void {
        // The status filter was a single `status=N` integer before the
        // multi-select; normalising on read keeps such a view applying.
        set_user_preference(
            saved_view_service::PREFIX . 'old',
            json_encode(['name' => 'Old', 'params' => ['status' => '3']]),
            (int) $this->user->id
        );

        $views = saved_view_service::all_for((int) $this->user->id);

        $this->assertSame(['status' => [3]], $views[0]['params']);
    }

    public function test_saving_the_same_name_twice_replaces_the_view(): void {
        saved_view_service::save((int) $this->user->id, 'My view', ['status[]' => ['1']]);
        saved_view_service::save((int) $this->user->id, 'My view', ['status[]' => ['2', '3']]);

        $views = saved_view_service::all_for((int) $this->user->id);

        $this->assertCount(1, $views);
        $this->assertSame(['status' => [2, 3]], $views[0]['params']);
    }

    public function test_delete_removes_only_the_named_view(): void {
        $keep = saved_view_service::save((int) $this->user->id, 'Keep me', ['status[]' => ['1']]);
        $drop = saved_view_service::save((int) $this->user->id, 'Drop me', ['status[]' => ['2']]);

        saved_view_service::delete((int) $this->user->id, $drop);

        $views = saved_view_service::all_for((int) $this->user->id);
        $this->assertCount(1, $views);
        $this->assertSame($keep, $views[0]['key']);
    }

    public function test_views_belong_to_their_owner(): void {
        $other = $this->getDataGenerator()->create_user();
        saved_view_service::save((int) $this->user->id, 'Mine', ['status[]' => ['1']]);

        $this->assertSame([], saved_view_service::all_for((int) $other->id));
    }

    public function test_a_view_with_no_usable_parameters_is_not_listed(): void {
        saved_view_service::save((int) $this->user->id, 'Empty', ['sesskey' => 'abc']);

        $this->assertSame([], saved_view_service::all_for((int) $this->user->id));
    }

    public function test_a_nested_status_payload_is_ignored_rather_than_stored(): void {
        $params = saved_view_service::normalise_params(['status' => [['1'], '2']]);

        $this->assertSame(['status' => [2]], $params);
    }

    public function test_normalised_params_build_a_valid_list_url(): void {
        // The end of the chain: what a view stores has to be what the ticket
        // list can turn back into a URL.
        $params = $this->round_trip(['status[]' => ['1', '3'], 'priority' => '2']);
        $url    = \local_moodesk\util\url_params::to_url('/local/moodesk/index.php', $params);

        $parsed = [];
        parse_str(parse_url($url->out(false), PHP_URL_QUERY) ?? '', $parsed);

        $this->assertSame(['status' => ['1', '3'], 'priority' => '2'], $parsed);
    }
}
