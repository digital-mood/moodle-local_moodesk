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
 * How local/moodesk:editkb reaches the roles of a site that already exists.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\db;

/**
 * Tests for the `clonepermissionsfrom` declaration on :editkb.
 *
 * A fresh site gets :editkb on the agent role from db/install.php, and
 * tests/db/install_test.php covers that. An upgraded site has no install
 * step to run, and db/upgrade.php touches no role on purpose — the decision
 * of 2026-09-12 was to let core do it: when update_capabilities() registers a
 * new capability declaring `clonepermissionsfrom`, it copies every
 * role_capabilities row of the named capability onto the new one, permission
 * included. That is what an upgrading site will experience, and this file
 * walks exactly that path: it removes :editkb as though it had never been
 * registered, sets up the roles a real site might have, and runs the same
 * core function the upgrade runs.
 *
 * @covers \local_moodesk\db
 */
final class capability_clone_test extends \advanced_testcase {
    /** @var \context_system */
    private \context_system $context;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->context = \context_system::instance();
    }

    /**
     * Put the site in the state an upgrade finds it in: :editkb unknown.
     */
    private function forget_editkb(): void {
        global $DB;
        $DB->delete_records('role_capabilities', ['capability' => 'local/moodesk:editkb']);
        $DB->delete_records('capabilities', ['name' => 'local/moodesk:editkb']);
        \cache::make('core', 'capabilities')->delete('core_capabilities');
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A role holding :reply with the given permission at system context.
     *
     * @param int|null $permission CAP_* constant, or null for a role without :reply.
     * @return int roleid
     */
    private function role_with_reply(?int $permission): int {
        $roleid = $this->getDataGenerator()->create_role();
        if ($permission !== null) {
            assign_capability('local/moodesk:reply', $permission, $roleid, $this->context->id, true);
        }
        return $roleid;
    }

    /**
     * The permission a role holds on :editkb at system context, or null.
     *
     * @param int $roleid
     * @return int|null
     */
    private function editkb_permission(int $roleid): ?int {
        global $DB;
        $permission = $DB->get_field('role_capabilities', 'permission', [
            'roleid'     => $roleid,
            'capability' => 'local/moodesk:editkb',
            'contextid'  => $this->context->id,
        ]);
        return $permission === false ? null : (int) $permission;
    }

    /**
     * The declaration itself, so the behaviour below is tied to a decision
     * somebody can read in db/access.php rather than to an accident.
     */
    public function test_editkb_declares_where_it_clones_from(): void {
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/local/moodesk/db/access.php');

        $this->assertSame('local/moodesk:reply', $capabilities['local/moodesk:editkb']['clonepermissionsfrom'] ?? null);
        $this->assertSame(
            [],
            $capabilities['local/moodesk:editkb']['archetypes'] ?? null,
            'Archetypes are ignored when cloning; keep them empty so nobody expects otherwise.'
        );
    }

    /**
     * On upgrade, every role's :reply — allow, prevent, prohibit — becomes its
     * :editkb, and a role without :reply gets nothing.
     */
    public function test_upgrade_clones_reply_onto_editkb_for_every_role(): void {
        $allow    = $this->role_with_reply(CAP_ALLOW);
        $prevent  = $this->role_with_reply(CAP_PREVENT);
        $prohibit = $this->role_with_reply(CAP_PROHIBIT);
        $noreply  = $this->role_with_reply(null);
        $this->forget_editkb();

        // Precondition: nothing holds a capability the site does not know.
        foreach ([$allow, $prevent, $prohibit, $noreply] as $roleid) {
            $this->assertNull($this->editkb_permission($roleid));
        }

        // What db/upgrade.php leads to, without db/upgrade.php.
        update_capabilities('local_moodesk');

        $this->assertSame(CAP_ALLOW, $this->editkb_permission($allow));
        $this->assertSame(CAP_PREVENT, $this->editkb_permission($prevent));
        $this->assertSame(CAP_PROHIBIT, $this->editkb_permission($prohibit));
        $this->assertNull($this->editkb_permission($noreply));
    }

    /**
     * The seeded agent role is one of those roles: it holds :reply, so an
     * upgraded site's agents can draft articles the moment the upgrade ends.
     */
    public function test_the_seeded_agent_role_gains_editkb_on_upgrade(): void {
        global $DB;
        $role = $DB->get_record('role', ['shortname' => 'mhd_agent'], '*', MUST_EXIST);
        $this->forget_editkb();
        $this->assertNull($this->editkb_permission((int) $role->id));

        update_capabilities('local_moodesk');

        $this->assertSame(CAP_ALLOW, $this->editkb_permission((int) $role->id));
    }

    /**
     * Cloning is a one-time event: once :editkb is registered, a later
     * update_capabilities() run leaves an administrator's changes alone.
     */
    public function test_cloning_does_not_repeat_once_registered(): void {
        $roleid = $this->role_with_reply(CAP_ALLOW);
        $this->forget_editkb();
        update_capabilities('local_moodesk');
        $this->assertSame(CAP_ALLOW, $this->editkb_permission($roleid));

        // The administrator decides this role should not draft articles.
        unassign_capability('local/moodesk:editkb', $roleid, $this->context->id);
        $this->assertNull($this->editkb_permission($roleid));

        update_capabilities('local_moodesk');
        $this->assertNull($this->editkb_permission($roleid), 'A second run must not re-clone.');
    }

    /**
     * The other two capabilities follow their archetypes, as every manager
     * and requester capability before them does.
     */
    public function test_viewkb_and_managekb_follow_their_archetypes(): void {
        global $DB;

        $userrole    = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);

        $this->assertTrue($DB->record_exists('role_capabilities', [
            'roleid' => $userrole, 'capability' => 'local/moodesk:viewkb', 'permission' => CAP_ALLOW,
        ]));
        $this->assertTrue($DB->record_exists('role_capabilities', [
            'roleid' => $managerrole, 'capability' => 'local/moodesk:managekb', 'permission' => CAP_ALLOW,
        ]));
        $this->assertFalse($DB->record_exists('role_capabilities', [
            'roleid' => $userrole, 'capability' => 'local/moodesk:editkb',
        ]), 'Authenticated users do not draft articles.');
    }
}
