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
 * Stand where a site upgrading to 3.0.0 stands.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\db;

/**
 * The Pro tables and columns a release before 3.0.0 left on a site.
 *
 * db/upgrade.php is history and still runs on them, while the schema of this
 * plugin no longer declares them. DDL outlives the test on MariaDB and MySQL, so
 * whoever installs them drops them in tearDown: anything left behind fails the
 * schema of every later test.
 */
trait legacy_pro_tables {
    /**
     * Install the tables and columns, as release 2.39.1 declares them.
     */
    private function install_legacy_pro_tables(): void {
        global $DB;

        $this->drop_legacy_pro_tables();
        $dbman = $DB->get_manager();
        $dbman->install_from_xmldb_file(__DIR__ . '/legacy_pro_tables.xml');

        foreach (self::legacy_columns() as $tablename => $fields) {
            $table = new \xmldb_table($tablename);
            foreach ($fields as $field) {
                $dbman->add_field($table, $field);
            }
        }
    }

    /**
     * Drop whichever of the tables and columns exist, with any index on them.
     */
    private function drop_legacy_pro_tables(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $dbman->delete_tables_from_xmldb_file(__DIR__ . '/legacy_pro_tables.xml');

        foreach (self::legacy_columns() as $tablename => $fields) {
            $table = new \xmldb_table($tablename);
            $names = array_map(static fn($field) => $field->getName(), $fields);
            foreach ($DB->get_indexes($tablename) as $index) {
                if (array_intersect($index['columns'], $names)) {
                    $dbman->drop_index($table, new \xmldb_index('legacy', XMLDB_INDEX_NOTUNIQUE, $index['columns']));
                }
            }
            foreach ($fields as $field) {
                if ($dbman->field_exists($table, $field)) {
                    $dbman->drop_field($table, $field);
                }
            }
        }
    }

    /**
     * The columns of the base's tables that left its schema.
     *
     * @return array<string, \xmldb_field[]> By table.
     */
    private static function legacy_columns(): array {
        $sla = [];
        foreach (['response', 'resolve'] as $kind) {
            foreach (['low', 'normal', 'high', 'urgent'] as $priority) {
                $sla[] = new \xmldb_field("sla_{$kind}_hours_{$priority}", XMLDB_TYPE_INTEGER, '4');
            }
        }

        return [
            'local_moodesk_department' => $sla,
            'local_moodesk_ticket' => [
                new \xmldb_field('teamid', XMLDB_TYPE_INTEGER, '10'),
                new \xmldb_field('mergedinto', XMLDB_TYPE_INTEGER, '10'),
                new \xmldb_field('merged_status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'active'),
                new \xmldb_field('merged_audit_id', XMLDB_TYPE_INTEGER, '10'),
                new \xmldb_field('tokenepoch', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0'),
            ],
        ];
    }
}
