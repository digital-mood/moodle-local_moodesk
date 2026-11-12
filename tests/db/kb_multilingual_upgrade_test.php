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
require_once($CFG->dirroot . '/local/moodesk/db/upgradelib.php');

/**
 * The KB-06 upgrade step: unique (groupid, lang), defensive backfill, duplicate guard.
 *
 * Runs against the installed schema, which already carries the unique index
 * from install.xml — so the index assertions here are "still there after the
 * step" and "the step is idempotent"; the live upgrade path from a pre-KB-06
 * schema is exercised by dropping the index first and by the PostgreSQL /
 * MariaDB lab before the gate. The DDL is done through $dbman inside the
 * test: on PostgreSQL the test transaction rolls it back, on MariaDB it does
 * not, so tearDown restores the index either way.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    ::local_moodesk_upgrade_kb_unique_group_lang
 */
final class kb_multilingual_upgrade_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Put the schema back the way install.xml has it, whatever a test did to it.
     */
    protected function tearDown(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_moodesk_kb_article');
        // The fixture rows go first: the duplicate-pair test leaves rows the
        // unique index would refuse, and on MariaDB the reset that removes
        // them runs after this method, not before.
        $DB->delete_records('local_moodesk_kb_article');
        $plain = new \xmldb_index('ix_groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid']);
        if ($dbman->index_exists($table, $plain)) {
            $dbman->drop_index($table, $plain);
        }
        $unique = new \xmldb_index('ux_groupid_lang', XMLDB_INDEX_UNIQUE, ['groupid', 'lang']);
        if (!$dbman->index_exists($table, $unique)) {
            $dbman->add_index($table, $unique);
        }
        parent::tearDown();
    }

    /**
     * A raw row, bypassing the repository so groupid can be left at 0.
     *
     * @param int    $groupid
     * @param string $lang
     * @param string $title
     * @return int
     */
    private function raw_row(int $groupid, string $lang, string $title = 'Row'): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('local_moodesk_kb_article', (object) [
            'groupid'      => $groupid,
            'lang'         => $lang,
            'categoryid'   => null,
            'title'        => $title,
            'body'         => '<p>x</p>',
            'bodyformat'   => FORMAT_HTML,
            'searchtext'   => 'x',
            'status'       => 0,
            'audience'     => 0,
            'createdby'    => 2,
            'usermodified' => 2,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Put the schema in its pre-KB-06 shape: plain groupid index, no unique one.
     */
    private function downgrade_schema(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_moodesk_kb_article');
        $unique = new \xmldb_index('ux_groupid_lang', XMLDB_INDEX_UNIQUE, ['groupid', 'lang']);
        if ($dbman->index_exists($table, $unique)) {
            $dbman->drop_index($table, $unique);
        }
        $plain = new \xmldb_index('ix_groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid']);
        if (!$dbman->index_exists($table, $plain)) {
            $dbman->add_index($table, $plain);
        }
    }

    public function test_step_backfills_groupid_adds_the_unique_index_and_drops_the_plain_one(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_moodesk_kb_article');
        $this->downgrade_schema();

        $stray  = $this->raw_row(0, 'en', 'Stray');
        $normal = $this->raw_row(0, 'en', 'Normal');
        $DB->set_field('local_moodesk_kb_article', 'groupid', $normal, ['id' => $normal]);
        // Two rows of one group in two languages: legal, and must survive.
        $es = $this->raw_row($normal, 'es', 'Normal ES');

        local_moodesk_upgrade_kb_unique_group_lang($DB);

        $this->assertSame($stray, (int) $DB->get_field('local_moodesk_kb_article', 'groupid', ['id' => $stray]));
        $this->assertSame($normal, (int) $DB->get_field('local_moodesk_kb_article', 'groupid', ['id' => $normal]));
        $this->assertSame($normal, (int) $DB->get_field('local_moodesk_kb_article', 'groupid', ['id' => $es]));
        $this->assertSame('es', $DB->get_field('local_moodesk_kb_article', 'lang', ['id' => $es]));

        $unique = new \xmldb_index('ux_groupid_lang', XMLDB_INDEX_UNIQUE, ['groupid', 'lang']);
        $plain  = new \xmldb_index('ix_groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid']);
        $this->assertTrue($dbman->index_exists($table, $unique));
        $this->assertFalse($dbman->index_exists($table, $plain));

        // The index holds the invariant: a second ES row in the group is refused by the database.
        $this->expectException(\dml_write_exception::class);
        $this->raw_row($normal, 'es', 'Normal ES again');
    }

    public function test_step_is_idempotent_on_an_installed_schema(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_moodesk_kb_article');
        $unique = new \xmldb_index('ux_groupid_lang', XMLDB_INDEX_UNIQUE, ['groupid', 'lang']);
        $this->assertTrue($dbman->index_exists($table, $unique), 'install.xml carries the unique index');

        $id = $this->raw_row(0, 'en');
        $DB->set_field('local_moodesk_kb_article', 'groupid', $id, ['id' => $id]);

        local_moodesk_upgrade_kb_unique_group_lang($DB);
        local_moodesk_upgrade_kb_unique_group_lang($DB);

        $this->assertTrue($dbman->index_exists($table, $unique));
        $this->assertFalse($dbman->index_exists($table, new \xmldb_index('ix_groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid'])));
        $this->assertSame($id, (int) $DB->get_field('local_moodesk_kb_article', 'groupid', ['id' => $id]));
    }

    public function test_step_stops_on_a_duplicated_pair_and_names_the_rows(): void {
        global $DB;
        $this->downgrade_schema();

        $a = $this->raw_row(0, 'en', 'A');
        $DB->set_field('local_moodesk_kb_article', 'groupid', $a, ['id' => $a]);
        $b = $this->raw_row($a, 'en', 'B — same group and language as A');
        $c = $this->raw_row($a, 'es', 'C — fine');

        try {
            local_moodesk_upgrade_kb_unique_group_lang($DB);
            $this->fail('A duplicated (groupid, lang) pair must stop the upgrade.');
        } catch (\moodle_exception $e) {
            $this->assertSame('kb_upgrade_duplicate_translations', $e->errorcode);
            $this->assertStringContainsString("groupid $a / lang en: article ids $a, $b", $e->a);
            $this->assertStringNotContainsString("lang es", (string) $e->a);
        }

        // Nothing was decided for the administrator: both rows are still there, untouched.
        $this->assertSame(3, $DB->count_records('local_moodesk_kb_article', ['groupid' => $a]));
        $this->assertFalse($DB->get_manager()->index_exists(
            new \xmldb_table('local_moodesk_kb_article'),
            new \xmldb_index('ux_groupid_lang', XMLDB_INDEX_UNIQUE, ['groupid', 'lang'])
        ));
    }
}
