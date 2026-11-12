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
 * Regression tests for the post-install seeding hook.
 *
 * The Moodle test bootstrap installs the plugin (running install.xml followed
 * by xmldb_local_moodesk_install) before any test runs. These tests assert the
 * post-install state and exercise the idempotency contract by re-running the
 * function after wiping the seeded rows.
 *
 * Two of them re-run it against a role that already exists, because that is
 * the branch a reinstall takes: the role survives an uninstall, its plugin
 * capabilities do not, and the install has to tell an emptied role from one
 * an administrator configured.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\db;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/moodesk/db/install.php');

/**
 * Tests for install.
 *
 * @covers ::xmldb_local_moodesk_install
 */
final class install_test extends \advanced_testcase {
    /** The tables that left this plugin with 3.0.0, without prefix. */
    private const PRO_TABLES = [
        'rule', 'rule_log', 'email_message', 'webhook', 'webhook_log', 'webhook_queue', 'cfield', 'cfield_value',
        'csat_rating', 'team', 'team_member', 'api_token', 'merge_audit', 'kb_feedback', 'kb_event', 'mailbox_cursor',
    ];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_install_seeds_default_category_and_config(): void {
        global $DB;

        $categoryid = (int) get_config('local_moodesk', 'defaultcategoryid');
        $this->assertGreaterThan(0, $categoryid, 'defaultcategoryid plugin config must be populated post-install.');

        $category = $DB->get_record('local_moodesk_category', ['id' => $categoryid], '*', MUST_EXIST);
        $this->assertSame(1, (int) $category->visible);
        $this->assertNotEmpty($category->name);
    }

    public function test_install_seeds_default_department(): void {
        global $DB;

        $count = $DB->count_records('local_moodesk_department');
        $this->assertGreaterThanOrEqual(1, $count, 'A default department must exist after install.');
    }

    public function test_install_seeds_agent_role_with_capabilities(): void {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'mhd_agent'], '*', MUST_EXIST);

        $configuredroleid = (int) get_config('local_moodesk', 'agentroleid');
        $this->assertSame((int) $role->id, $configuredroleid, 'agentroleid config must point at the seeded role.');

        $systemcontext = \context_system::instance();
        $expectedcaps = [
            'local/moodesk:agent',
            'local/moodesk:viewall',
            'local/moodesk:reply',
            'local/moodesk:addnote',
            'local/moodesk:manage',
            'local/moodesk:editkb',
        ];
        foreach ($expectedcaps as $cap) {
            $assigned = $DB->record_exists('role_capabilities', [
                'roleid'     => $role->id,
                'capability' => $cap,
                'contextid'  => $systemcontext->id,
                'permission' => CAP_ALLOW,
            ]);
            $this->assertTrue($assigned, "Capability {$cap} must be granted to mhd_agent at system context.");
        }
    }

    public function test_install_role_is_restricted_to_system_context(): void {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'mhd_agent'], '*', MUST_EXIST);
        $levels = array_map('intval', array_values(get_role_contextlevels($role->id)));

        $this->assertSame([CONTEXT_SYSTEM], $levels);
    }

    public function test_install_is_idempotent(): void {
        global $DB;

        // Wipe the seeded category, department and config keys, then re-run install.
        // Role is left in place to verify the existing-role branch is also idempotent.
        $DB->delete_records('local_moodesk_category');
        $DB->delete_records('local_moodesk_department');
        unset_config('defaultcategoryid', 'local_moodesk');

        $result = xmldb_local_moodesk_install();
        $this->assertTrue($result);

        $newcategoryid = (int) get_config('local_moodesk', 'defaultcategoryid');
        $this->assertGreaterThan(0, $newcategoryid);
        $this->assertTrue($DB->record_exists('local_moodesk_category', ['id' => $newcategoryid]));
        $this->assertGreaterThanOrEqual(1, $DB->count_records('local_moodesk_department'));

        // Role still exists; agentroleid config still points at it.
        $role = $DB->get_record('role', ['shortname' => 'mhd_agent'], '*', MUST_EXIST);
        $this->assertSame((int) $role->id, (int) get_config('local_moodesk', 'agentroleid'));

        // Second consecutive run must remain a no-op without errors.
        $this->assertTrue(xmldb_local_moodesk_install());
    }

    public function test_install_recovers_missing_default_category_config(): void {
        global $DB;

        // Simulate a partial state: category row exists, but the config key got cleared.
        unset_config('defaultcategoryid', 'local_moodesk');

        $this->assertTrue(xmldb_local_moodesk_install());

        $configured = (int) get_config('local_moodesk', 'defaultcategoryid');
        $this->assertGreaterThan(0, $configured);
        $this->assertTrue($DB->record_exists('local_moodesk_category', ['id' => $configured, 'visible' => 1]));
    }

    public function test_reinstall_restores_capabilities_to_a_role_that_lost_them(): void {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'mhd_agent'], '*', MUST_EXIST);
        $systemcontext = \context_system::instance();

        // What uninstall_plugin() leaves behind: the role, with every
        // role_capabilities row for a local/moodesk:* capability gone. Nothing
        // else about the role is touched — including where it may be assigned,
        // which an administrator widened here on purpose.
        set_role_contextlevels((int) $role->id, [CONTEXT_SYSTEM, CONTEXT_COURSECAT]);
        $DB->delete_records_select(
            'role_capabilities',
            'roleid = :roleid AND ' . $DB->sql_like('capability', ':prefix'),
            ['roleid' => $role->id, 'prefix' => $DB->sql_like_escape('local/moodesk:') . '%']
        );
        $this->assertSame(0, $this->count_plugin_capabilities((int) $role->id), 'Precondition: the role holds nothing.');

        $this->assertTrue(xmldb_local_moodesk_install());

        // The same role — not a second one — and it holds the six defaults again.
        $this->assertSame(1, $DB->count_records('role', ['shortname' => 'mhd_agent']));
        $this->assertSame((int) $role->id, (int) get_config('local_moodesk', 'agentroleid'));
        foreach (local_moodesk_install_agent_capabilities() as $cap) {
            $this->assertTrue($DB->record_exists('role_capabilities', [
                'roleid'     => $role->id,
                'capability' => $cap,
                'contextid'  => $systemcontext->id,
                'permission' => CAP_ALLOW,
            ]), "Reinstall must restore {$cap} to a role that came back with no capabilities.");
        }
        $this->assertSame(6, $this->count_plugin_capabilities((int) $role->id));

        // The context levels are the administrator's: only a freshly created
        // role gets the System-only restriction.
        $levels = array_map('intval', array_values(get_role_contextlevels((int) $role->id)));
        sort($levels);
        $this->assertSame([CONTEXT_SYSTEM, CONTEXT_COURSECAT], $levels);
    }

    public function test_reinstall_leaves_a_customised_role_alone(): void {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'mhd_agent'], '*', MUST_EXIST);
        $systemcontext = \context_system::instance();

        // An administrator's configuration: two of the defaults withdrawn, one
        // turned from allow into prevent, and one capability the seed never
        // grants added. The role is unmistakably theirs.
        unassign_capability('local/moodesk:manage', (int) $role->id, $systemcontext->id);
        unassign_capability('local/moodesk:addnote', (int) $role->id, $systemcontext->id);
        assign_capability('local/moodesk:reply', CAP_PREVENT, (int) $role->id, $systemcontext->id, true);
        assign_capability('local/moodesk:viewhealth', CAP_ALLOW, (int) $role->id, $systemcontext->id, true);
        $before = $this->plugin_capabilities((int) $role->id);
        $this->assertCount(5, $before, 'Precondition: agent, viewall, reply (prevent), editkb and viewhealth.');

        $this->assertTrue(xmldb_local_moodesk_install());

        // Row for row, permission for permission, exactly what was there.
        $this->assertSame($before, $this->plugin_capabilities((int) $role->id));
        $this->assertFalse($DB->record_exists('role_capabilities', [
            'roleid' => $role->id, 'capability' => 'local/moodesk:manage',
        ]), 'A capability the administrator withdrew must not come back.');
        $this->assertSame(CAP_PREVENT, (int) $DB->get_field('role_capabilities', 'permission', [
            'roleid' => $role->id, 'capability' => 'local/moodesk:reply', 'contextid' => $systemcontext->id,
        ]), 'A permission the administrator changed must keep its value.');
    }

    /**
     * Every local/moodesk:* row a role holds, keyed for comparison.
     *
     * @param int $roleid
     * @return array capability => permission, at every context, sorted by name.
     */
    private function plugin_capabilities(int $roleid): array {
        global $DB;

        $rows = $DB->get_records_select(
            'role_capabilities',
            'roleid = :roleid AND ' . $DB->sql_like('capability', ':prefix'),
            ['roleid' => $roleid, 'prefix' => $DB->sql_like_escape('local/moodesk:') . '%'],
            'capability, contextid'
        );

        $caps = [];
        foreach ($rows as $row) {
            $caps[$row->capability . '@' . $row->contextid] = (int) $row->permission;
        }
        return $caps;
    }

    /**
     * How many local/moodesk:* rows a role holds, in any state, at any context.
     *
     * @param int $roleid
     * @return int
     */
    private function count_plugin_capabilities(int $roleid): int {
        return count($this->plugin_capabilities($roleid));
    }

    public function test_the_pro_tables_are_not_declared_here(): void {
        global $CFG;

        $xml = file_get_contents($CFG->dirroot . '/local/moodesk/db/install.xml');
        preg_match_all('~<TABLE NAME="([a-z_]+)"~', $xml, $matches);

        $this->assertCount(8, $matches[1]);
        foreach (self::PRO_TABLES as $table) {
            $this->assertNotContains("local_moodesk_{$table}", $matches[1]);
            $this->assertNotContains("local_moodeskpro_{$table}", $matches[1]);
        }
    }

    public function test_the_pro_columns_are_not_declared_here(): void {
        global $CFG;

        $xml = file_get_contents($CFG->dirroot . '/local/moodesk/db/install.xml');
        preg_match_all('~<FIELD NAME="([a-z_]+)"~', $xml, $matches);

        $columns = ['teamid', 'mergedinto', 'merged_status', 'merged_audit_id', 'tokenepoch'];
        foreach (['response', 'resolve'] as $kind) {
            foreach (['low', 'normal', 'high', 'urgent'] as $priority) {
                $columns[] = "sla_{$kind}_hours_{$priority}";
            }
        }
        $this->assertCount(13, $columns);
        $this->assertSame([], array_values(array_intersect($columns, $matches[1])));
        $this->assertStringNotContainsString('ix_teamid', $xml);
    }
}
