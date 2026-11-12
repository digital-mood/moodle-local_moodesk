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
 * Erasure reaches user ids frozen inside the ticket history's JSON.
 *
 * The case every path in the provider used to miss: an agent is named inside a
 * row whose *subject* is somebody else. The value keeps the assignee it was
 * written with even after the ticket has been reassigned.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

use core_privacy\local\request\approved_contextlist;

/**
 * Tests for JSON snapshot redaction.
 *
 * @covers \local_moodesk\privacy\provider
 */
final class snapshot_redaction_test extends \advanced_testcase {
    /** @var \stdClass The agent being erased, assignee on somebody else's ticket. */
    private \stdClass $agent;

    /** @var \stdClass The requester who owns the ticket, and stays. */
    private \stdClass $requester;

    /** @var \stdClass A second agent who also stays. */
    private \stdClass $otheragent;

    /** @var int The requester's ticket. */
    private int $ticketid;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $this->requester  = $this->getDataGenerator()->create_user();
        $this->agent      = $this->getDataGenerator()->create_user();
        $this->otheragent = $this->getDataGenerator()->create_user();

        $now = time();

        $this->ticketid = (int) $DB->insert_record('local_moodesk_ticket', (object) [
            'userid'       => $this->requester->id,
            'createdby'    => $this->requester->id,
            'assigneeid'   => $this->agent->id,
            'subject'      => 'Printer on fire',
            'status'       => 1,
            'priority'     => 1,
            'source'       => 0,
            'sla_breached' => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
    }

    /*
     * -------------------------------------------------------------------------
     * Harness
     * -------------------------------------------------------------------------
     */

    /**
     * Insert a history row for an assignment change.
     *
     * @param string      $action   The history action.
     * @param string|null $oldvalue Encoded old value.
     * @param string|null $newvalue Encoded new value.
     * @return int The history row id.
     */
    private function seed_history(string $action, ?string $oldvalue, ?string $newvalue): int {
        global $DB;

        return (int) $DB->insert_record('local_moodesk_ticket_history', (object) [
            'ticketid'    => $this->ticketid,
            // The actor is a third party on purpose: the row must be found by
            // what the JSON says, not by the column the old code scoped on.
            'userid'      => $this->otheragent->id,
            'action'      => $action,
            'oldvalue'    => $oldvalue,
            'newvalue'    => $newvalue,
            'timecreated' => time(),
        ]);
    }

    /**
     * Erase one user through the Privacy API.
     *
     * @param \stdClass $user The subject.
     * @return void
     */
    private function erase(\stdClass $user): void {
        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($user->id),
            'local_moodesk',
            [\context_system::instance()->id]
        ));
    }

    /**
     * The decoded JSON of one column.
     *
     * @param string $table  The table.
     * @param int    $id     The row id.
     * @param string $column The JSON column.
     * @return array The decoded value.
     */
    private function decoded(string $table, int $id, string $column): array {
        global $DB;

        $raw = $DB->get_field($table, $column, ['id' => $id]);
        $this->assertNotEmpty($raw, "$table.$column was emptied when it only needed redacting.");

        $decoded = json_decode((string) $raw, true);
        $this->assertIsArray($decoded, "$table.$column is no longer valid JSON.");

        return $decoded;
    }

    /*
     * -------------------------------------------------------------------------
     * The two columns
     * -------------------------------------------------------------------------
     */

    /**
     * 3 — the assignee an assignment moved away from.
     */
    public function test_erasure_clears_the_assignee_from_ticket_history_oldvalue(): void {
        $historyid = $this->seed_history(
            'assigned',
            json_encode(['assigneeid' => (int) $this->agent->id]),
            json_encode(['assigneeid' => (int) $this->otheragent->id])
        );

        $this->erase($this->agent);

        $old = $this->decoded('local_moodesk_ticket_history', $historyid, 'oldvalue');
        $this->assertSame(-1, $old['assigneeid']);
    }

    /**
     * 4 — the assignee an assignment moved to.
     */
    public function test_erasure_clears_the_assignee_from_ticket_history_newvalue(): void {
        $historyid = $this->seed_history(
            'assigned',
            json_encode(['assigneeid' => null]),
            json_encode(['assigneeid' => (int) $this->agent->id])
        );

        $this->erase($this->agent);

        $new = $this->decoded('local_moodesk_ticket_history', $historyid, 'newvalue');
        $this->assertSame(-1, $new['assigneeid']);
    }
}
