<?php
// This file is part of Moodle - https://moodle.org/
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
 * Privacy of the Pro tables an upgraded site keeps from 2.x.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_moodesk\db\legacy_pro_tables;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../fixtures/legacy_pro_tables.php');

/**
 * Export and erasure reach the legacy tables, and only where they exist.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\privacy\legacy_pro_data
 * @covers    \local_moodesk\privacy\provider
 */
final class legacy_pro_data_test extends \advanced_testcase {
    use legacy_pro_tables;

    /** @var \stdClass The person whose data is asked for. */
    private \stdClass $user;

    /** @var \stdClass Somebody else. */
    private \stdClass $other;

    /** @var int A ticket the person raised. */
    private int $ticketid;

    protected function tearDown(): void {
        $this->drop_legacy_pro_tables();
        parent::tearDown();
    }

    public function test_a_site_without_the_tables_has_nothing_to_find(): void {
        $this->resetAfterTest();
        $this->drop_legacy_pro_tables();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(legacy_pro_data::present());
        $this->assertFalse(legacy_pro_data::holds_data((int) $user->id));
        legacy_pro_data::erase_user((int) $user->id, []);
        legacy_pro_data::delete_all();
        $this->assertCount(0, provider::get_contexts_for_userid((int) $user->id));
    }

    public function test_the_tables_are_declared_only_where_they_exist(): void {
        $this->resetAfterTest();
        $this->drop_legacy_pro_tables();
        $this->assertCount(0, legacy_pro_data::add_metadata(new collection('local_moodesk'))->get_collection());
    }

    public function test_the_tables_are_declared_with_strings(): void {
        $this->resetAfterTest();
        $this->install_legacy_pro_tables();
        $collection = legacy_pro_data::add_metadata(new collection('local_moodesk'));
        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
            $this->assertTrue(get_string_manager()->string_exists($item->get_summary(), 'local_moodesk'));
            foreach ($item->get_privacy_fields() as $field => $string) {
                $this->assertTrue(get_string_manager()->string_exists($string, 'local_moodesk'), $string);
            }
        }
        $this->assertContains('local_moodesk_csat_rating', $names);
        $this->assertContains('local_moodesk_email_message', $names);
        $this->assertCount(15, $names);
    }

    public function test_a_person_named_only_by_the_legacy_tables_is_found(): void {
        $this->resetAfterTest();
        $this->seed();

        $this->assertTrue(legacy_pro_data::holds_data((int) $this->user->id));
        $this->assertCount(1, provider::get_contexts_for_userid((int) $this->user->id));

        $userlist = new userlist(\context_system::instance(), 'local_moodesk');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $this->user->id, array_map('intval', $userlist->get_userids()));
    }

    public function test_the_export_carries_the_legacy_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed();
        $context = \context_system::instance();

        provider::export_user_data(new approved_contextlist($this->user, 'local_moodesk', [$context->id]));

        $writer = writer::with_context($context);
        $base = [get_string('pluginname', 'local_moodesk'), get_string('privacy_legacy', 'local_moodesk')];
        $at = function (string $area, string $table, array $where) use ($writer, $base): \stdClass {
            global $DB;
            $id = $DB->get_field('local_moodesk_' . $table, 'id', $where, MUST_EXIST);
            $data = $writer->get_data(array_merge($base, [get_string($area, 'local_moodesk'), $id]));
            $this->assertNotEmpty($data, $area);
            return (object) $data;
        };

        $this->assertSame('Great help', $at('privacy_csat_ratings', 'csat_rating', ['ticketid' => $this->ticketid])->comment);
        $this->assertSame(
            'person@example.test',
            $at('privacy_email_audit', 'email_message', ['message_id' => 'person@mail'])->sender_email
        );
        $at('privacy_team_memberships', 'team_member', ['userid' => $this->user->id]);
        $at('privacy_kb_feedback', 'kb_feedback', ['userid' => $this->user->id]);
        $at('privacy_api_tokens', 'api_token', ['userid' => $this->user->id]);
        $at('privacy_merges', 'merge_audit', ['actorid' => $this->user->id]);
        $this->assertEmpty($writer->get_data(array_merge($base, [get_string('privacy_email_audit', 'local_moodesk'),
            $DB->get_field('local_moodesk_email_message', 'id', ['message_id' => 'other@mail'])])));
    }

    public function test_erasure_removes_or_anonymises_the_persons_rows_only(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed();
        $context = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist($this->user, 'local_moodesk', [$context->id]));

        $uid = (int) $this->user->id;
        $this->assertFalse($DB->record_exists('local_moodesk_team_member', ['userid' => $uid]));
        $this->assertFalse($DB->record_exists('local_moodesk_kb_feedback', ['userid' => $uid]));
        $this->assertFalse($DB->record_exists('local_moodesk_api_token', ['userid' => $uid]));
        $this->assertNull($DB->get_field('local_moodesk_csat_rating', 'comment', ['ticketid' => $this->ticketid]));
        $this->assertFalse($DB->record_exists('local_moodesk_merge_audit', ['actorid' => $uid]));
        $mail = $DB->get_record('local_moodesk_email_message', ['message_id' => 'person@mail']);
        $this->assertNotSame('person@example.test', $mail->sender_email);
        $this->assertNull($mail->resolved_userid);
        $this->assertFalse(legacy_pro_data::holds_data($uid));

        // The other person's rows are untouched.
        $this->assertTrue($DB->record_exists('local_moodesk_team_member', ['userid' => $this->other->id]));
        $this->assertSame(
            'other@example.test',
            $DB->get_field('local_moodesk_email_message', 'sender_email', ['message_id' => 'other@mail'])
        );
    }

    public function test_erasure_by_userlist_reaches_the_legacy_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed();

        provider::delete_data_for_users(
            new approved_userlist(\context_system::instance(), 'local_moodesk', [(int) $this->user->id])
        );

        $this->assertFalse($DB->record_exists('local_moodesk_team_member', ['userid' => $this->user->id]));
        $this->assertTrue($DB->record_exists('local_moodesk_team_member', ['userid' => $this->other->id]));
    }

    public function test_a_wipe_of_the_system_context_empties_the_personal_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->seed();

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        foreach (['team_member', 'kb_feedback', 'api_token', 'csat_rating', 'email_message', 'merge_audit'] as $short) {
            $this->assertSame(0, $DB->count_records('local_moodesk_' . $short), $short);
        }
        $this->assertSame(0, $DB->count_records_select('local_moodesk_team', 'usermodified <> 0'));
    }

    /**
     * Install the legacy tables and give both people rows in them.
     */
    private function seed(): void {
        global $DB;

        $this->install_legacy_pro_tables();
        $this->user  = $this->getDataGenerator()->create_user(['email' => 'person@example.test']);
        $this->other = $this->getDataGenerator()->create_user(['email' => 'other@example.test']);
        $now = time();

        $this->ticketid = (int) $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_ticket([
            'userid' => $this->user->id,
            'createdby' => $this->user->id,
        ])->id;
        $teamid = (int) $DB->insert_record('local_moodesk_team', (object) [
            'name' => 'Team', 'isactive' => 1, 'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $this->other->id,
        ]);
        foreach ([$this->user, $this->other] as $person) {
            $DB->insert_record('local_moodesk_team_member', (object) [
                'teamid' => $teamid, 'userid' => $person->id, 'timecreated' => $now, 'usermodified' => 0,
            ]);
        }
        $DB->insert_record('local_moodesk_csat_rating', (object) [
            'ticketid' => $this->ticketid, 'rating' => 5, 'comment' => 'Great help', 'token' => 'tok',
            'token_expires' => $now + 100, 'submitted_at' => $now, 'timecreated' => $now, 'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->insert_record('local_moodesk_kb_feedback', (object) [
            'articleid' => 1, 'userid' => $this->user->id, 'helpful' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_moodesk_api_token', (object) [
            'userid' => $this->user->id, 'externaltokenid' => 0, 'name' => 'Integration', 'scopes' => 'read',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_moodesk_merge_audit', (object) [
            'sourceticketid' => $this->ticketid, 'targetticketid' => 0, 'actorid' => $this->user->id, 'mergedat' => $now,
            'reason' => 'Duplicate', 'source_snapshot' => '{}', 'migrated_items' => '{}', 'conflicts' => '{}',
        ]);
        foreach ([[$this->user, 'person@mail'], [$this->other, 'other@mail']] as [$person, $messageid]) {
            $DB->insert_record('local_moodesk_email_message', (object) [
                'ticketid' => null, 'replyid' => null, 'message_id' => $messageid, 'sender_email' => $person->email,
                'sender_name' => 'Name', 'raw_subject' => 'Subject', 'role' => 3, 'reject_reason' => 'token_invalid',
                'resolved_userid' => $person->id, 'timecreated' => $now,
            ]);
        }
    }
}
