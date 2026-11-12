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
 * Domain unit tests for the ticket state machine.
 *
 * Pure unit tests — no DB, no Moodle bootstrap dependencies beyond basic_testcase.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Tests for ticket.
 *
 * @covers \local_moodesk\domain\ticket
 */
final class ticket_test extends \basic_testcase {
    public function test_is_valid_transition_phase2_workflow(): void {
        // Open → any active state.
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_OPEN, ticket::STATUS_INPROGRESS));
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_OPEN, ticket::STATUS_PENDING));
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_OPEN, ticket::STATUS_RESOLVED));
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_OPEN, ticket::STATUS_CLOSED));

        // Inprogress ↔ pending.
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_INPROGRESS, ticket::STATUS_PENDING));
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_PENDING, ticket::STATUS_INPROGRESS));

        // Resolved → closed.
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_RESOLVED, ticket::STATUS_CLOSED));

        // Reopen path.
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_CLOSED, ticket::STATUS_OPEN));
    }

    public function test_is_valid_transition_rejects_unknown_transitions(): void {
        // Closed only allows reopen → open. Closed → inprogress is not allowed.
        $this->assertFalse(ticket::is_valid_transition(ticket::STATUS_CLOSED, ticket::STATUS_INPROGRESS));
        $this->assertFalse(ticket::is_valid_transition(ticket::STATUS_CLOSED, ticket::STATUS_RESOLVED));
        $this->assertFalse(ticket::is_valid_transition(ticket::STATUS_CLOSED, ticket::STATUS_PENDING));

        // Open cannot revert to legacy NEW status.
        $this->assertFalse(ticket::is_valid_transition(ticket::STATUS_OPEN, ticket::STATUS_NEW));

        // Resolved cannot loop back to pending.
        $this->assertFalse(ticket::is_valid_transition(ticket::STATUS_RESOLVED, ticket::STATUS_PENDING));

        // Unknown status code in the source slot.
        $this->assertFalse(ticket::is_valid_transition(99, ticket::STATUS_OPEN));
    }

    public function test_is_valid_transition_legacy_paths_preserved(): void {
        // Legacy NEW and ON_HOLD must still transition to active states for
        // backward compatibility with Phase 1 tickets.
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_NEW, ticket::STATUS_OPEN));
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_NEW, ticket::STATUS_INPROGRESS));
        $this->assertTrue(ticket::is_valid_transition(ticket::STATUS_ON_HOLD, ticket::STATUS_OPEN));
    }

    public function test_is_terminal_only_closed(): void {
        $this->assertTrue(ticket::is_terminal(ticket::STATUS_CLOSED));

        $this->assertFalse(ticket::is_terminal(ticket::STATUS_OPEN));
        $this->assertFalse(ticket::is_terminal(ticket::STATUS_INPROGRESS));
        $this->assertFalse(ticket::is_terminal(ticket::STATUS_PENDING));
        $this->assertFalse(ticket::is_terminal(ticket::STATUS_RESOLVED));
    }

    public function test_all_priorities_contains_four_canonical_levels(): void {
        $priorities = ticket::all_priorities();

        $this->assertContains(ticket::PRIORITY_LOW, $priorities);
        $this->assertContains(ticket::PRIORITY_NORMAL, $priorities);
        $this->assertContains(ticket::PRIORITY_HIGH, $priorities);
        $this->assertContains(ticket::PRIORITY_URGENT, $priorities);
        $this->assertCount(4, $priorities);
    }

    public function test_all_statuses_includes_active_and_legacy(): void {
        $statuses = ticket::all_statuses();

        // Active workflow must be present.
        $this->assertContains(ticket::STATUS_OPEN, $statuses);
        $this->assertContains(ticket::STATUS_INPROGRESS, $statuses);
        $this->assertContains(ticket::STATUS_PENDING, $statuses);
        $this->assertContains(ticket::STATUS_RESOLVED, $statuses);
        $this->assertContains(ticket::STATUS_CLOSED, $statuses);

        // Legacy values stay in the array so existing data remains queryable.
        $this->assertContains(ticket::STATUS_NEW, $statuses);
        $this->assertContains(ticket::STATUS_ON_HOLD, $statuses);
    }

    /*
     * -------------------------------------------------------------------------
     * SLA resolution pause
     * -------------------------------------------------------------------------
     */

    /**
     * Build the minimal ticket shape sla_pause_fields reads.
     *
     * @param int      $status
     * @param int      $pausedseconds
     * @param int|null $pausedsince
     * @return \stdClass
     */
    private function pause_state(int $status, int $pausedseconds = 0, ?int $pausedsince = null): \stdClass {
        return (object) [
            'status'             => $status,
            'sla_paused_seconds' => $pausedseconds,
            'sla_paused_since'   => $pausedsince,
        ];
    }

    public function test_entering_pending_opens_an_interval(): void {
        $fields = ticket::sla_pause_fields($this->pause_state(ticket::STATUS_OPEN), ticket::STATUS_PENDING, 1000);

        $this->assertSame(['sla_paused_since' => 1000], $fields);
    }

    public function test_leaving_pending_folds_the_interval_into_the_total(): void {
        $ticket = $this->pause_state(ticket::STATUS_PENDING, 300, 1000);

        $fields = ticket::sla_pause_fields($ticket, ticket::STATUS_OPEN, 1700);

        $this->assertSame(['sla_paused_seconds' => 1000, 'sla_paused_since' => null], $fields);
    }

    public function test_a_repeated_entry_into_pending_does_not_restart_the_interval(): void {
        // The state machine refuses pending → pending, but the domain helper is
        // reachable from the merge path too, and restarting the clock here would
        // silently discard everything accrued since the interval opened.
        $ticket = $this->pause_state(ticket::STATUS_PENDING, 0, 1000);

        $this->assertSame([], ticket::sla_pause_fields($ticket, ticket::STATUS_PENDING, 5000));
    }

    public function test_transitions_outside_pending_write_nothing(): void {
        $ticket = $this->pause_state(ticket::STATUS_OPEN, 900);

        $this->assertSame([], ticket::sla_pause_fields($ticket, ticket::STATUS_RESOLVED, 5000));
    }

    public function test_a_marker_set_in_the_future_never_hands_back_budget(): void {
        // Clock skew between web nodes could stamp a marker ahead of now. The
        // fold must floor at zero rather than subtract from the accrued total.
        $ticket = $this->pause_state(ticket::STATUS_PENDING, 600, 9000);

        $fields = ticket::sla_pause_fields($ticket, ticket::STATUS_CLOSED, 5000);

        $this->assertSame(['sla_paused_seconds' => 600, 'sla_paused_since' => null], $fields);
    }
}
