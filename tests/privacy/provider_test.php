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
 * Tests for the local_moodesk privacy provider.
 *
 * Verifies metadata declaration, context/user discovery, export, and the
 * two deletion paths (full-context wipe and per-user anonymisation) across
 * every table of the base that stores personal or indirectly-personal data.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use local_moodesk\hook\user_erasing;

/**
 * Privacy provider unit tests.
 *
 * @covers \local_moodesk\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    /** @var int Requester whose data is the subject of export/erasure. */
    private int $requesterid;

    /** @var int A second requester, used to prove isolation between users. */
    private int $otherid;

    /** @var int The agent who replies to and is assigned the requester's ticket. */
    private int $agentid;

    /** @var int The requester's ticket id. */
    private int $ticketid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        writer::reset();

        $requester = $this->getDataGenerator()->create_user();
        $other     = $this->getDataGenerator()->create_user();
        $agent     = $this->getDataGenerator()->create_user();
        $this->requesterid = (int) $requester->id;
        $this->otherid     = (int) $other->id;
        $this->agentid     = (int) $agent->id;

        $this->ticketid = $this->seed_ticket_graph($this->requesterid, $this->agentid);
        // A separate ticket owned by another user, to prove export/erasure scoping.
        $this->seed_ticket_graph($this->otherid, $this->agentid);
        // Agent-level membership data, seeded once (not per ticket).
        $this->seed_agent_memberships($this->agentid);
    }

    /**
     * Insert a ticket plus one row in every PII-bearing child table.
     *
     * @param int $requesterid Owner of the ticket.
     * @param int $agentid     Assigned agent / reply author.
     * @return int The new ticket id.
     */
    private function seed_ticket_graph(int $requesterid, int $agentid): int {
        global $DB;
        $now = time();

        $ticketid = (int) $DB->insert_record('local_moodesk_ticket', (object) [
            'userid'       => $requesterid,
            'createdby'    => $requesterid,
            'assigneeid'   => $agentid,
            'subject'      => 'Sensitive subject for ' . $requesterid,
            'status'       => 1,
            'priority'     => 1,
            'source'       => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
            'sla_breached' => 0,
            'usermodified' => $requesterid,
        ]);

        // Requester's own reply.
        $DB->insert_record('local_moodesk_reply', (object) [
            'ticketid'      => $ticketid,
            'userid'        => $requesterid,
            'message'       => 'Requester message body with PII.',
            'messageformat' => FORMAT_PLAIN,
            'type'          => 0,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'usermodified'  => $requesterid,
        ]);

        // Agent reply on the requester's ticket.
        $DB->insert_record('local_moodesk_reply', (object) [
            'ticketid'      => $ticketid,
            'userid'        => $agentid,
            'message'       => 'Agent answer body.',
            'messageformat' => FORMAT_PLAIN,
            'type'          => 0,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'usermodified'  => $agentid,
        ]);

        $DB->insert_record('local_moodesk_ticket_history', (object) [
            'ticketid'    => $ticketid,
            'userid'      => $requesterid,
            'action'      => 'created',
            'oldvalue'    => null,
            'newvalue'    => json_encode(['status' => 1]),
            'timecreated' => $now,
        ]);

        return $ticketid;
    }

    /**
     * Seed one department membership for the agent (agent-level data, not
     * per-ticket).
     *
     * @param int $agentid
     */
    private function seed_agent_memberships(int $agentid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_moodesk_dept_member', (object) [
            'departmentid' => 1,
            'userid'       => $agentid,
            'timecreated'  => $now,
        ]);
    }

    /**
     * The metadata collection must declare the tables the plugin reads.
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('local_moodesk');
        $collection = provider::get_metadata($collection);
        $items      = $collection->get_collection();

        $this->assertNotEmpty($items);

        $tables = [];
        foreach ($items as $item) {
            $tables[] = $item->get_name();
        }

        $this->assertContains('local_moodesk_ticket', $tables);
        $this->assertContains('local_moodesk_reply', $tables);
    }

    /**
     * Every item in the metadata collection has to name something real.
     *
     * Core checks this in core_privacy\privacy\provider_test, parameterised
     * over every installed component — and nothing in this project's CI runs
     * core's tests. It found `core_files` declared as a plugin *type* rather
     * than the subsystem it is, which made mooDesk the only component in the
     * whole Moodle tree calling add_plugintype_link('core_files').
     */
    public function test_metadata_items_name_real_things(): void {
        global $DB;

        $collection = new \core_privacy\local\metadata\collection('local_moodesk');
        $collection = provider::get_metadata($collection);

        $plugintypes = \core_component::get_plugin_types();

        foreach ($collection->get_collection() as $item) {
            $name = $item->get_name();

            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $this->assertTrue(
                    $DB->get_manager()->table_exists($name),
                    "Privacy metadata declares table {$name}, which does not exist"
                );
            }

            if ($item instanceof \core_privacy\local\metadata\types\plugintype_link) {
                $this->assertArrayHasKey(
                    $name,
                    $plugintypes,
                    "Privacy metadata declares plugin type {$name}, which is not a plugin type"
                );
            }

            if ($item instanceof \core_privacy\local\metadata\types\subsystem_link) {
                [$plugintype, $pluginname] = \core_component::normalize_component($name);
                $this->assertEquals('core', $plugintype);
                $this->assertTrue(
                    \core_component::is_core_subsystem($pluginname),
                    "Privacy metadata declares subsystem {$name}, which is not a core subsystem"
                );
            }
        }
    }

    /**
     * A requester resolves to exactly the system context.
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid($this->requesterid);
        $this->assertCount(1, $contextlist);
        $this->assertEquals(
            \context_system::instance()->id,
            $contextlist->get_contextids()[0]
        );
    }

    /**
     * Requester, other requester, and agent are all discoverable in the
     * system context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new \core_privacy\local\request\userlist(
            \context_system::instance(),
            'local_moodesk'
        );
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();

        $this->assertContains($this->requesterid, $userids);
        $this->assertContains($this->agentid, $userids);
        $this->assertContains($this->otherid, $userids);
    }

    /**
     * Exporting the requester writes data into the system context.
     */
    public function test_export_user_data(): void {
        $context = \context_system::instance();
        $this->export_for_user($this->requesterid);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * A full-context wipe removes every plugin row.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertEquals(0, $DB->count_records('local_moodesk_ticket'));
        $this->assertEquals(0, $DB->count_records('local_moodesk_reply'));
        $this->assertEquals(0, $DB->count_records('local_moodesk_ticket_history'));
        $this->assertEquals(0, $DB->count_records('local_moodesk_dept_member'));
    }

    /**
     * Per-user erasure anonymises the requester's data while leaving the
     * other user's ticket graph untouched.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $anon = get_string('privacy_anon_user', 'local_moodesk');

        $approved = new approved_contextlist(
            \core_user::get_user($this->requesterid),
            'local_moodesk',
            [\context_system::instance()->id]
        );
        provider::delete_data_for_user($approved);

        // Requester's ticket is redacted and de-linked.
        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $this->ticketid]);
        $this->assertEquals(0, (int) $ticket->userid);
        $this->assertEquals($anon, $ticket->subject);

        // Requester's reply is redacted; the agent reply survives.
        $this->assertEquals(
            0,
            $DB->count_records('local_moodesk_reply', ['userid' => $this->requesterid])
        );

        // Agent membership rows for the agent are dropped (right to erasure of
        // the agent is a separate call; here membership belongs to the agent,
        // so the requester erasure must NOT touch it).
        $this->assertEquals(
            1,
            $DB->count_records('local_moodesk_dept_member', ['userid' => $this->agentid])
        );

        // The other user's ticket is completely untouched.
        $others = $DB->get_records('local_moodesk_ticket', ['userid' => $this->otherid]);
        $this->assertCount(1, $others);
    }

    /**
     * Erasure via an approved userlist anonymises the named user.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $approved = new approved_userlist(
            \context_system::instance(),
            'local_moodesk',
            [$this->requesterid]
        );
        provider::delete_data_for_users($approved);

        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $this->ticketid]);
        $this->assertEquals(0, (int) $ticket->userid);
    }

    /**
     * Plugins hear of an erasure while the ids still lead back to the person.
     */
    public function test_erasure_tells_plugins_before_the_references_go(): void {
        global $DB;

        $seen = null;
        $this->redirectHook(user_erasing::class, static function (user_erasing $hook) use (&$seen, $DB): void {
            $seen = [
                'hook'   => $hook,
                'owners' => $DB->count_records('local_moodesk_ticket', ['userid' => $hook->userid]),
            ];
        });

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->requesterid),
            'local_moodesk',
            [\context_system::instance()->id]
        ));
        $this->stopHookRedirections();

        $this->assertSame($this->requesterid, $seen['hook']->userid);
        $this->assertEquals([$this->ticketid], $seen['hook']->ticketids);
        $this->assertCount(1, $seen['hook']->replyids);
        $this->assertSame(1, $seen['owners'], 'The ticket no longer led back to the person.');
    }

    /**
     * Run an export for the given user against the system context.
     *
     * @param int $userid
     */
    private function export_for_user(int $userid): void {
        $approved = new approved_contextlist(
            \core_user::get_user($userid),
            'local_moodesk',
            [\context_system::instance()->id]
        );
        provider::export_user_data($approved);
    }
}
