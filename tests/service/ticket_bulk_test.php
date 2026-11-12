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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Integration tests for bulk ticket operations.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\ticket;

/**
 * What apply_bulk() promises: partial success.
 *
 * The interesting cases are the ones where the selection is not uniform. An
 * agent selects thirty tickets off a filtered page; two of them are closed and
 * cannot go to resolved, one was merged while the page sat open. The other
 * twenty-seven must still move, and the agent must be told which three did not.
 * Every test here is a variation on that.
 *
 * @covers \local_moodesk\service\ticket_service::apply_bulk
 */
final class ticket_bulk_test extends \advanced_testcase {
    /** @var ticket_service */
    private ticket_service $service;

    /** @var \stdClass Ticket requester. */
    private \stdClass $requester;

    /** @var \stdClass Agent holding :manage. */
    private \stdClass $agent;

    /** @var \stdClass A second agent, for assignment. */
    private \stdClass $agent2;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->service = ticket_service::create();

        $categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$categoryid) {
            $categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid'     => null,
                'name'         => 'General',
                'sortorder'    => 0,
                'visible'      => 1,
                'timecreated'  => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent     = $this->getDataGenerator()->create_user();
        $this->agent2    = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $agentroleid   = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($agentroleid, $this->agent->id, $systemcontext->id);
        role_assign($agentroleid, $this->agent2->id, $systemcontext->id);

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Create one ticket and return its id.
     *
     * @param string $subject
     * @return int
     */
    private function make_ticket(string $subject = 'Bulk subject'): int {
        $data                = new \stdClass();
        $data->userid        = $this->requester->id;
        $data->subject       = $subject;
        $data->message       = '<p>Body.</p>';
        $data->messageformat = FORMAT_HTML;
        $data->priority      = ticket::PRIORITY_NORMAL;

        $result = $this->service->create_ticket($data, $this->requester->id);
        return (int) $result['ticketid'];
    }

    /**
     * An id no ticket holds.
     *
     * @return int
     */
    private function unused_ticket_id(): int {
        global $DB;
        return ((int) $DB->get_field_sql('SELECT MAX(id) FROM {local_moodesk_ticket}')) + 1000;
    }

    /**
     * Read one ticket's status straight from the database.
     *
     * @param int $ticketid
     * @return int
     */
    private function status_of(int $ticketid): int {
        global $DB;
        return (int) $DB->get_field('local_moodesk_ticket', 'status', ['id' => $ticketid], MUST_EXIST);
    }

    /*
     * Selection handling.
     */

    public function test_an_empty_selection_does_nothing_and_reports_itself_as_empty(): void {
        $result = $this->service->apply_bulk(
            [],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertTrue($result->is_empty());
        $this->assertSame(0, $result->processed_count());
        $this->assertSame([], $result->succeeded());
        $this->assertSame([], $result->failed());
    }

    public function test_a_repeated_ticket_id_is_acted_on_once(): void {
        $ticketid = $this->make_ticket();

        $result = $this->service->apply_bulk(
            [$ticketid, $ticketid, $ticketid],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertSame([$ticketid], $result->succeeded());
        $this->assertSame(1, $result->processed_count());
    }

    /**
     * A hand-built request can carry a zero, and PARAM_INT will pass it through.
     */
    public function test_non_positive_ids_are_dropped_before_anything_runs(): void {
        $ticketid = $this->make_ticket();

        $result = $this->service->apply_bulk(
            [0, -5, $ticketid],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertSame([$ticketid], $result->succeeded());
        $this->assertSame([], $result->failed_ids());
    }

    public function test_a_selection_of_only_invalid_ids_is_empty_not_failed(): void {
        $result = $this->service->apply_bulk(
            [0, -1],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertTrue($result->is_empty());
        $this->assertSame([], $result->failed_ids());
    }

    /*
     * The cap (docs/lab/bulk-bench-2026-10-01.md).
     */

    public function test_a_selection_over_the_cap_is_refused_before_any_ticket_moves(): void {
        $ids = [];
        for ($i = 0; $i <= ticket_service::BULK_MAX_TICKETS; $i++) {
            $ids[] = $this->make_ticket('Over the cap ' . $i);
        }

        try {
            $this->service->apply_bulk(
                $ids,
                ticket_service::BULK_ACTION_STATUS,
                ['status' => ticket::STATUS_RESOLVED],
                $this->agent->id
            );
            $this->fail('Expected bulk_too_many for ' . count($ids) . ' tickets.');
        } catch (\moodle_exception $e) {
            $this->assertSame('bulk_too_many', $e->errorcode);
        }

        foreach ($ids as $id) {
            $this->assertSame(ticket::STATUS_OPEN, $this->status_of($id));
        }
    }

    public function test_a_selection_exactly_at_the_cap_is_applied(): void {
        $ids = [];
        for ($i = 0; $i < ticket_service::BULK_MAX_TICKETS; $i++) {
            $ids[] = $this->make_ticket('At the cap ' . $i);
        }

        $result = $this->service->apply_bulk(
            $ids,
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertSame(ticket_service::BULK_MAX_TICKETS, $result->success_count());
    }

    public function test_the_cap_counts_distinct_tickets_not_submitted_ids(): void {
        // A page's worth of ids repeated is still a page's worth of tickets.
        $id = $this->make_ticket();
        $ids = array_fill(0, ticket_service::BULK_MAX_TICKETS + 5, $id);

        $result = $this->service->apply_bulk(
            $ids,
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertSame(1, $result->success_count());
    }

    public function test_the_list_can_never_select_more_than_one_request_accepts(): void {
        // The list selects within one page and sends the page's ids; a page longer
        // than the cap would let select-all build a batch the server refuses.
        $this->assertLessThanOrEqual(ticket_service::BULK_MAX_TICKETS, ticket_service::PAGE_SIZE);
        $this->assertLessThan(ticket_service::BULK_MAX_TICKETS, ticket_service::BULK_CONFIRM_FROM);
    }

    /*
     * Status changes.
     */

    public function test_a_valid_transition_moves_every_selected_ticket(): void {
        $first  = $this->make_ticket('First');
        $second = $this->make_ticket('Second');

        $result = $this->service->apply_bulk(
            [$first, $second],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertSame([$first, $second], $result->succeeded());
        $this->assertSame(0, $result->failure_count());
        $this->assertSame(ticket::STATUS_RESOLVED, $this->status_of($first));
        $this->assertSame(ticket::STATUS_RESOLVED, $this->status_of($second));
    }

    public function test_a_transition_the_state_machine_forbids_is_recorded_as_a_failure(): void {
        $ticketid = $this->make_ticket();
        // Closed tickets may only reopen; resolved is not reachable from there.
        $this->service->transition_status($ticketid, ticket::STATUS_CLOSED, $this->agent->id);

        $result = $this->service->apply_bulk(
            [$ticketid],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertSame([], $result->succeeded());
        $this->assertSame([$ticketid], $result->failed_ids());
        $this->assertNotSame('', $result->failed()[$ticketid], 'A domain refusal carries its reason.');
        $this->assertSame(ticket::STATUS_CLOSED, $this->status_of($ticketid));
    }

    /**
     * The case the whole feature turns on: one ticket refusing must not take the
     * rest of the selection down with it, and the refusal must be named.
     */
    public function test_one_refused_ticket_does_not_stop_the_others(): void {
        $closed = $this->make_ticket('Already closed');
        $this->service->transition_status($closed, ticket::STATUS_CLOSED, $this->agent->id);
        $open  = $this->make_ticket('Still open');
        $other = $this->make_ticket('Also open');

        // The refusal sits first, so anything that aborted early would be visible.
        $result = $this->service->apply_bulk(
            [$closed, $open, $other],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertTrue($result->is_partial());
        $this->assertSame([$open, $other], $result->succeeded());
        $this->assertSame([$closed], $result->failed_ids());
        $this->assertSame(3, $result->processed_count());
        $this->assertSame(ticket::STATUS_RESOLVED, $this->status_of($open));
        $this->assertSame(ticket::STATUS_RESOLVED, $this->status_of($other));
        $this->assertSame(ticket::STATUS_CLOSED, $this->status_of($closed));
    }

    public function test_a_ticket_that_does_not_exist_fails_without_touching_the_others(): void {
        $ticketid = $this->make_ticket();
        $missing  = $this->unused_ticket_id();

        $result = $this->service->apply_bulk(
            [$missing, $ticketid],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $this->agent->id
        );

        $this->assertSame([$ticketid], $result->succeeded());
        $this->assertSame([$missing], $result->failed_ids());
    }

    /*
     * Assignment.
     */

    public function test_assignment_lands_on_every_selected_ticket(): void {
        global $DB;
        $first  = $this->make_ticket('First');
        $second = $this->make_ticket('Second');

        $result = $this->service->apply_bulk(
            [$first, $second],
            ticket_service::BULK_ACTION_ASSIGN,
            ['assigneeid' => $this->agent2->id],
            $this->agent->id
        );

        $this->assertSame([$first, $second], $result->succeeded());
        $this->assertSame(
            (int) $this->agent2->id,
            (int) $DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $first], MUST_EXIST)
        );
    }

    public function test_assigning_zero_unassigns(): void {
        global $DB;
        $ticketid = $this->make_ticket();
        $this->service->assign($ticketid, $this->agent2->id, $this->agent->id);

        $result = $this->service->apply_bulk(
            [$ticketid],
            ticket_service::BULK_ACTION_ASSIGN,
            ['assigneeid' => 0],
            $this->agent->id
        );

        $this->assertSame([$ticketid], $result->succeeded());
        $this->assertNull(
            $DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $ticketid], MUST_EXIST) ?: null
        );
    }

    public function test_a_failed_assignment_leaves_the_rest_of_the_selection_assigned(): void {
        global $DB;
        $ticketid = $this->make_ticket();
        $missing  = $this->unused_ticket_id();

        $result = $this->service->apply_bulk(
            [$missing, $ticketid],
            ticket_service::BULK_ACTION_ASSIGN,
            ['assigneeid' => $this->agent2->id],
            $this->agent->id
        );

        $this->assertTrue($result->is_partial());
        $this->assertSame([$ticketid], $result->succeeded());
        $this->assertSame([$missing], $result->failed_ids());
        $this->assertSame(
            (int) $this->agent2->id,
            (int) $DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $ticketid], MUST_EXIST)
        );
    }

    /*
     * Action and parameter contract.
     */

    public function test_an_unknown_action_is_a_programming_error_not_a_ticket_failure(): void {
        $ticketid = $this->make_ticket();

        $this->expectException(\coding_exception::class);
        $this->service->apply_bulk([$ticketid], 'delete', [], $this->agent->id);
    }

    public function test_an_unknown_action_is_rejected_before_any_ticket_is_touched(): void {
        $ticketid = $this->make_ticket();

        try {
            $this->service->apply_bulk([$ticketid], 'priority', ['priority' => 3], $this->agent->id);
            $this->fail('Expected a coding_exception for an unknown action.');
        } catch (\coding_exception $e) {
            $this->assertSame(ticket::STATUS_OPEN, $this->status_of($ticketid));
        }
    }

    public function test_a_status_action_without_a_status_is_a_programming_error(): void {
        $ticketid = $this->make_ticket();

        $this->expectException(\coding_exception::class);
        $this->service->apply_bulk([$ticketid], ticket_service::BULK_ACTION_STATUS, [], $this->agent->id);
    }

    public function test_a_status_outside_the_domain_is_rejected(): void {
        $ticketid = $this->make_ticket();

        $this->expectException(\coding_exception::class);
        $this->service->apply_bulk(
            [$ticketid],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => 99],
            $this->agent->id
        );
    }

    /*
     * Authorisation.
     */

    /**
     * A missing capability is not thirty ticket failures — it is one refusal.
     *
     * apply_bulk() adds no check of its own: every ticket goes through
     * transition_status(), which requires :manage on its own behalf. What is
     * asserted here is that the resulting exception escapes rather than being
     * collected as a per-ticket failure, so a caller can never read a permission
     * problem as "the tickets happened not to move".
     */
    public function test_an_actor_without_manage_is_refused_outright(): void {
        $ticketid = $this->make_ticket();
        $outsider = $this->getDataGenerator()->create_user();

        $this->expectException(\required_capability_exception::class);
        $this->service->apply_bulk(
            [$ticketid],
            ticket_service::BULK_ACTION_STATUS,
            ['status' => ticket::STATUS_RESOLVED],
            $outsider->id
        );
    }

    public function test_an_actor_without_manage_changes_nothing(): void {
        $ticketid = $this->make_ticket();
        $outsider = $this->getDataGenerator()->create_user();

        try {
            $this->service->apply_bulk(
                [$ticketid],
                ticket_service::BULK_ACTION_ASSIGN,
                ['assigneeid' => $outsider->id],
                $outsider->id
            );
            $this->fail('Expected a required_capability_exception.');
        } catch (\required_capability_exception $e) {
            global $DB;
            $this->assertNull(
                $DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $ticketid], MUST_EXIST) ?: null
            );
        }
    }
}
