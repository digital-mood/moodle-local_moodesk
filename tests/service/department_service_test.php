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
 * Integration tests for the department application service.
 *
 * Hits a real test database (no mocks) per project convention: services are
 * tested against their actual repository contracts, since the integration is
 * the contract.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Tests for department_service.
 *
 * @covers \local_moodesk\service\department_service
 */
final class department_service_test extends \advanced_testcase {
    /** @var department_service */
    private department_service $service;

    /** @var \stdClass User holding :managedepartment (manager archetype). */
    private \stdClass $manager;

    /** @var \stdClass User holding :agent (mhd_agent role). */
    private \stdClass $agent;

    /** @var \stdClass Plain authenticated user with no agent role. */
    private \stdClass $regular;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->service = department_service::create();

        $systemcontext = \context_system::instance();

        // Manager — wields :managedepartment via the manager archetype.
        $this->manager = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $this->manager->id, $systemcontext->id);

        // Agent — eligible to be added to a department.
        $this->agent = $this->getDataGenerator()->create_user();
        $agentroleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($agentroleid, $this->agent->id, $systemcontext->id);

        // Plain user — no helpdesk capabilities beyond authenticated defaults.
        $this->regular = $this->getDataGenerator()->create_user();

        accesslib_clear_all_caches_for_unit_testing();
    }

    public function test_create_department_persists_record(): void {
        global $DB;

        $data         = new \stdClass();
        $data->name   = 'Billing';
        $data->description = 'Accounts and refunds';

        $deptid = $this->service->create_department($data, $this->manager->id);

        $this->assertGreaterThan(0, $deptid);
        $record = $DB->get_record('local_moodesk_department', ['id' => $deptid], '*', MUST_EXIST);
        $this->assertSame('Billing', $record->name);
        $this->assertSame(1, (int) $record->isactive);
    }

    public function test_create_department_requires_name(): void {
        $data = new \stdClass();
        $data->name = '   '; // Whitespace-only must be rejected.

        $this->expectException(\moodle_exception::class);
        $this->service->create_department($data, $this->manager->id);
    }

    public function test_create_department_requires_capability(): void {
        $data       = new \stdClass();
        $data->name = 'Unauthorized creation attempt';

        $this->expectException(\required_capability_exception::class);
        $this->service->create_department($data, $this->regular->id);
    }

    public function test_add_member_then_is_member(): void {
        // Seed a department directly to avoid mixing concerns with create_department().
        $deptid = $this->insert_active_department('Support');

        $this->service->add_member($deptid, $this->agent->id, $this->manager->id);

        $memberids = $this->service->get_member_ids($deptid);
        $this->assertContains((int) $this->agent->id, $memberids);
        $this->assertSame(1, $this->service->get_member_count($deptid));
    }

    public function test_add_member_rejects_non_agent_user(): void {
        $deptid = $this->insert_active_department('Support');

        // Plain user lacks :agent — service must refuse to enroll them.
        $this->expectException(\moodle_exception::class);
        $this->service->add_member($deptid, $this->regular->id, $this->manager->id);
    }

    public function test_remove_member_reverses_addition(): void {
        $deptid = $this->insert_active_department('Support');

        $this->service->add_member($deptid, $this->agent->id, $this->manager->id);
        $this->assertSame(1, $this->service->get_member_count($deptid));

        $this->service->remove_member($deptid, $this->agent->id, $this->manager->id);
        $this->assertSame(0, $this->service->get_member_count($deptid));
    }

    public function test_get_user_departments_lists_only_active(): void {
        $active   = $this->insert_active_department('Active queue');
        $archived = $this->insert_archived_department('Old queue');

        $this->service->add_member($active, $this->agent->id, $this->manager->id);
        // Insert membership for archived dept directly — service blocks add to inactive.
        global $DB;
        $DB->insert_record('local_moodesk_dept_member', (object) [
            'departmentid' => $archived,
            'userid'       => $this->agent->id,
            'timecreated'  => time(),
        ]);

        $depts = $this->service->get_user_departments($this->agent->id);
        $ids   = array_map(static fn($d) => (int) $d->id, $depts);

        $this->assertContains($active, $ids);
        $this->assertNotContains($archived, $ids);
    }

    /*
     * -------------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------------
     */

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
            'usermodified' => $this->manager->id,
        ]);
    }

    /**
     * Insert an archived department and return its ID.
     *
     * @param string $name Department name.
     */
    private function insert_archived_department(string $name): int {
        global $DB;
        return (int) $DB->insert_record('local_moodesk_department', (object) [
            'name'         => $name,
            'isactive'     => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
            'usermodified' => $this->manager->id,
        ]);
    }
}
