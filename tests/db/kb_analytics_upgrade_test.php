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

namespace local_moodesk\db;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/moodesk/db/upgrade.php');
require_once($CFG->dirroot . '/lib/upgradelib.php');
require_once(__DIR__ . '/../fixtures/legacy_pro_tables.php');

/**
 * The KB-07 upgrade step: the two analytics tables exist after it, with
 * their keys and indexes, whether or not they existed before.
 *
 * These tables belong to local_moodeskpro since 3.0.0 under other names; the
 * step is history that a site below 2.29.0 still runs. The tests stand on the
 * legacy tables, so the live path from a v2.28.0 schema is exercised by
 * dropping them first and re-running the block from the KB-06 savepoint.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    ::xmldb_local_moodesk_upgrade
 */
final class kb_analytics_upgrade_test extends \advanced_testcase {
    use legacy_pro_tables;

    /** The savepoint the KB-07 block upgrades from. */
    private const BEFORE_KB07 = 2026091500;

    /** The savepoint the KB-07 block records. */
    private const KB07 = 2026091600;

    /** The two tables the block creates. */
    private const TABLES = ['local_moodesk_kb_feedback', 'local_moodesk_kb_event'];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->install_legacy_pro_tables();
    }

    /**
     * Leave no legacy table behind.
     */
    protected function tearDown(): void {
        $this->drop_legacy_pro_tables();
        parent::tearDown();
    }

    /**
     * Whether a table exists right now.
     *
     * @param string $name
     * @return bool
     */
    private function table_exists(string $name): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table($name));
    }

    /**
     * Drop both tables, as a v2.28.0 site has never had them.
     */
    private function drop_both(): void {
        global $DB;
        $dbman = $DB->get_manager();
        foreach (self::TABLES as $name) {
            $table = new \xmldb_table($name);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
    }

    /**
     * From a v2.28.0 schema the block creates both tables and records the savepoint.
     */
    public function test_upgrade_from_2280_creates_both_tables(): void {
        global $DB;

        $this->drop_both();
        $this->assertFalse($this->table_exists('local_moodesk_kb_feedback'));
        $this->assertFalse($this->table_exists('local_moodesk_kb_event'));

        set_config('version', self::BEFORE_KB07, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_KB07));

        $this->assertTrue($this->table_exists('local_moodesk_kb_feedback'));
        $this->assertTrue($this->table_exists('local_moodesk_kb_event'));
        $this->assertGreaterThanOrEqual(self::KB07, (int) get_config('local_moodesk', 'version'));

        // The structures the queries lean on, present after the block.
        $dbman    = $DB->get_manager();
        $feedback = new \xmldb_table('local_moodesk_kb_feedback');
        $this->assertTrue($dbman->index_exists(
            $feedback,
            new \xmldb_index('ux_article_user', XMLDB_INDEX_UNIQUE, ['articleid', 'userid'])
        ));
        $this->assertTrue($dbman->index_exists(
            $feedback,
            new \xmldb_index('ix_userid', XMLDB_INDEX_NOTUNIQUE, ['userid'])
        ));
        $event = new \xmldb_table('local_moodesk_kb_event');
        $this->assertTrue($dbman->index_exists(
            $event,
            new \xmldb_index('ix_type_time_article', XMLDB_INDEX_NOTUNIQUE, ['type', 'timecreated', 'articleid'])
        ));
        $this->assertTrue($dbman->index_exists(
            $event,
            new \xmldb_index('ix_article_type_time', XMLDB_INDEX_NOTUNIQUE, ['articleid', 'type', 'timecreated'])
        ));
        $this->assertTrue($dbman->index_exists(
            $event,
            new \xmldb_index('ix_type_result_time', XMLDB_INDEX_NOTUNIQUE, ['type', 'resultcount', 'timecreated'])
        ));
        $this->assertTrue($dbman->field_exists($event, new \xmldb_field('querynorm')));
        $this->assertTrue($dbman->field_exists($event, new \xmldb_field('resultcount')));
    }

    /**
     * Running the block against a schema that already has the tables changes nothing.
     */
    public function test_upgrade_is_idempotent_when_tables_exist(): void {
        global $DB;

        $this->assertTrue($this->table_exists('local_moodesk_kb_feedback'));
        $this->assertTrue($this->table_exists('local_moodesk_kb_event'));

        // A row in each proves the tables were neither dropped nor recreated.
        $article = $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_kb_article();
        $feedbackid = $DB->insert_record('local_moodesk_kb_feedback', (object) [
            'articleid' => $article->id, 'userid' => 2, 'helpful' => 1,
            'timecreated' => 100, 'timemodified' => 100,
        ]);
        $eventid = $DB->insert_record('local_moodesk_kb_event', (object) [
            'type' => 1, 'articleid' => $article->id, 'surface' => 1, 'actorrole' => 0, 'timecreated' => 100,
        ]);

        set_config('version', self::BEFORE_KB07, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_KB07));

        $this->assertTrue($DB->record_exists('local_moodesk_kb_feedback', ['id' => $feedbackid]));
        $this->assertTrue($DB->record_exists('local_moodesk_kb_event', ['id' => $eventid]));
    }
}
