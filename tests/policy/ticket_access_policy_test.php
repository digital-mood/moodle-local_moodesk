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
 * Unit tests for the ticket access policy.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\policy;

/**
 * Tests for ticket_access_policy.
 *
 * This class is the single answer to "may this actor reach this ticket", asked
 * by the ticket page, the drawer, the AJAX surface, the REST API, the merge
 * paths and the attachment callback. Before it was wired, four of those had
 * their own answer and each was weaker than the last, so the matrix below is
 * the contract itself rather than a sample of it.
 *
 * The department cases are the ones worth reading twice: they assert that
 * membership does *not* decide reach, which is the opposite
 * of what the code did before and what no test ever covered.
 *
 * @covers \local_moodesk\policy\ticket_access_policy
 */
final class ticket_access_policy_test extends \advanced_testcase {
    /** @var ticket_access_policy */
    private ticket_access_policy $policy;

    /** @var \stdClass The person who raised the ticket. */
    private \stdClass $requester;

    /** @var int Department the ticket belongs to. */
    private int $departmentid;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->policy    = new ticket_access_policy();
        $this->requester = $this->getDataGenerator()->create_user();

        $this->departmentid = (int) $DB->insert_record('local_moodesk_department', (object) [
            'name'         => 'Engineering',
            'isactive'     => 1,
            'timecreated'  => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * A ticket record shaped the way the repository returns one.
     *
     * @param int|null $departmentid Department the ticket sits in.
     * @param int|null $assigneeid   Agent it is assigned to.
     * @return \stdClass
     */
    private function ticket(?int $departmentid = null, ?int $assigneeid = null): \stdClass {
        return (object) [
            'id'           => 1,
            'userid'       => $this->requester->id,
            'departmentid' => $departmentid,
            'assigneeid'   => $assigneeid,
        ];
    }

    /**
     * A user holding exactly the capabilities named, and nothing else.
     *
     * Built from a bare role rather than from mhd_agent, because the seeded
     * role carries :viewall and :manage together and the interesting cases here
     * are the ones where they come apart.
     *
     * @param string[] $capabilities Capability names to allow at system context.
     * @return \stdClass
     */
    private function user_holding(array $capabilities): \stdClass {
        $context = \context_system::instance();
        $user    = $this->getDataGenerator()->create_user();
        $roleid  = $this->getDataGenerator()->create_role();

        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    public function test_the_system_actor_reaches_everything(): void {
        $this->assertTrue($this->policy->can_view(0, $this->ticket($this->departmentid)));
        $this->assertTrue($this->policy->can_manage(0, $this->ticket($this->departmentid)));
    }

    public function test_manage_reaches_every_ticket(): void {
        $manager = $this->user_holding(['local/moodesk:manage']);

        $this->assertTrue($this->policy->can_view((int) $manager->id, $this->ticket($this->departmentid)));
        $this->assertTrue($this->policy->can_manage((int) $manager->id, $this->ticket($this->departmentid)));
    }

    /**
     * The decision of 2026-09-04: viewall is global.
     *
     * An agent holding viewall but not manage, who is not a member of the
     * ticket's department and is not its assignee, reaches it anyway. This is
     * what makes the list and the detail agree.
     */
    public function test_viewall_is_global_and_does_not_depend_on_department_membership(): void {
        $agent = $this->user_holding(['local/moodesk:viewall']);

        $this->assertTrue($this->policy->can_view((int) $agent->id, $this->ticket($this->departmentid)));
        $this->assertTrue($this->policy->can_view((int) $agent->id, $this->ticket(null)));
    }

    public function test_viewall_does_not_confer_the_right_to_change_a_ticket(): void {
        $agent = $this->user_holding(['local/moodesk:viewall']);

        $this->assertFalse($this->policy->can_manage((int) $agent->id, $this->ticket($this->departmentid)));
    }

    public function test_a_requester_reaches_their_own_ticket(): void {
        $this->user_holding([]); // Nobody else in play.
        $requester = $this->requester;

        // The authenticated user archetype carries viewown, so assign it here
        // the way a real site grants it.
        $context = \context_system::instance();
        $roleid  = $this->getDataGenerator()->create_role();
        assign_capability('local/moodesk:viewown', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $requester->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->policy->can_view((int) $requester->id, $this->ticket()));
    }

    public function test_a_requester_does_not_reach_someone_elses_ticket(): void {
        $stranger = $this->user_holding(['local/moodesk:viewown']);

        $this->assertFalse($this->policy->can_view((int) $stranger->id, $this->ticket()));
    }

    public function test_a_user_with_no_helpdesk_capability_reaches_nothing(): void {
        $nobody = $this->user_holding([]);

        $this->assertFalse($this->policy->can_view((int) $nobody->id, $this->ticket()));
        $this->assertFalse($this->policy->can_manage((int) $nobody->id, $this->ticket()));
    }

    public function test_require_can_view_throws_for_a_ticket_out_of_reach(): void {
        $stranger = $this->user_holding(['local/moodesk:viewown']);

        $this->expectException(\required_capability_exception::class);
        $this->policy->require_can_view((int) $stranger->id, $this->ticket());
    }

    public function test_require_can_view_is_silent_for_a_ticket_in_reach(): void {
        $agent = $this->user_holding(['local/moodesk:viewall']);

        $this->policy->require_can_view((int) $agent->id, $this->ticket($this->departmentid));
        $this->expectNotToPerformAssertions();
    }

    /*
     * -------------------------------------------------------------------------
     * can_reply — the contract SEC-03 introduced.
     *
     * The row that matters is the third one: :reply without visibility. Before
     * this primitive existed, reply_service granted it and only the entry points
     * stopped it, which is why the one entry point that checked nothing — the
     * email pipeline — let a stranger post into anybody's thread (V6).
     * -------------------------------------------------------------------------
     */

    public function test_the_system_actor_may_reply(): void {
        $this->assertTrue($this->policy->can_reply(0, $this->ticket($this->departmentid)));
        $this->assertTrue($this->policy->can_add_note(0, $this->ticket($this->departmentid)));
    }

    public function test_a_requester_may_reply_to_their_own_ticket(): void {
        $context = \context_system::instance();
        $roleid  = $this->getDataGenerator()->create_role();
        assign_capability('local/moodesk:viewown', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $this->requester->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->policy->can_reply((int) $this->requester->id, $this->ticket()));
    }

    public function test_an_agent_with_reply_and_visibility_may_reply(): void {
        $agent = $this->user_holding(['local/moodesk:viewall', 'local/moodesk:reply']);

        $this->assertTrue($this->policy->can_reply((int) $agent->id, $this->ticket($this->departmentid)));
    }

    /**
     * Visibility is a precondition of writing, not a separate concern the
     * caller is trusted to have checked.
     */
    public function test_reply_without_visibility_is_denied(): void {
        $agent = $this->user_holding(['local/moodesk:reply']);

        $this->assertFalse($this->policy->can_reply((int) $agent->id, $this->ticket($this->departmentid)));
    }

    public function test_visibility_without_reply_is_denied(): void {
        $agent = $this->user_holding(['local/moodesk:viewall']);

        $this->assertFalse($this->policy->can_reply((int) $agent->id, $this->ticket($this->departmentid)));
    }

    public function test_an_unrelated_user_may_not_reply(): void {
        $stranger = $this->user_holding(['local/moodesk:viewown']);

        $this->assertFalse($this->policy->can_reply((int) $stranger->id, $this->ticket()));
    }

    public function test_a_site_admin_may_reply(): void {
        $this->setAdminUser();
        global $USER;

        $this->assertTrue($this->policy->can_reply((int) $USER->id, $this->ticket($this->departmentid)));
        $this->assertTrue($this->policy->can_add_note((int) $USER->id, $this->ticket($this->departmentid)));
    }

    public function test_require_can_reply_throws_for_a_denied_actor(): void {
        $agent = $this->user_holding(['local/moodesk:reply']);

        $this->expectException(\required_capability_exception::class);
        $this->policy->require_can_reply((int) $agent->id, $this->ticket($this->departmentid));
    }

    /*
     * -------------------------------------------------------------------------
     * can_add_note
     * -------------------------------------------------------------------------
     */

    public function test_addnote_with_visibility_is_allowed(): void {
        $agent = $this->user_holding(['local/moodesk:viewall', 'local/moodesk:addnote']);

        $this->assertTrue($this->policy->can_add_note((int) $agent->id, $this->ticket($this->departmentid)));
    }

    public function test_addnote_without_visibility_is_denied(): void {
        $agent = $this->user_holding(['local/moodesk:addnote']);

        $this->assertFalse($this->policy->can_add_note((int) $agent->id, $this->ticket($this->departmentid)));
    }

    /**
     * A requester never writes an internal note on their own ticket, however
     * completely they can see it. can_reply has a requester branch; this does
     * not, and that difference is the point.
     */
    public function test_a_requester_may_not_add_a_note_to_their_own_ticket(): void {
        $context = \context_system::instance();
        $roleid  = $this->getDataGenerator()->create_role();
        assign_capability('local/moodesk:viewown', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $this->requester->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue($this->policy->can_reply((int) $this->requester->id, $this->ticket()));
        $this->assertFalse($this->policy->can_add_note((int) $this->requester->id, $this->ticket()));
    }

    public function test_require_can_add_note_throws_for_a_denied_actor(): void {
        $agent = $this->user_holding(['local/moodesk:addnote']);

        $this->expectException(\required_capability_exception::class);
        $this->policy->require_can_add_note((int) $agent->id, $this->ticket($this->departmentid));
    }
}
