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
 * Integration tests for the agent management application service.
 *
 * Hits a real test database (no mocks) per project convention: the service is
 * tested against Moodle's real role API and the plugin repositories, since the
 * integration with Moodle roles *is* the contract.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\ticket;

/**
 * Tests for agent_service.
 *
 * @covers \local_moodesk\service\agent_service
 */
final class agent_service_test extends \advanced_testcase {
    /** @var agent_service */
    private agent_service $service;

    /** @var \stdClass Manager — holds :manageagents via the manager archetype. */
    private \stdClass $manager;

    /** @var \stdClass Managed agent — holds :agent via the seeded mhd_agent role. */
    private \stdClass $managed;

    /** @var \stdClass Unmanaged agent — holds :agent via a different custom role. */
    private \stdClass $unmanaged;

    /** @var \stdClass Plain authenticated user with no agent role. */
    private \stdClass $regular;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        // These cases are about the roster, the role toggles and offboarding —
        // not about licensing. They run on Enterprise with room for 1000 agents
        // so a fixture with more agents than Starter allows never trips the
        // entitlement gate: a forced edition has 2 seats unless something says
        // otherwise. The gate has its own suites (hook/agent_capacity_hook_test,
        // and in local_moodeskpro agent_entitlement_test and agent_capacity_test).
        edition_service::set_edition_for_testing(edition_service::EDITION_ENTERPRISE);
        $this->redirectHook(\local_moodesk\hook\agent_capacity::class, static function ($hook): void {
            $hook->set_limit(1000);
        });

        $this->service = agent_service::create();

        $systemcontext = \context_system::instance();

        // Manager — wields :manageagents via the manager archetype.
        $this->manager = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $this->manager->id, $systemcontext->id);

        // Managed agent — the seeded mhd_agent role (the one mooDesk controls).
        $this->managed = $this->getDataGenerator()->create_user();
        $agentroleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($agentroleid, $this->managed->id, $systemcontext->id);

        // Unmanaged agent — :agent granted through a *different* custom role, so
        // mooDesk recognises them as an agent but must not manage their status.
        $this->unmanaged = $this->getDataGenerator()->create_user();
        $customroleid = create_role('Corp agent', 'corp_agent', 'Agent via another role');
        set_role_contextlevels($customroleid, [CONTEXT_SYSTEM]);
        assign_capability('local/moodesk:agent', CAP_ALLOW, $customroleid, $systemcontext->id, true);
        role_assign($customroleid, $this->unmanaged->id, $systemcontext->id);

        // Plain user — no helpdesk capabilities beyond authenticated defaults.
        $this->regular = $this->getDataGenerator()->create_user();

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Static state outlives the per-test database reset.
     */
    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /*
     * -------------------------------------------------------------------------
     * Roster
     * -------------------------------------------------------------------------
     */

    public function test_list_agents_includes_managed_and_unmanaged(): void {
        $roster = $this->service->list_agents($this->manager->id);

        $this->assertArrayHasKey((int) $this->managed->id, $roster);
        $this->assertArrayHasKey((int) $this->unmanaged->id, $roster);

        $this->assertTrue($roster[(int) $this->managed->id]->managed);
        $this->assertFalse($roster[(int) $this->unmanaged->id]->managed);
    }

    public function test_list_agents_excludes_site_admin(): void {
        // The site admin does not hold :agent explicitly. Even though an admin
        // "can do anything", $doanything=false must keep them out of the roster.
        $admin = get_admin();
        $roster = $this->service->list_agents($this->manager->id);
        $this->assertArrayNotHasKey((int) $admin->id, $roster);
    }

    public function test_list_agents_requires_capability(): void {
        $this->expectException(\required_capability_exception::class);
        $this->service->list_agents($this->regular->id);
    }

    public function test_list_agents_reports_open_workload(): void {
        // Two open + one resolved ticket assigned to the managed agent.
        $this->insert_ticket($this->managed->id, ticket::STATUS_OPEN);
        $this->insert_ticket($this->managed->id, ticket::STATUS_INPROGRESS);
        $this->insert_ticket($this->managed->id, ticket::STATUS_RESOLVED);

        $roster = $this->service->list_agents($this->manager->id);

        $this->assertSame(2, $roster[(int) $this->managed->id]->openworkload);
        $this->assertSame(0, $roster[(int) $this->unmanaged->id]->openworkload);
    }

    public function test_list_agents_search_filters_by_name(): void {
        $roster = $this->service->list_agents(
            $this->manager->id,
            ['search' => $this->managed->email]
        );

        $this->assertArrayHasKey((int) $this->managed->id, $roster);
        $this->assertArrayNotHasKey((int) $this->unmanaged->id, $roster);
    }

    /*
     * -------------------------------------------------------------------------
     * Managed vs unmanaged
     * -------------------------------------------------------------------------
     */

    public function test_is_managed_agent_distinguishes_role_origin(): void {
        $this->assertTrue($this->service->is_managed_agent((int) $this->managed->id));
        $this->assertFalse($this->service->is_managed_agent((int) $this->unmanaged->id));
        $this->assertFalse($this->service->is_managed_agent((int) $this->regular->id));
    }

    /*
     * -------------------------------------------------------------------------
     * Grant
     * -------------------------------------------------------------------------
     */

    public function test_grant_agent_makes_user_a_managed_agent(): void {
        $this->assertFalse($this->service->is_agent((int) $this->regular->id));

        $this->service->grant_agent((int) $this->regular->id, $this->manager->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->service->is_agent((int) $this->regular->id));
        $this->assertTrue($this->service->is_managed_agent((int) $this->regular->id));
    }

    public function test_grant_agent_is_idempotent(): void {
        $this->service->grant_agent((int) $this->regular->id, $this->manager->id);
        $this->service->grant_agent((int) $this->regular->id, $this->manager->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->service->is_managed_agent((int) $this->regular->id));
    }

    public function test_grant_agent_requires_capability(): void {
        $this->expectException(\required_capability_exception::class);
        $this->service->grant_agent((int) $this->regular->id, $this->regular->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Revoke
     * -------------------------------------------------------------------------
     */

    public function test_revoke_agent_removes_managed_status(): void {
        $this->service->revoke_agent((int) $this->managed->id, $this->manager->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($this->service->is_agent((int) $this->managed->id));
        $this->assertFalse($this->service->is_managed_agent((int) $this->managed->id));
    }

    public function test_revoke_agent_refuses_unmanaged(): void {
        $this->expectException(\moodle_exception::class);
        $this->service->revoke_agent((int) $this->unmanaged->id, $this->manager->id);
    }

    public function test_revoke_agent_refuses_when_open_tickets_remain(): void {
        $this->insert_ticket($this->managed->id, ticket::STATUS_OPEN);

        $this->expectException(\moodle_exception::class);
        $this->service->revoke_agent((int) $this->managed->id, $this->manager->id);
    }

    public function test_revoke_agent_succeeds_when_only_closed_tickets(): void {
        $this->insert_ticket($this->managed->id, ticket::STATUS_CLOSED);

        $this->service->revoke_agent((int) $this->managed->id, $this->manager->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse($this->service->is_agent((int) $this->managed->id));
    }

    public function test_revoke_agent_requires_capability(): void {
        $this->expectException(\required_capability_exception::class);
        $this->service->revoke_agent((int) $this->managed->id, $this->regular->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Candidate search (A2 — onboarding)
     * -------------------------------------------------------------------------
     */

    public function test_search_candidate_users_finds_non_agent(): void {
        $results = $this->service->search_candidate_users($this->regular->email, $this->manager->id);
        $this->assertArrayHasKey((int) $this->regular->id, $results);
    }

    public function test_search_candidate_users_excludes_existing_agents(): void {
        // Both managed and unmanaged agents must be off the candidate list —
        // you cannot "add" someone who is already an agent.
        $managed = $this->service->search_candidate_users($this->managed->email, $this->manager->id);
        $this->assertArrayNotHasKey((int) $this->managed->id, $managed);

        $unmanaged = $this->service->search_candidate_users($this->unmanaged->email, $this->manager->id);
        $this->assertArrayNotHasKey((int) $this->unmanaged->id, $unmanaged);
    }

    public function test_search_candidate_users_matches_by_name(): void {
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Zoltan',
            'lastname'  => 'Xylophone',
            'email'     => 'zoltan.xylophone@example.com',
        ]);

        $results = $this->service->search_candidate_users('Xylophone', $this->manager->id);

        $this->assertArrayHasKey((int) $user->id, $results);
        $this->assertSame('Zoltan Xylophone', $results[(int) $user->id]->fullname);
    }

    public function test_search_candidate_users_requires_minimum_term(): void {
        $this->assertSame([], $this->service->search_candidate_users('a', $this->manager->id));
        $this->assertSame([], $this->service->search_candidate_users('', $this->manager->id));
    }

    public function test_search_candidate_users_excludes_suspended(): void {
        $suspended = $this->getDataGenerator()->create_user([
            'firstname' => 'Quinn',
            'lastname'  => 'Suspendo',
            'suspended' => 1,
        ]);

        $results = $this->service->search_candidate_users('Suspendo', $this->manager->id);
        $this->assertArrayNotHasKey((int) $suspended->id, $results);
    }

    public function test_search_candidate_users_requires_capability(): void {
        $this->expectException(\required_capability_exception::class);
        $this->service->search_candidate_users('example', $this->regular->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Offboard (A3 — requeue + revoke)
     * -------------------------------------------------------------------------
     */

    public function test_offboard_agent_requeues_open_tickets_and_revokes(): void {
        global $DB;

        $t1 = $this->insert_ticket($this->managed->id, ticket::STATUS_OPEN);
        $t2 = $this->insert_ticket($this->managed->id, ticket::STATUS_INPROGRESS);
        // A resolved ticket must NOT be requeued.
        $t3 = $this->insert_ticket($this->managed->id, ticket::STATUS_RESOLVED);

        $result = $this->service->offboard_agent((int) $this->managed->id, $this->manager->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Both open tickets returned to the queue; the resolved one untouched.
        $this->assertSame(2, $result->requeued);
        $this->assertNull($DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $t1]));
        $this->assertNull($DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $t2]));
        $this->assertEquals($this->managed->id, $DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $t3]));

        // Agent status revoked.
        $this->assertFalse($this->service->is_agent((int) $this->managed->id));
        $this->assertFalse($result->residual);
    }

    public function test_offboard_agent_refuses_unmanaged(): void {
        $this->expectException(\moodle_exception::class);
        $this->service->offboard_agent((int) $this->unmanaged->id, $this->manager->id);
    }

    public function test_offboard_agent_flags_residual_capability(): void {
        global $DB;

        // A user who is BOTH a managed agent AND an agent via another role.
        $dual = $this->getDataGenerator()->create_user();
        $systemcontext = \context_system::instance();
        $agentroleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        $corproleid  = $DB->get_field('role', 'id', ['shortname' => 'corp_agent'], MUST_EXIST);
        role_assign($agentroleid, $dual->id, $systemcontext->id);
        role_assign($corproleid, $dual->id, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();

        $result = $this->service->offboard_agent((int) $dual->id, $this->manager->id);
        accesslib_clear_all_caches_for_unit_testing();

        // Managed role gone, but agent status persists via the other role.
        $this->assertTrue($result->residual);
        $this->assertFalse($this->service->is_managed_agent((int) $dual->id));
        $this->assertTrue($this->service->is_agent((int) $dual->id));
    }

    public function test_offboard_agent_requires_capability(): void {
        $this->expectException(\required_capability_exception::class);
        $this->service->offboard_agent((int) $this->managed->id, $this->regular->id);
    }

    /*
     * -------------------------------------------------------------------------
     * Directory filters & attention (A1)
     * -------------------------------------------------------------------------
     */

    public function test_list_agents_filters_by_status(): void {
        global $DB;

        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $agentroleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($agentroleid, $suspended->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $active = $this->service->list_agents($this->manager->id, ['status' => 'active']);
        $this->assertArrayHasKey((int) $this->managed->id, $active);
        $this->assertArrayNotHasKey((int) $suspended->id, $active);

        $onlysuspended = $this->service->list_agents($this->manager->id, ['status' => 'suspended']);
        $this->assertArrayHasKey((int) $suspended->id, $onlysuspended);
        $this->assertArrayNotHasKey((int) $this->managed->id, $onlysuspended);
    }

    public function test_list_agents_filters_by_management(): void {
        $managed = $this->service->list_agents($this->manager->id, ['management' => 'managed']);
        $this->assertArrayHasKey((int) $this->managed->id, $managed);
        $this->assertArrayNotHasKey((int) $this->unmanaged->id, $managed);

        $unmanaged = $this->service->list_agents($this->manager->id, ['management' => 'unmanaged']);
        $this->assertArrayHasKey((int) $this->unmanaged->id, $unmanaged);
        $this->assertArrayNotHasKey((int) $this->managed->id, $unmanaged);
    }

    public function test_list_agents_filters_by_department(): void {
        $deptid = $this->insert_department('Support');
        $this->add_dept_member($deptid, (int) $this->managed->id);

        $results = $this->service->list_agents($this->manager->id, ['departmentid' => $deptid]);

        $this->assertArrayHasKey((int) $this->managed->id, $results);
        $this->assertArrayNotHasKey((int) $this->unmanaged->id, $results);
    }

    public function test_list_agents_flags_nomembership(): void {
        // Managed agent starts with no department/team membership.
        $before = $this->service->list_agents($this->manager->id);
        $this->assertTrue($before[(int) $this->managed->id]->nomembership);

        $deptid = $this->insert_department('Support');
        $this->add_dept_member($deptid, (int) $this->managed->id);

        $after = $this->service->list_agents($this->manager->id);
        $this->assertFalse($after[(int) $this->managed->id]->nomembership);
    }

    public function test_get_departments_without_members(): void {
        $empty  = $this->insert_department('Empty queue');
        $staffed = $this->insert_department('Staffed queue');
        $this->add_dept_member($staffed, (int) $this->managed->id);

        $result = $this->service->get_departments_without_members($this->manager->id);
        $ids    = array_map(static fn($d) => (int) $d->id, $result);

        $this->assertContains($empty, $ids);
        $this->assertNotContains($staffed, $ids);
    }

    /*
     * -------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------
     */

    /**
     * Insert a department and return its ID.
     *
     * @param string $name Department name.
     */
    private function insert_department(string $name): int {
        global $DB;
        return (int) $DB->insert_record('local_moodesk_department', (object) [
            'name'         => $name,
            'isactive'     => 1,
            'timecreated'  => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * Add a user to a department.
     *
     * @param int $departmentid Department to read.
     * @param int $userid       The user to act on.
     */
    private function add_dept_member(int $departmentid, int $userid): void {
        global $DB;
        $DB->insert_record('local_moodesk_dept_member', (object) [
            'departmentid' => $departmentid,
            'userid'       => $userid,
            'timecreated'  => time(),
        ]);
    }

    /**
     * Insert a minimal ticket assigned to an agent, in the given status.
     *
     * @param int $assigneeid
     * @param int $status
     * @return int New ticket id.
     */
    private function insert_ticket(int $assigneeid, int $status): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('local_moodesk_ticket', (object) [
            'userid'       => $assigneeid,
            'createdby'    => $assigneeid,
            'assigneeid'   => $assigneeid,
            'subject'      => 'Workload fixture',
            'status'       => $status,
            'priority'     => 1,
            'source'       => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
            'usermodified' => $assigneeid,
        ]);
    }
}
