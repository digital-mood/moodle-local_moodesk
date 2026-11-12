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
 * Integration tests for the stateless SLA evaluation service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\ticket;
use local_moodesk\hook\sla_overrides;

/**
 * Tests for sla_service.
 *
 * @covers \local_moodesk\service\sla_service
 */
final class sla_service_test extends \advanced_testcase {
    /** @var sla_service */
    private sla_service $sla;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->sla = new sla_service();

        // Reset all SLA thresholds to a known state for each test.
        // Tests that need them set will call set_thresholds_normal_4h_24h() or similar.
        foreach (['low', 'normal', 'high', 'urgent'] as $suffix) {
            set_config("sla_response_hours_{$suffix}", 0, 'local_moodesk');
            set_config("sla_resolve_hours_{$suffix}", 0, 'local_moodesk');
        }
    }

    /**
     * Build a ticket stdClass with sensible defaults that can be overridden.
     *
     * @param array $overrides
     * @return \stdClass
     */
    private function make_ticket(array $overrides = []): \stdClass {
        $base = (object) [
            'priority'           => ticket::PRIORITY_NORMAL,
            'status'             => ticket::STATUS_OPEN,
            'timecreated'        => time(),
            'timefirstresponse'  => null,
            'timeresolved'       => null,
            'departmentid'       => null,
            'sla_paused_seconds' => 0,
            'sla_paused_since'   => null,
        ];
        foreach ($overrides as $k => $v) {
            $base->$k = $v;
        }
        return $base;
    }

    public function test_status_is_none_when_thresholds_disabled(): void {
        // All priorities disabled (0 hours) → no SLA in effect.
        $ticket = $this->make_ticket(['timecreated' => time() - 3600]);
        $this->assertSame('none', $this->sla->get_status($ticket));
    }

    public function test_status_is_ok_when_well_within_threshold(): void {
        set_config('sla_response_hours_normal', 24, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 72, 'local_moodesk');

        $now    = time();
        $ticket = $this->make_ticket([
            'timecreated' => $now - 60, // 1 minute old, far inside thresholds.
        ]);

        $this->assertSame('ok', $this->sla->get_status($ticket, $now));
        $this->assertFalse($this->sla->is_breached($ticket, $now));
    }

    public function test_status_is_breach_when_response_overdue(): void {
        set_config('sla_response_hours_normal', 4, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 72, 'local_moodesk');

        $now    = time();
        $ticket = $this->make_ticket([
            'timecreated'       => $now - 5 * HOURSECS, // Well past 4h response window.
            'timefirstresponse' => null,
        ]);

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
        $this->assertTrue($this->sla->is_breached($ticket, $now));
    }

    public function test_status_is_warning_inside_warning_window(): void {
        // Warning window = last 20% of the response threshold.
        // 10h threshold → 2h warning window → 9h elapsed = inside window.
        set_config('sla_response_hours_normal', 10, 'local_moodesk');

        $now    = time();
        $ticket = $this->make_ticket([
            'timecreated' => $now - 9 * HOURSECS,
        ]);

        $this->assertSame('warning', $this->sla->get_status($ticket, $now));
    }

    public function test_first_response_recorded_clears_response_breach(): void {
        // Once the agent has responded, response SLA is met regardless of when.
        set_config('sla_response_hours_normal', 4, 'local_moodesk');

        $now    = time();
        $ticket = $this->make_ticket([
            'timecreated'       => $now - 10 * HOURSECS,
            'timefirstresponse' => $now - 9 * HOURSECS,
        ]);

        // No resolve threshold configured → only response check applies.
        $this->assertSame('ok', $this->sla->get_status($ticket, $now));
    }

    public function test_terminal_status_always_returns_ok(): void {
        set_config('sla_response_hours_normal', 1, 'local_moodesk');

        $now    = time();
        $base   = [
            'timecreated'       => $now - 999 * HOURSECS, // Would be a massive breach.
            'timefirstresponse' => null,
            'timeresolved'      => null,
        ];

        $resolvedticket = $this->make_ticket($base + ['status' => ticket::STATUS_RESOLVED]);
        $closedticket   = $this->make_ticket($base + ['status' => ticket::STATUS_CLOSED]);

        $this->assertSame('ok', $this->sla->get_status($resolvedticket, $now));
        $this->assertSame('ok', $this->sla->get_status($closedticket, $now));
    }

    public function test_a_department_override_takes_precedence_over_global(): void {
        // Global says 24h response. A plugin says 1h for this department.
        // A 2-hour-old ticket on that department must breach.
        set_config('sla_response_hours_normal', 24, 'local_moodesk');
        $this->redirectHook(sla_overrides::class, static function (sla_overrides $hook): void {
            $hook->set_hours(7, 'response_normal', 1);
        });

        $now    = time();
        $ticket = $this->make_ticket(['departmentid' => 7, 'timecreated' => $now - 2 * HOURSECS]);

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
    }

    public function test_a_department_nobody_overrides_inherits_global(): void {
        // Global = 1h response (tight), and no override for this department.
        // 2h-old ticket must breach via the global threshold.
        set_config('sla_response_hours_normal', 1, 'local_moodesk');
        $this->redirectHook(sla_overrides::class, static function (): void {
        });

        $now    = time();
        $ticket = $this->make_ticket(['departmentid' => 7, 'timecreated' => $now - 2 * HOURSECS]);

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
    }

    public function test_a_department_is_asked_about_once(): void {
        $calls = 0;
        $this->redirectHook(sla_overrides::class, static function () use (&$calls): void {
            $calls++;
        });

        $this->sla->prime_departments([7, 8]);
        $this->sla->get_thresholds(ticket::PRIORITY_NORMAL, 7);
        $this->sla->get_thresholds(ticket::PRIORITY_HIGH, 8);

        $this->assertSame(1, $calls);
    }

    public function test_get_thresholds_returns_seconds_not_hours(): void {
        set_config('sla_response_hours_normal', 4, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 24, 'local_moodesk');

        $thresholds = $this->sla->get_thresholds(ticket::PRIORITY_NORMAL);

        $this->assertSame(4 * HOURSECS, $thresholds['response']);
        $this->assertSame(24 * HOURSECS, $thresholds['resolve']);
    }

    /*
     * -------------------------------------------------------------------------
     * Resolution pause
     * -------------------------------------------------------------------------
     */

    /**
     * Enable a 72h resolution SLA with first response already out of the way.
     *
     * The two dimensions are independent, and only resolution pauses, so these
     * tests take response off the board rather than let it decide the verdict.
     */
    private function set_resolve_only_72h(): void {
        set_config('sla_response_hours_normal', 0, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 72, 'local_moodesk');
    }

    public function test_closed_pause_pushes_the_resolution_deadline_out(): void {
        $this->set_resolve_only_72h();
        $now = time();

        // Five days old against a 72h target: overdue by the old arithmetic.
        // Four of those days were spent waiting on the requester.
        $ticket = $this->make_ticket([
            'timecreated'        => $now - (5 * DAYSECS),
            'sla_paused_seconds' => 4 * DAYSECS,
        ]);

        $this->assertSame('ok', $this->sla->get_status($ticket, $now));
    }

    public function test_an_open_pause_counts_while_the_ticket_is_pending(): void {
        $this->set_resolve_only_72h();
        $now = time();

        $ticket = $this->make_ticket([
            'status'           => ticket::STATUS_PENDING,
            'timecreated'      => $now - (5 * DAYSECS),
            'sla_paused_since' => $now - (4 * DAYSECS),
        ]);

        $this->assertSame('ok', $this->sla->get_status($ticket, $now));
    }

    public function test_a_stale_marker_on_an_active_ticket_grants_nothing(): void {
        $this->set_resolve_only_72h();
        $now = time();

        // The marker says paused, the status says otherwise. Only the closed
        // total counts, so this ticket is 5 days into a 72h budget.
        $ticket = $this->make_ticket([
            'status'             => ticket::STATUS_OPEN,
            'timecreated'        => $now - (5 * DAYSECS),
            'sla_paused_seconds' => 0,
            'sla_paused_since'   => $now - (4 * DAYSECS),
        ]);

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
    }

    public function test_a_pause_that_does_not_cover_the_overrun_still_breaches(): void {
        $this->set_resolve_only_72h();
        $now = time();

        $ticket = $this->make_ticket([
            'timecreated'        => $now - (10 * DAYSECS),
            'sla_paused_seconds' => 2 * DAYSECS,
        ]);

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
    }

    public function test_breach_starts_exactly_at_the_deadline(): void {
        $this->set_resolve_only_72h();
        $now = time();

        // Pins the boundary: the deadline instant itself is a breach, the second
        // before it is not. Without this the pause offset could drift by one
        // second and nothing would notice.
        $atdeadline = $this->make_ticket([
            'timecreated'        => $now - (72 * HOURSECS) - DAYSECS,
            'sla_paused_seconds' => DAYSECS,
        ]);
        $onesecondearlier = $this->make_ticket([
            'timecreated'        => $now - (72 * HOURSECS) - DAYSECS + 1,
            'sla_paused_seconds' => DAYSECS,
        ]);

        $this->assertSame('breach', $this->sla->get_status($atdeadline, $now));
        $this->assertNotSame('breach', $this->sla->get_status($onesecondearlier, $now));
    }

    public function test_a_ticket_that_never_paused_is_unaffected(): void {
        $this->set_resolve_only_72h();
        $now = time();

        $ticket = $this->make_ticket(['timecreated' => $now - (5 * DAYSECS)]);

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
    }

    public function test_a_record_without_the_pause_columns_reads_as_never_paused(): void {
        $this->set_resolve_only_72h();
        $now = time();

        // A caller that selected a narrower column set must still get an answer,
        // and it must be the pre-pause answer rather than a fatal.
        $ticket = (object) [
            'priority'          => ticket::PRIORITY_NORMAL,
            'status'            => ticket::STATUS_OPEN,
            'timecreated'       => $now - (5 * DAYSECS),
            'timefirstresponse' => null,
            'timeresolved'      => null,
            'departmentid'      => null,
        ];

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
    }

    public function test_the_sidebar_bar_holds_still_while_the_ticket_is_paused(): void {
        $this->set_resolve_only_72h();
        $now = time();

        // 36h of work done, then two days waiting: the bar should read the 50%
        // it reached before the pause, not the 100% wall-clock would give.
        $ticket = $this->make_ticket([
            'status'             => ticket::STATUS_PENDING,
            'timecreated'        => $now - (36 * HOURSECS) - (2 * DAYSECS),
            'sla_paused_since'   => $now - (2 * DAYSECS),
        ]);

        $context = $this->sla->get_sla_context($ticket, $now);

        $this->assertSame(50, $context['resolve']['pct']);
        $this->assertFalse($context['resolve']['met']);
        $this->assertSame('ok', $context['status']);
    }

    public function test_the_sidebar_says_why_the_bar_is_not_moving(): void {
        $this->set_resolve_only_72h();
        $now = time();

        $ticket = $this->make_ticket([
            'status'           => ticket::STATUS_PENDING,
            'timecreated'      => $now - (36 * HOURSECS) - (2 * DAYSECS),
            'sla_paused_since' => $now - (2 * DAYSECS),
        ]);

        $context = $this->sla->get_sla_context($ticket, $now);

        $this->assertSame('sla-paused', $context['resolve']['color_class']);
        $this->assertSame(get_string('sla_paused', 'local_moodesk'), $context['resolve']['remaining']);

        // First response never pauses, so its item must not borrow the state.
        $this->assertNotSame('sla-paused', $context['response']['color_class']);
    }

    public function test_a_pending_ticket_without_a_marker_is_not_shown_as_paused(): void {
        $this->set_resolve_only_72h();
        $now = time();

        // Pending, but nothing recorded when the pause began. Showing "paused"
        // here would promise a stopped clock that is in fact still running.
        $ticket = $this->make_ticket([
            'status'      => ticket::STATUS_PENDING,
            'timecreated' => $now - (36 * HOURSECS),
        ]);

        $context = $this->sla->get_sla_context($ticket, $now);

        $this->assertNotSame('sla-paused', $context['resolve']['color_class']);
    }

    public function test_response_does_not_pause(): void {
        set_config('sla_response_hours_normal', 4, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 0, 'local_moodesk');
        $now = time();

        // Pending, unanswered, well past the response target. Waiting on the
        // requester cannot excuse never having replied in the first place.
        $ticket = $this->make_ticket([
            'status'             => ticket::STATUS_PENDING,
            'timecreated'        => $now - (5 * DAYSECS),
            'sla_paused_seconds' => 4 * DAYSECS,
            'sla_paused_since'   => $now - DAYSECS,
        ]);

        $this->assertSame('breach', $this->sla->get_status($ticket, $now));
    }

    /**
     * Remaining times on both sides of every unit boundary.
     *
     * @return array[]
     */
    public static function remaining_time_provider(): array {
        return [
            'under a minute'          => [30, '<1m'],
            'minutes'                 => [45 * MINSECS, '45m'],
            'just under 59.5 minutes' => [59 * MINSECS + 29, '59m'],
            '3,570 seconds'           => [3570, '1h'],
            'hours and minutes'       => [2 * HOURSECS + 15 * MINSECS, '2h 15m'],
            '7 h 59.5 min'            => [7 * HOURSECS + 59 * MINSECS + 30, '8h'],
            '23 h 59.5 min'           => [23 * HOURSECS + 59 * MINSECS + 30, '1d'],
            'days'                    => [2 * DAYSECS, '2d'],
        ];
    }

    /**
     * The sidebar's remaining time rolls over to the next unit instead of
     * showing '60m' or '7h 60m'.
     *
     * @dataProvider remaining_time_provider
     * @param int    $remaining Seconds left before the deadline.
     * @param string $expected  Label the sidebar must show.
     */
    public function test_remaining_time_rolls_over_at_unit_boundaries(int $remaining, string $expected): void {
        set_config('sla_response_hours_normal', 72, 'local_moodesk');
        $now = time();

        $ticket = $this->make_ticket(['timecreated' => $now - (72 * HOURSECS) + $remaining]);

        $this->assertSame($expected, $this->sla->get_sla_context($ticket, $now)['response']['remaining']);
    }

    /**
     * Overdue times on both sides of the minute/hour and hour/day boundaries.
     *
     * @return array[]
     */
    public static function overdue_time_provider(): array {
        return [
            'minutes'       => [45 * MINSECS, 'OVERDUE 45m ago'],
            '3,570 seconds' => [3570, 'OVERDUE 1h ago'],
            'hours'         => [5 * HOURSECS, 'OVERDUE 5h ago'],
            '23.6 hours'    => [(int) (23.6 * HOURSECS), 'OVERDUE 1d ago'],
        ];
    }

    /**
     * The overdue label rolls over to the next unit instead of showing '60m'
     * or '24h'.
     *
     * @dataProvider overdue_time_provider
     * @param int    $overdue  Seconds past the deadline.
     * @param string $expected Label the sidebar must show.
     */
    public function test_overdue_time_rolls_over_at_unit_boundaries(int $overdue, string $expected): void {
        set_config('sla_response_hours_normal', 4, 'local_moodesk');
        $now = time();

        $ticket = $this->make_ticket(['timecreated' => $now - (4 * HOURSECS) - $overdue]);

        $this->assertSame($expected, $this->sla->get_sla_context($ticket, $now)['response']['remaining']);
    }
}
