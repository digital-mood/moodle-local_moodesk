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
 * Tests for moving email_ingestion_alert to local_moodeskpro (3.0.2).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\db;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/moodesk/db/upgradelib.php');
require_once($CFG->dirroot . '/local/moodesk/db/upgrade.php');
require_once($CFG->libdir . '/messagelib.php');
require_once($CFG->libdir . '/upgradelib.php');

/**
 * The copy of a provider's settings and preferences, and what the core's
 * provider sync for this plugin then does to them.
 *
 * The sync of local_moodeskpro, which creates the provider under its new
 * component, is pinned by the add-on's own test (it needs the add-on's
 * db/messages.php). These cases hold with or without the add-on installed.
 *
 * @covers ::local_moodesk_move_message_provider
 * @covers ::xmldb_local_moodesk_upgrade
 */
final class message_provider_move_test extends \advanced_testcase {
    /** The provider that moves. */
    private const NAME = 'email_ingestion_alert';

    /**
     * Every case writes settings and preferences.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The values come across exactly, and the core then deletes the old names.
     */
    public function test_values_are_copied_and_the_old_names_go(): void {
        global $DB;

        [$u1, $u2] = $this->arrange_301_state();

        $copied = local_moodesk_move_message_provider(self::NAME, 'local_moodeskpro');
        $this->assertGreaterThanOrEqual(4, $copied);

        message_update_providers('local_moodesk');

        $this->assertSame('1', get_config('message', 'email_provider_local_moodeskpro_email_ingestion_alert_locked'));
        $this->assertSame('0', get_config('message', 'popup_provider_local_moodeskpro_email_ingestion_alert_locked'));
        $this->assertSame('popup', get_config('message', 'message_provider_local_moodeskpro_email_ingestion_alert_enabled'));
        $this->assertSame('popup', $this->pref($u1, 'local_moodeskpro'));
        $this->assertSame('none', $this->pref($u2, 'local_moodeskpro'));

        // The core's sync of this plugin deleted the old provider and every old name.
        $this->assertFalse($DB->record_exists('message_providers', ['component' => 'local_moodesk', 'name' => self::NAME]));
        $this->assertFalse(get_config('message', 'email_provider_local_moodesk_email_ingestion_alert_locked'));
        $this->assertFalse(get_config('message', 'message_provider_local_moodesk_email_ingestion_alert_enabled'));
        $this->assertNull($this->pref($u1, 'local_moodesk'));
        $this->assertNull($this->pref($u2, 'local_moodesk'));

        // The base's other providers are untouched.
        foreach (['ticket_notification', 'operational_alert'] as $name) {
            $this->assertTrue($DB->record_exists('message_providers', ['component' => 'local_moodesk', 'name' => $name]));
        }
    }

    /**
     * A provider the administrator turned off in 3.0.1 stays off: the switch
     * is copied like any other setting.
     */
    public function test_a_provider_turned_off_stays_off(): void {
        $this->arrange_301_state();
        set_config('local_moodesk_email_ingestion_alert_disable', 1, 'message');

        local_moodesk_move_message_provider(self::NAME, 'local_moodeskpro');
        message_update_providers('local_moodesk');

        $this->assertSame('1', get_config('message', 'local_moodeskpro_email_ingestion_alert_disable'));
    }

    /**
     * Running it twice changes nothing the first run did.
     */
    public function test_it_is_idempotent(): void {
        global $DB;

        [$u1] = $this->arrange_301_state();

        local_moodesk_move_message_provider(self::NAME, 'local_moodeskpro');
        $this->assertSame(0, local_moodesk_move_message_provider(self::NAME, 'local_moodeskpro'));

        $this->assertSame(1, $DB->count_records('user_preferences', [
            'userid' => $u1,
            'name'   => 'message_provider_local_moodeskpro_email_ingestion_alert_enabled',
        ]));
        $this->assertSame('popup', $this->pref($u1, 'local_moodeskpro'));
    }

    /**
     * A value already under the new name is kept, not overwritten.
     */
    public function test_an_existing_new_name_is_not_overwritten(): void {
        [$u1] = $this->arrange_301_state();
        set_config('message_provider_local_moodeskpro_email_ingestion_alert_enabled', 'email', 'message');
        set_user_preference('message_provider_local_moodeskpro_email_ingestion_alert_enabled', 'email', $u1);

        local_moodesk_move_message_provider(self::NAME, 'local_moodeskpro');

        $this->assertSame('email', get_config('message', 'message_provider_local_moodeskpro_email_ingestion_alert_enabled'));
        $this->assertSame('email', $this->pref($u1, 'local_moodeskpro'));
    }

    /**
     * Names are matched exactly: in LIKE, `_` matches any character, and a
     * preference whose name only looks like the provider's is not copied.
     */
    public function test_names_are_matched_exactly(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        set_user_preference('message_provider_local_moodesk_email_ingestion_alertXenabled', 'popup', $user);

        local_moodesk_move_message_provider(self::NAME, 'local_moodeskpro');

        $this->assertFalse($DB->record_exists_select(
            'user_preferences',
            'userid = ? AND ' . $DB->sql_like('name', '?'),
            [$user->id, 'message_provider_local_moodeskpro%']
        ));
    }

    /**
     * The upgrade step copies only when the add-on is installed. On a
     * Starter-only site the provider is simply retired: nothing is written
     * under local_moodeskpro, and the step says nothing.
     */
    public function test_the_upgrade_step_on_a_starter_only_site_writes_nothing_for_the_add_on(): void {
        global $DB;

        [$u1] = $this->arrange_301_state();
        set_config('local_moodesk_email_ingestion_alert_disable', 1, 'message');
        $addonversion = get_config('local_moodeskpro', 'version');
        unset_config('version', 'local_moodeskpro');

        set_config('version', 2026110700, 'local_moodesk');
        xmldb_local_moodesk_upgrade(2026110700);
        message_update_providers('local_moodesk');

        $this->assertFalse($DB->record_exists_select(
            'user_preferences',
            $DB->sql_like('name', '?'),
            ['message_provider_local_moodeskpro%']
        ));
        $this->assertFalse(get_config('message', 'message_provider_local_moodeskpro_email_ingestion_alert_enabled'));
        $this->assertFalse(get_config('message', 'email_provider_local_moodeskpro_email_ingestion_alert_locked'));
        $this->assertFalse(get_config('message', 'local_moodeskpro_email_ingestion_alert_disable'));
        // The core leaves the old switch behind; the step does not.
        $this->assertFalse(get_config('message', 'local_moodesk_email_ingestion_alert_disable'));
        $this->assertNull($this->pref($u1, 'local_moodesk'));
        $this->assertFalse($DB->record_exists('message_providers', ['component' => 'local_moodesk', 'name' => self::NAME]));

        if ($addonversion !== false) {
            set_config('version', $addonversion, 'local_moodeskpro');
        }
    }

    /**
     * With the add-on installed, the upgrade step copies.
     */
    public function test_the_upgrade_step_with_the_add_on_copies(): void {
        [$u1] = $this->arrange_301_state();
        set_config('local_moodesk_email_ingestion_alert_disable', 1, 'message');
        $addonversion = get_config('local_moodeskpro', 'version');
        set_config('version', 2026110701, 'local_moodeskpro');

        set_config('version', 2026110700, 'local_moodesk');
        xmldb_local_moodesk_upgrade(2026110700);

        $this->assertSame('popup', $this->pref($u1, 'local_moodeskpro'));
        $this->assertSame('popup', get_config('message', 'message_provider_local_moodeskpro_email_ingestion_alert_enabled'));
        $this->assertSame('1', get_config('message', 'local_moodeskpro_email_ingestion_alert_disable'));
        $this->assertFalse(get_config('message', 'local_moodesk_email_ingestion_alert_disable'));

        if ($addonversion === false) {
            unset_config('version', 'local_moodeskpro');
        } else {
            set_config('version', $addonversion, 'local_moodeskpro');
        }
    }

    /**
     * The state a 3.0.1 site is in: the provider under local_moodesk with an
     * administrator's non-default settings and two users' own preferences,
     * and nothing under local_moodeskpro yet.
     *
     * @return int[] The two users' ids.
     */
    private function arrange_301_state(): array {
        global $DB;

        // Whatever the add-on's install wrote, if it is installed: 3.0.1 had none.
        $DB->delete_records('message_providers', ['component' => 'local_moodeskpro', 'name' => self::NAME]);
        foreach ($DB->get_fieldset_select('message_processors', 'name', '1 = 1') as $processor) {
            unset_config("{$processor}_provider_local_moodeskpro_email_ingestion_alert_locked", 'message');
        }
        unset_config('message_provider_local_moodeskpro_email_ingestion_alert_enabled', 'message');
        unset_config('local_moodeskpro_email_ingestion_alert_disable', 'message');

        $DB->insert_record('message_providers', (object) [
            'name'      => self::NAME,
            'component' => 'local_moodesk',
            'capability' => null,
        ]);
        // Email disabled and locked; popup enabled, not locked. Every processor
        // has its _locked, as the core wrote one for each when it installed the
        // provider.
        foreach ($DB->get_fieldset_select('message_processors', 'name', '1 = 1') as $processor) {
            $locked = (int) ($processor === 'email');
            set_config("{$processor}_provider_local_moodesk_email_ingestion_alert_locked", $locked, 'message');
        }
        set_config('message_provider_local_moodesk_email_ingestion_alert_enabled', 'popup', 'message');

        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        set_user_preference('message_provider_local_moodesk_email_ingestion_alert_enabled', 'popup', $u1);
        set_user_preference('message_provider_local_moodesk_email_ingestion_alert_enabled', 'none', $u2);

        return [(int) $u1->id, (int) $u2->id];
    }

    /**
     * A user's stored preference for the provider under a component, read
     * from the table rather than the per-request preference cache.
     *
     * @param int    $userid
     * @param string $component
     * @return string|null
     */
    private function pref(int $userid, string $component): ?string {
        global $DB;

        $value = $DB->get_field('user_preferences', 'value', [
            'userid' => $userid,
            'name'   => "message_provider_{$component}_email_ingestion_alert_enabled",
        ]);
        return $value === false ? null : $value;
    }
}
