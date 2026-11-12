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
 * Tests for the query cost of the agent roster.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\repository\department_repository;

/**
 * The roster's read count does not grow with the agents on it.
 *
 * Before 2.31.0 every row of the agent directory and of the supervision
 * screen looked up its departments one agent at a time: two
 * reads per agent, 105 for 50 agents on the scale bench
 * (docs/lab/scale-bench-2026-09-16.md §7.2). These cases
 * pin the membership prefetch by counting reads, not milliseconds, and check
 * that what each row says about its memberships did not change.
 *
 * @covers \local_moodesk\service\agent_service
 * @covers \local_moodesk\repository\department_repository
 */
final class agent_service_reads_test extends \advanced_testcase {
    /** @var \stdClass Manager — holds :manageagents via the manager archetype. */
    private \stdClass $manager;

    /** @var int The seeded mhd_agent role. */
    private int $agentroleid;

    /** @var department_repository */
    private department_repository $deptrepo;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        // Enterprise: the fixture assigns agents through Moodle's role API,
        // which the entitlement gate counts but never intercepts, and the gate
        // has its own suites.
        edition_service::set_edition_for_testing(edition_service::EDITION_ENTERPRISE);

        $systemcontext = \context_system::instance();
        $this->manager = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $this->manager->id, $systemcontext->id);
        $this->agentroleid = (int) $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);

        $this->deptrepo = new department_repository();
    }

    /**
     * Static state outlives the per-test database reset.
     */
    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * Create a department.
     *
     * @param string $name
     * @param int    $isactive
     * @return int
     */
    private function department(string $name, int $isactive = 1): int {
        return $this->deptrepo->insert((object) [
            'name'         => $name,
            'isactive'     => $isactive,
            'timecreated'  => time(),
            'timemodified' => time(),
            'usermodified' => $this->manager->id,
        ]);
    }

    /**
     * Add $count agents, each a member of the given departments.
     *
     * @param int   $count
     * @param int[] $departmentids
     * @return int[] User ids.
     */
    private function agents(int $count, array $departmentids): array {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $user  = $this->getDataGenerator()->create_user();
            $ids[] = (int) $user->id;
            role_assign($this->agentroleid, $user->id, \context_system::instance()->id);
            foreach ($departmentids as $departmentid) {
                $this->deptrepo->add_member($departmentid, (int) $user->id);
            }
        }
        accesslib_clear_all_caches_for_unit_testing();

        return $ids;
    }

    /**
     * Reads spent by one call, after a warm-up call so caches (roles,
     * strings, config) do not count.
     *
     * @param callable $call
     * @return array{reads: int, result: mixed}
     */
    private function measure(callable $call): array {
        global $DB;

        $call();

        $before = $DB->perf_get_reads();
        $result = $call();

        return ['reads' => $DB->perf_get_reads() - $before, 'result' => $result];
    }

    /**
     * The same number of reads for a roster of 5 and one of 40, every agent
     * in two departments: what grows is rows returned, not
     * queries sent.
     */
    public function test_directory_reads_do_not_grow_with_the_agents(): void {
        $depts = [$this->department('Billing'), $this->department('Access')];
        $service = agent_service::create();
        $list = fn() => $service->list_agents((int) $this->manager->id);

        $this->agents(5, $depts);
        $small = $this->measure($list);

        $this->agents(35, $depts);
        $large = $this->measure($list);

        $this->assertCount(5, $small['result']);
        $this->assertCount(40, $large['result']);
        $this->assertSame($small['reads'], $large['reads']);
    }

    /**
     * And each row still says what it said: its own memberships, sorted by
     * name, active departments only, and the no-membership flag for an agent
     * in neither.
     */
    public function test_rows_keep_their_own_memberships(): void {
        $billing  = $this->department('Billing');
        $access   = $this->department('Access');
        $archived = $this->department('Archived', 0);

        [$both]   = $this->agents(1, [$billing, $access, $archived]);
        [$one]    = $this->agents(1, [$access]);
        [$none]   = $this->agents(1, []);

        $roster = agent_service::create()->list_agents((int) $this->manager->id);

        $this->assertSame(['Access', 'Billing'], $roster[$both]->departments);
        $this->assertFalse($roster[$both]->nomembership);

        $this->assertSame(['Access'], $roster[$one]->departments);
        $this->assertFalse($roster[$one]->nomembership);

        $this->assertSame([], $roster[$none]->departments);
        $this->assertTrue($roster[$none]->nomembership);
    }

    /**
     * The batch lookups agree with their one-user forms, row for row.
     */
    public function test_batch_lookups_match_the_single_user_forms(): void {
        $billing = $this->department('Billing');
        $access  = $this->department('Access');

        [$a] = $this->agents(1, [$billing, $access]);
        [$b] = $this->agents(1, [$access]);
        $outsider = (int) $this->getDataGenerator()->create_user()->id;

        $depts = $this->deptrepo->get_user_departments_for([$a, $b, $outsider]);

        foreach ([$a, $b] as $userid) {
            $this->assertEquals($this->deptrepo->get_user_departments($userid), $depts[$userid] ?? []);
        }
        $this->assertArrayNotHasKey($outsider, $depts);
        $this->assertSame([], $this->deptrepo->get_user_departments_for([]));
    }
}
