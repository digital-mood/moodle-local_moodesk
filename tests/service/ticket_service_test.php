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
 * Integration tests for the ticket application service.
 *
 * Hits a real test database (no mocks) per project convention. The integration
 * with repositories IS the contract under test — mocking the repository layer
 * would defeat the purpose.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\ticket;
use local_moodesk\domain\ticket_history;
use local_moodesk\domain\reply as reply_domain;

/**
 * Tests for ticket_service.
 *
 * @covers \local_moodesk\service\ticket_service
 */
final class ticket_service_test extends \advanced_testcase {
    /** @var ticket_service */
    private ticket_service $service;

    /** @var \stdClass Authenticated user submitting tickets. */
    private \stdClass $requester;

    /** @var \stdClass User holding the mhd_agent role (full agent capabilities). */
    private \stdClass $agent;

    /** @var \stdClass A second agent for assignment / membership tests. */
    private \stdClass $agent2;

    /** @var int Default category ID seeded in setUp(). */
    private int $categoryid;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->service = ticket_service::create();

        // Make sure a default category exists and is wired into config so
        // create_ticket() can resolve a fallback. The plugin's install hook
        // already seeds these, but tests should not implicitly rely on it.
        $this->categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$this->categoryid) {
            $this->categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid'    => null,
                'name'        => 'General',
                'sortorder'   => 0,
                'visible'     => 1,
                'timecreated' => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $this->categoryid, 'local_moodesk');

        // Users.
        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent     = $this->getDataGenerator()->create_user();
        $this->agent2    = $this->getDataGenerator()->create_user();

        $systemcontext = \context_system::instance();
        $agentroleid   = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($agentroleid, $this->agent->id, $systemcontext->id);
        role_assign($agentroleid, $this->agent2->id, $systemcontext->id);

        accesslib_clear_all_caches_for_unit_testing();
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /*
     * -------------------------------------------------------------------------
     * Creation
     * -------------------------------------------------------------------------
     */

    public function test_create_ticket_persists_and_writes_creation_history(): void {
        global $DB;

        $data                = new \stdClass();
        $data->userid        = $this->requester->id;
        $data->subject       = 'Cannot log in';
        $data->message       = '<p>Login button does nothing.</p>';
        $data->messageformat = FORMAT_HTML;
        $data->priority      = ticket::PRIORITY_HIGH;

        $result = $this->service->create_ticket($data, $this->requester->id);

        $this->assertArrayHasKey('ticketid', $result);
        $this->assertArrayHasKey('replyid', $result);
        $this->assertGreaterThan(0, $result['ticketid']);
        $this->assertGreaterThan(0, $result['replyid']);

        // Ticket row reflects the requested values.
        $row = $DB->get_record('local_moodesk_ticket', ['id' => $result['ticketid']], '*', MUST_EXIST);
        $this->assertSame((int) $this->requester->id, (int) $row->userid);
        $this->assertSame('Cannot log in', $row->subject);
        $this->assertSame(ticket::STATUS_OPEN, (int) $row->status);
        $this->assertSame(ticket::PRIORITY_HIGH, (int) $row->priority);
        $this->assertSame(ticket::SOURCE_WEB, (int) $row->source);
        $this->assertNull($row->timefirstresponse);

        // Initial reply lives in the thread as a public reply.
        $thread = $DB->get_records('local_moodesk_reply', ['ticketid' => $result['ticketid']], 'id ASC');
        $this->assertCount(1, $thread);
        $first = reset($thread);
        $this->assertSame(reply_domain::TYPE_PUBLIC_REPLY, (int) $first->type);

        // History contains a "created" entry.
        $history = $DB->get_records('local_moodesk_ticket_history', ['ticketid' => $result['ticketid']]);
        $actions = array_map(static fn($h) => $h->action, $history);
        $this->assertContains(ticket_history::ACTION_CREATED, $actions);
    }

    /**
     * A course the requester cannot see is not carried on the ticket: neither
     * its id nor its name. `submit.php?courseid=N` used to read the full name
     * of any course on the site into the requester's own ticket (audit H8).
     */
    public function test_create_ticket_drops_a_course_the_actor_cannot_access(): void {
        global $DB;

        $hidden = $this->getDataGenerator()->create_course(['fullname' => 'Secret programme', 'visible' => 0]);

        $data                = new \stdClass();
        $data->userid        = $this->requester->id;
        $data->subject       = 'Question';
        $data->message       = 'body';
        $data->courseid      = (int) $hidden->id;

        $result = $this->service->create_ticket($data, $this->requester->id);
        $row    = $DB->get_record('local_moodesk_ticket', ['id' => $result['ticketid']], '*', MUST_EXIST);

        $this->assertNull($row->courseid);
        $this->assertNull($row->coursename_snapshot);
        $this->assertSame(\context_system::instance()->id, (int) $row->contextid);
    }

    /**
     * A course the requester is enrolled in is kept, name and all; a course
     * that does not exist is treated as none.
     */
    public function test_create_ticket_keeps_a_course_the_actor_can_access(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        $this->getDataGenerator()->enrol_user($this->requester->id, $course->id);

        $data                = new \stdClass();
        $data->userid        = $this->requester->id;
        $data->subject       = 'Question';
        $data->message       = 'body';
        $data->courseid      = (int) $course->id;

        $result = $this->service->create_ticket($data, $this->requester->id);
        $row    = $DB->get_record('local_moodesk_ticket', ['id' => $result['ticketid']], '*', MUST_EXIST);

        $this->assertSame((int) $course->id, (int) $row->courseid);
        $this->assertSame('Algebra 101', $row->coursename_snapshot);
        $this->assertSame(\context_course::instance($course->id)->id, (int) $row->contextid);

        $data->courseid = 999999;
        $result = $this->service->create_ticket($data, $this->requester->id);
        $row    = $DB->get_record('local_moodesk_ticket', ['id' => $result['ticketid']], '*', MUST_EXIST);
        $this->assertNull($row->courseid);
    }

    public function test_create_ticket_rejects_blank_subject(): void {
        $data           = new \stdClass();
        $data->userid   = $this->requester->id;
        $data->subject  = '   ';
        $data->message  = 'body';

        $this->expectException(\moodle_exception::class);
        $this->service->create_ticket($data, $this->requester->id);
    }

    public function test_create_ticket_rejects_blank_message(): void {
        $data           = new \stdClass();
        $data->userid   = $this->requester->id;
        $data->subject  = 'Real subject';
        $data->message  = '';

        $this->expectException(\moodle_exception::class);
        $this->service->create_ticket($data, $this->requester->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Visibility / access control
     * -------------------------------------------------------------------------
     */

    public function test_get_ticket_requester_cannot_see_someone_elses_ticket(): void {
        $other = $this->getDataGenerator()->create_user();

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        // The 'Other' user is a plain authenticated user — has :viewown but isn't the owner,
        // and has no :viewall. Service must refuse.
        $this->expectException(\moodle_exception::class);
        $this->service->get_ticket($ticketid, $other->id);
    }

    public function test_get_ticket_agent_can_see_global_ticket(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $row = $this->service->get_ticket($ticketid, $this->agent->id);
        $this->assertSame($ticketid, (int) $row->id);
    }

    public function test_get_ticket_owner_can_see_own_ticket(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $row = $this->service->get_ticket($ticketid, $this->requester->id);
        $this->assertSame($ticketid, (int) $row->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Assignment
     * -------------------------------------------------------------------------
     */

    public function test_assign_records_assignee_and_writes_history(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->assign($ticketid, $this->agent->id, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertSame((int) $this->agent->id, (int) $row->assigneeid);

        $history = $DB->get_records('local_moodesk_ticket_history', ['ticketid' => $ticketid]);
        $actions = array_map(static fn($h) => $h->action, $history);
        $this->assertContains(ticket_history::ACTION_ASSIGNED, $actions);
    }

    public function test_assign_rejects_user_without_agent_capability(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        // Try to assign the requester (a plain user, no :agent cap).
        $this->expectException(\moodle_exception::class);
        $this->service->assign($ticketid, $this->requester->id, $this->agent->id);
    }

    public function test_assign_to_member_of_ticket_department_succeeds(): void {
        global $DB;

        $deptid   = $this->insert_active_department('Support');
        $ticketid = $this->create_basic_ticket_for($this->requester->id, ['departmentid' => $deptid]);

        $DB->insert_record('local_moodesk_dept_member', (object) [
            'departmentid' => $deptid,
            'userid'       => $this->agent->id,
            'timecreated'  => time(),
        ]);

        $this->service->assign($ticketid, $this->agent->id, $this->agent->id);

        $assigned = $DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $ticketid]);
        $this->assertSame((int) $this->agent->id, (int) $assigned);
    }

    public function test_assign_rejects_agent_outside_ticket_department(): void {
        $deptid   = $this->insert_active_department('Engineering');
        $ticketid = $this->create_basic_ticket_for($this->requester->id, ['departmentid' => $deptid]);

        // The agent is NOT a member of the department.
        $this->expectException(\moodle_exception::class);
        $this->service->assign($ticketid, $this->agent->id, $this->agent->id);
    }

    public function test_assign_null_unassigns_ticket(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $this->service->assign($ticketid, $this->agent->id, $this->agent->id);

        $this->service->assign($ticketid, null, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], 'assigneeid', MUST_EXIST);
        $this->assertNull($row->assigneeid);
    }

    /*
     * -------------------------------------------------------------------------
     * Status transition (state machine)
     * -------------------------------------------------------------------------
     */

    public function test_transition_status_valid_path_persists(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->transition_status($ticketid, ticket::STATUS_INPROGRESS, $this->agent->id);
        $this->assertSame(
            ticket::STATUS_INPROGRESS,
            (int) $DB->get_field('local_moodesk_ticket', 'status', ['id' => $ticketid])
        );

        $this->service->transition_status($ticketid, ticket::STATUS_RESOLVED, $this->agent->id);
        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertSame(ticket::STATUS_RESOLVED, (int) $row->status);
        // Resolved transition must stamp timeresolved.
        $this->assertNotNull($row->timeresolved);
        $this->assertGreaterThan(0, (int) $row->timeresolved);
    }

    public function test_transition_status_invalid_path_throws(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        // Transition open → new is not permitted (legacy NEW is initial-only).
        $this->expectException(\moodle_exception::class);
        $this->service->transition_status($ticketid, ticket::STATUS_NEW, $this->agent->id);
    }

    public function test_transition_status_writes_status_changed_history(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->transition_status($ticketid, ticket::STATUS_INPROGRESS, $this->agent->id);

        $entries = $DB->get_records('local_moodesk_ticket_history', [
            'ticketid' => $ticketid,
            'action'   => ticket_history::ACTION_STATUS_CHANGED,
        ]);
        $this->assertCount(1, $entries);

        $entry = reset($entries);
        $newvalue = json_decode($entry->newvalue, true);
        $this->assertSame(ticket::STATUS_INPROGRESS, (int) $newvalue['status']);
    }

    public function test_transition_to_same_status_is_a_noop(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        // Transition open → open: must not write history, must not error.
        $this->service->transition_status($ticketid, ticket::STATUS_OPEN, $this->agent->id);

        $rows = $DB->count_records('local_moodesk_ticket_history', [
            'ticketid' => $ticketid,
            'action'   => ticket_history::ACTION_STATUS_CHANGED,
        ]);
        $this->assertSame(0, $rows);
    }

    /*
     * -------------------------------------------------------------------------
     * Priority change
     * -------------------------------------------------------------------------
     */

    public function test_set_priority_updates_field_and_writes_history(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->set_priority($ticketid, ticket::PRIORITY_URGENT, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertSame(ticket::PRIORITY_URGENT, (int) $row->priority);

        $history = $DB->get_records('local_moodesk_ticket_history', [
            'ticketid' => $ticketid,
            'action'   => ticket_history::ACTION_PRIORITY_CHANGED,
        ]);
        $this->assertCount(1, $history);
    }

    public function test_set_priority_rejects_invalid_value(): void {
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->expectException(\moodle_exception::class);
        $this->service->set_priority($ticketid, 99, $this->agent->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Reply (timefirstresponse semantics)
     * -------------------------------------------------------------------------
     */

    public function test_first_agent_reply_sets_timefirstresponse(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $replyservice = reply_service::create();
        $data          = new \stdClass();
        $data->message = 'Looking into it.';
        $data->messageformat = FORMAT_PLAIN;

        $replyservice->add_public_reply($ticketid, $data, $this->agent->id);

        $stamp = $DB->get_field('local_moodesk_ticket', 'timefirstresponse', ['id' => $ticketid]);
        $this->assertNotNull($stamp);
        $this->assertGreaterThan(0, (int) $stamp);
    }

    public function test_requester_reply_does_not_set_timefirstresponse(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $replyservice = reply_service::create();
        $data          = new \stdClass();
        $data->message = 'Any update?';
        $data->messageformat = FORMAT_PLAIN;

        $replyservice->add_public_reply($ticketid, $data, $this->requester->id);

        $stamp = $DB->get_field('local_moodesk_ticket', 'timefirstresponse', ['id' => $ticketid]);
        $this->assertFalse((bool) $stamp); // Null in DB → false from get_field.
    }

    public function test_view_all_is_global_for_viewall_regardless_of_department_membership(): void {
        // Regression: get_all_tickets() requires local/moodesk:viewall, so its
        // VIEW_ALL branch must be genuinely global. An unassigned ticket in a
        // department the viewer is not a member of was hidden from "All Tickets"
        // (accessibility-scoped query) while still counted by the dashboard's
        // global manager metrics — the two disagreed.
        $deptid   = $this->insert_active_department('Memberless Dept');
        $ticketid = $this->create_basic_ticket_for($this->requester->id, ['departmentid' => $deptid]);

        // The $this->agent user holds viewall via the mhd_agent role but is NOT a member
        // of $deptid, and the ticket is unassigned — so the accessibility filter
        // would have excluded it.
        $result = $this->service->get_all_tickets(
            (int) $this->agent->id,
            [],
            0,
            \local_moodesk\domain\department::VIEW_ALL
        );

        $ids = array_map(static fn($t) => (int) $t->id, $result['tickets']);
        $this->assertContains(
            $ticketid,
            $ids,
            'VIEW_ALL must surface every ticket for a viewall user, even in departments they do not belong to.'
        );
        $this->assertSame(1, (int) $result['total']);
    }

    /**
     * The list and the detail must agree about what a viewall agent can reach.
     *
     * They did not. The list was global by deliberate decision while
     * get_ticket() applied a department rule of its own, so an agent could see
     * a row in the queue, click it, and be told the ticket does not exist. The
     * decision of 2026-09-04 makes viewall genuinely
     * global; this asserts both halves now say so.
     *
     * Nothing covered the old department deny, which is exactly why it could
     * disagree with the list for as long as it did.
     */
    public function test_the_list_and_the_detail_agree_for_an_agent_outside_the_department(): void {
        $deptid   = $this->insert_active_department('Somebody Elses Dept');
        $ticketid = $this->create_basic_ticket_for($this->requester->id, ['departmentid' => $deptid]);

        $listed = $this->service->get_all_tickets(
            (int) $this->agent->id,
            [],
            0,
            \local_moodesk\domain\department::VIEW_ALL
        );
        $ids = array_map(static fn($t) => (int) $t->id, $listed['tickets']);
        $this->assertContains($ticketid, $ids);

        // The same agent, the same ticket, the other half of the product.
        $detail = $this->service->get_ticket($ticketid, (int) $this->agent->id);
        $this->assertSame($ticketid, (int) $detail->id);
    }

    public function test_a_requester_still_cannot_open_someone_elses_ticket(): void {
        $other    = $this->getDataGenerator()->create_user();
        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        // Widening viewall to be global must not have widened anything else:
        // a requester reaches their own tickets and nothing more.
        $this->expectException(\required_capability_exception::class);
        $this->service->get_ticket($ticketid, (int) $other->id);
    }

    public function test_get_all_tickets_filters_on_a_multi_value_status_selection(): void {
        global $DB;
        // Regression: the ticket list built its filter array under the key
        // 'statuses', while the repository reads 'status'. The selection never
        // reached the query, so filtering by status returned the full list.
        // create_ticket() opens every ticket, so the other two statuses are set directly.
        $new      = $this->create_basic_ticket_for($this->requester->id);
        $open     = $this->create_basic_ticket_for($this->requester->id);
        $resolved = $this->create_basic_ticket_for($this->requester->id);
        $DB->set_field('local_moodesk_ticket', 'status', ticket::STATUS_NEW, ['id' => $new]);
        $DB->set_field('local_moodesk_ticket', 'status', ticket::STATUS_RESOLVED, ['id' => $resolved]);

        $one = $this->service->get_all_tickets(
            (int) $this->agent->id,
            ['status' => [ticket::STATUS_OPEN]],
            0,
            \local_moodesk\domain\department::VIEW_ALL
        );
        $this->assertSame([$open], array_map(static fn($t) => (int) $t->id, $one['tickets']));
        $this->assertSame(1, (int) $one['total']);

        $many = $this->service->get_all_tickets(
            (int) $this->agent->id,
            ['status' => [ticket::STATUS_OPEN, ticket::STATUS_RESOLVED]],
            0,
            \local_moodesk\domain\department::VIEW_ALL
        );
        $manyids = array_map(static fn($t) => (int) $t->id, $many['tickets']);
        sort($manyids);
        $expected = [$open, $resolved];
        sort($expected);
        $this->assertSame($expected, $manyids);
        $this->assertSame(2, (int) $many['total']);
        $this->assertNotContains($new, $manyids);
    }

    /*
     * -------------------------------------------------------------------------
     * Routing: department and category
     *
     * Baseline coverage written ahead of the merge read-only enforcement (F2).
     * Both write paths are about to grow a merged-state guard and had no tests
     * at all, so these pin the working behaviour first: the guard must not
     * change any of it for an active ticket.
     * -------------------------------------------------------------------------
     */

    public function test_set_department_updates_field_and_writes_history(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $deptid   = $this->insert_active_department('Second Line');

        $this->service->set_department($ticketid, $deptid, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertSame($deptid, (int) $row->departmentid);

        $history = $DB->get_records('local_moodesk_ticket_history', [
            'ticketid' => $ticketid,
            'action'   => ticket_history::ACTION_DEPARTMENT_CHANGED,
        ]);
        $this->assertCount(1, $history);
    }

    public function test_set_department_null_clears_the_department(): void {
        global $DB;

        $deptid   = $this->insert_active_department('First Line');
        $ticketid = $this->create_basic_ticket_for($this->requester->id, ['departmentid' => $deptid]);

        $this->service->set_department($ticketid, null, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertNull($row->departmentid);
    }

    public function test_set_department_to_same_value_is_a_noop(): void {
        global $DB;

        $deptid   = $this->insert_active_department('Only Dept');
        $ticketid = $this->create_basic_ticket_for($this->requester->id, ['departmentid' => $deptid]);

        $this->service->set_department($ticketid, $deptid, $this->agent->id);

        $history = $DB->get_records('local_moodesk_ticket_history', [
            'ticketid' => $ticketid,
            'action'   => ticket_history::ACTION_DEPARTMENT_CHANGED,
        ]);
        $this->assertCount(0, $history, 'Re-setting the same department must not write a history row.');
    }

    public function test_set_category_updates_field_and_writes_history(): void {
        global $DB;

        $ticketid  = $this->create_basic_ticket_for($this->requester->id);
        $newcatid  = (int) $DB->insert_record('local_moodesk_category', (object) [
            'parentid'     => null,
            'name'         => 'Billing',
            'sortorder'    => 1,
            'visible'      => 1,
            'timecreated'  => time(),
            'usermodified' => 0,
        ]);

        $this->service->set_category($ticketid, $newcatid, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $this->assertSame($newcatid, (int) $row->categoryid);

        $history = $DB->get_records('local_moodesk_ticket_history', [
            'ticketid' => $ticketid,
            'action'   => ticket_history::ACTION_CATEGORY_CHANGED,
        ]);
        $this->assertCount(1, $history);
    }

    public function test_set_category_to_same_value_is_a_noop(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $current  = (int) $DB->get_field('local_moodesk_ticket', 'categoryid', ['id' => $ticketid]);

        $this->service->set_category($ticketid, $current, $this->agent->id);

        $history = $DB->get_records('local_moodesk_ticket_history', [
            'ticketid' => $ticketid,
            'action'   => ticket_history::ACTION_CATEGORY_CHANGED,
        ]);
        $this->assertCount(0, $history, 'Re-setting the same category must not write a history row.');
    }

    /*
     * -------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------
     */

    /**
     * Create a basic open ticket for a given requester user.
     *
     * @param int   $requesterid
     * @param array $overrides   Optional ticket-record column overrides.
     * @return int               New ticket ID.
     */
    private function create_basic_ticket_for(int $requesterid, array $overrides = []): int {
        $data                = new \stdClass();
        $data->userid        = $requesterid;
        $data->subject       = 'Test subject';
        $data->message       = 'Test message';
        $data->messageformat = FORMAT_PLAIN;
        foreach ($overrides as $k => $v) {
            $data->$k = $v;
        }

        $result = $this->service->create_ticket($data, $requesterid);
        return (int) $result['ticketid'];
    }

    /**
     * Insert an active department and return its ID.
     *
     * @param string $name Department name.
     */
    private function insert_active_department(string $name): int {
        global $DB;
        return (int) $DB->insert_record('local_moodesk_department', (object) [
            'name'         => $name,
            'isactive'     => 1,
            'timecreated'  => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);
    }

    /*
     * -------------------------------------------------------------------------
     * SLA resolution pause
     * -------------------------------------------------------------------------
     */

    /**
     * Backdate an open pause so the service sees a stay that already has depth.
     *
     * transition_status stamps time(), and a test cannot make real time pass, so
     * the marker is moved backwards instead: leaving the pause then folds in the
     * interval we chose.
     *
     * @param int $ticketid
     * @param int $seconds  How long the ticket should look like it has waited.
     */
    private function backdate_open_pause(int $ticketid, int $seconds): void {
        global $DB;

        $since = (int) $DB->get_field('local_moodesk_ticket', 'sla_paused_since', ['id' => $ticketid]);
        $this->assertGreaterThan(0, $since, 'ticket should be paused before backdating');

        $DB->set_field('local_moodesk_ticket', 'sla_paused_since', $since - $seconds, ['id' => $ticketid]);
    }

    public function test_transition_to_pending_opens_the_sla_pause(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);
        $before    = time();

        $this->service->transition_status($ticketid, ticket::STATUS_PENDING, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);

        $this->assertSame(0, (int) $row->sla_paused_seconds, 'nothing has accrued yet');
        $this->assertNotNull($row->sla_paused_since);
        $this->assertGreaterThanOrEqual($before, (int) $row->sla_paused_since);
    }

    public function test_leaving_pending_folds_the_interval_into_the_total(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->transition_status($ticketid, ticket::STATUS_PENDING, $this->agent->id);
        $this->backdate_open_pause($ticketid, 2 * DAYSECS);
        $this->service->transition_status($ticketid, ticket::STATUS_INPROGRESS, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);

        $this->assertEqualsWithDelta(2 * DAYSECS, (int) $row->sla_paused_seconds, 5);
        $this->assertNull($row->sla_paused_since, 'the interval is closed, not still open');
    }

    public function test_repeated_pauses_accumulate(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->transition_status($ticketid, ticket::STATUS_PENDING, $this->agent->id);
        $this->backdate_open_pause($ticketid, 2 * DAYSECS);
        $this->service->transition_status($ticketid, ticket::STATUS_OPEN, $this->agent->id);

        $this->service->transition_status($ticketid, ticket::STATUS_PENDING, $this->agent->id);
        $this->backdate_open_pause($ticketid, 3 * DAYSECS);
        $this->service->transition_status($ticketid, ticket::STATUS_OPEN, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);

        $this->assertEqualsWithDelta(5 * DAYSECS, (int) $row->sla_paused_seconds, 5);
        $this->assertNull($row->sla_paused_since);
    }

    public function test_resolving_straight_from_pending_closes_the_pause(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->transition_status($ticketid, ticket::STATUS_PENDING, $this->agent->id);
        $this->backdate_open_pause($ticketid, DAYSECS);

        // Straight to resolved without passing through an active status: the
        // requester answered and the agent closed it out in one move.
        $this->service->transition_status($ticketid, ticket::STATUS_RESOLVED, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);

        $this->assertEqualsWithDelta(DAYSECS, (int) $row->sla_paused_seconds, 5);
        $this->assertNull($row->sla_paused_since);
        $this->assertNotNull($row->timeresolved);
    }

    public function test_transitions_that_avoid_pending_leave_the_pause_alone(): void {
        global $DB;

        $ticketid = $this->create_basic_ticket_for($this->requester->id);

        $this->service->transition_status($ticketid, ticket::STATUS_INPROGRESS, $this->agent->id);
        $this->service->transition_status($ticketid, ticket::STATUS_RESOLVED, $this->agent->id);

        $row = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);

        $this->assertSame(0, (int) $row->sla_paused_seconds);
        $this->assertNull($row->sla_paused_since);
    }
}
