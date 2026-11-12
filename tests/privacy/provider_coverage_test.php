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
 * Regression tests for the five gaps audit finding R2 identified.
 *
 * The existing provider_test covers the shape of the contract: that export
 * produces something, that erasure removes something, that users are scoped
 * apart. It passed throughout, while a requester's attachments went unexported,
 * nine tables went undeclared and undeleted, and refused mail carrying somebody's
 * address was reachable by nothing at all.
 *
 * So these tests are written against the specific thing each gap got wrong,
 * rather than against the operation in general.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Tests for the R2 privacy gaps.
 *
 * @covers \local_moodesk\privacy\provider
 */
final class provider_coverage_test extends \advanced_testcase {
    /** @var \stdClass The requester whose data is under test. */
    private \stdClass $requester;

    /** @var int The requester's ticket. */
    private int $ticketid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        writer::reset();

        $this->requester = $this->getDataGenerator()->create_user(['email' => 'requester@example.test']);
        $this->ticketid  = $this->seed_ticket((int) $this->requester->id);
    }

    /**
     * A ticket owned by the given user, with one reply of theirs.
     *
     * @param int $userid The requester.
     * @return int The ticket id.
     */
    private function seed_ticket(int $userid): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_moodesk_ticket', (object) [
            'userid'       => $userid,
            'createdby'    => $userid,
            'subject'      => 'Printer on fire',
            'status'       => 1,
            'priority'     => 1,
            'source'       => 0,
            'sla_breached' => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);
    }

    /**
     * A reply on the ticket, authored by the given user.
     *
     * @param int $userid Author.
     * @return int The reply id.
     */
    private function seed_reply(int $userid): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_moodesk_reply', (object) [
            'ticketid'      => $this->ticketid,
            'userid'        => $userid,
            'message'       => 'Still on fire.',
            'messageformat' => FORMAT_PLAIN,
            'type'          => 0,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'usermodified'  => $userid,
        ]);
    }

    /**
     * Put one file into a plugin file area.
     *
     * @param string $area   'ticket_attachment' or 'reply_attachment'.
     * @param int    $itemid Ticket id or reply id.
     * @param string $name   File name.
     * @return void
     */
    private function seed_file(string $area, int $itemid, string $name): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'local_moodesk',
            'filearea'  => $area,
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $name,
        ], 'content');
    }

    /**
     * Run the export for one user.
     *
     * @param int $userid The subject.
     * @return void
     */
    private function export_for(int $userid): void {
        provider::export_user_data(new approved_contextlist(
            \core_user::get_user($userid),
            'local_moodesk',
            [\context_system::instance()->id]
        ));
    }

    /**
     * Erase one user.
     *
     * @param int $userid The subject.
     * @return void
     */
    private function delete_for(int $userid): void {
        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($userid),
            'local_moodesk',
            [\context_system::instance()->id]
        ));
    }

    /*
     * -------------------------------------------------------------------------
     * Gap 1 — the requester's own reply attachments
     * -------------------------------------------------------------------------
     */

    /**
     * A file attached to the requester's own reply reaches the export.
     *
     * The agent branch always exported this area; the requester branch never
     * did, so a person asking for their own data got their words and not their
     * files.
     */
    public function test_a_requesters_reply_attachment_is_exported(): void {
        $replyid = $this->seed_reply((int) $this->requester->id);
        $this->seed_file('reply_attachment', $replyid, 'evidence.pdf');

        $this->export_for((int) $this->requester->id);

        $files = writer::with_context(\context_system::instance())->get_files([
            get_string('pluginname', 'local_moodesk'),
            get_string('privacy_tickets', 'local_moodesk'),
            $this->ticketid,
            get_string('privacy_replies', 'local_moodesk'),
            $replyid,
        ]);

        $this->assertArrayHasKey(
            'evidence.pdf',
            $files,
            "A file attached to the requester's own reply was not exported."
        );
    }

    /*
     * The email routes in are covered by attachment_export_test, which drives
     * the ingestion service rather than seeding a row where the service is
     * assumed to put it. A hand-seeded copy of that case used to live here and
     * was removed: it asserted the same two lines as the test above and proved
     * nothing about email, which is where the original bug lived.
     */

    /*
     * -------------------------------------------------------------------------
     * Gap 3 — usermodified
     * -------------------------------------------------------------------------
     */

    /**
     * Erasure clears usermodified everywhere it appears.
     *
     * The provider's docblock named this personal data from the beginning and
     * no code ever touched it, so an erased user stayed named as the last editor.
     */
    public function test_erasure_clears_usermodified_on_every_table_that_has_it(): void {
        global $DB;

        $userid = (int) $this->requester->id;
        $now    = time();

        $DB->set_field('local_moodesk_ticket', 'usermodified', $userid, ['id' => $this->ticketid]);
        $replyid = $this->seed_reply($userid);
        $DB->set_field('local_moodesk_reply', 'usermodified', $userid, ['id' => $replyid]);

        $seeded = [
            'local_moodesk_category'   => ['name' => 'Billing'],
            'local_moodesk_department' => ['name' => 'Support'],
        ];

        foreach ($seeded as $table => $fields) {
            $DB->insert_record($table, (object) array_merge($fields, [
                'usermodified' => $userid,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]));
        }

        $this->delete_for($userid);

        foreach (array_merge(array_keys($seeded), ['local_moodesk_ticket', 'local_moodesk_reply']) as $table) {
            $this->assertSame(
                0,
                $DB->count_records($table, ['usermodified' => $userid]),
                "$table still names the erased user in usermodified."
            );
        }
    }

    /**
     * Wiping the whole context leaves no configuration row naming anybody.
     *
     * The wipe deletes the tables that exist to hold ticket data, but
     * configuration tables survive it by design — a department and a category
     * describe how the site is set up rather than what a person did. Their `usermodified` is declared
     * personal data all the same, so surviving the wipe is not the same as
     * keeping the name. This is the case the per-user erasure test cannot
     * catch: there, the row is found by the user id; here, every user is the
     * subject at once.
     */
    public function test_the_full_wipe_leaves_no_configuration_row_naming_a_user(): void {
        global $DB;

        $userid = (int) $this->requester->id;
        $now    = time();

        $seeded = [
            'local_moodesk_category'   => ['name' => 'Billing', 'sortorder' => 0, 'visible' => 1],
            'local_moodesk_department' => ['name' => 'Support', 'isactive' => 1],
        ];

        foreach ($seeded as $table => $fields) {
            $DB->insert_record($table, (object) array_merge($fields, [
                'usermodified' => $userid,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]));
        }

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        foreach (array_keys($seeded) as $table) {
            $this->assertSame(
                0,
                $DB->count_records($table, ['usermodified' => $userid]),
                "A full context wipe left $table naming a user in usermodified."
            );
            $this->assertGreaterThan(
                0,
                $DB->count_records($table),
                "The wipe deleted $table, which is configuration and has to survive it."
            );
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Gap 5 — user preferences
     * -------------------------------------------------------------------------
     */

    /**
     * Both preference shapes are exported.
     *
     * The sidebar state is one fixed key; saved views are one key per view under
     * a shared prefix, holding a name the user typed.
     */
    public function test_user_preferences_are_exported(): void {
        $userid = (int) $this->requester->id;

        set_user_preference('local_moodesk_sidebar_collapsed', 1, $userid);
        set_user_preference(
            \local_moodesk\service\saved_view_service::PREFIX . 'urgent',
            json_encode(['name' => 'My urgent tickets', 'params' => ['priority' => 3]]),
            $userid
        );

        provider::export_user_preferences($userid);

        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('local_moodesk');

        $this->assertArrayHasKey('local_moodesk_sidebar_collapsed', (array) $prefs);
        $this->assertArrayHasKey(
            \local_moodesk\service\saved_view_service::PREFIX . 'urgent',
            (array) $prefs,
            'A saved view was not exported.'
        );
    }

    /**
     * Erasure removes both preference shapes.
     *
     * A saved view holds a name the user typed and the filters behind it; it is
     * their content, not operational history worth keeping anonymously.
     */
    public function test_user_preferences_are_deleted(): void {
        $userid = (int) $this->requester->id;
        $viewkey = \local_moodesk\service\saved_view_service::PREFIX . 'urgent';

        set_user_preference('local_moodesk_sidebar_collapsed', 1, $userid);
        set_user_preference($viewkey, json_encode(['name' => 'My urgent tickets', 'params' => []]), $userid);

        $this->delete_for($userid);

        $this->assertNull(get_user_preferences('local_moodesk_sidebar_collapsed', null, $userid));
        $this->assertNull(get_user_preferences($viewkey, null, $userid));
    }

    /**
     * Another user's saved views survive an erasure that is not theirs.
     */
    public function test_another_users_saved_views_survive(): void {
        $other   = $this->getDataGenerator()->create_user();
        $viewkey = \local_moodesk\service\saved_view_service::PREFIX . 'mine';

        set_user_preference($viewkey, json_encode(['name' => 'Mine', 'params' => []]), (int) $other->id);

        $this->delete_for((int) $this->requester->id);

        $this->assertNotNull(get_user_preferences($viewkey, null, (int) $other->id));
    }
}
