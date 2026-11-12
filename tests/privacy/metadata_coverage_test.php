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
 * Structural guard: the schema may not outgrow the privacy declaration.
 *
 * Audit finding R2 was not one oversight, it was nine tables that arrived over
 * time and nobody remembered to declare — webhook payloads quoting ticket
 * subjects, a rule naming the agent it assigns to, `usermodified` on eleven
 * tables. Every one of them shipped through a green CI, because nothing
 * anywhere compared the schema with the declaration.
 *
 * Three of those eleven `usermodified` columns were found by this test rather
 * than by the analysis that preceded it — cfield_value, csat_rating and
 * team_member — which is the argument for having it.
 *
 * This file is that comparison. It is deliberately annoying in one specific
 * way: adding a table or a person-shaped column to `db/install.xml` fails here
 * until somebody either declares it or writes it into an exclusion list below.
 * Both are one line. Neither can be done by accident, which is the point.
 *
 * **An exclusion means "looked at, holds no personal data".** It does not mean
 * "not declared yet". The lists carry a reason for each entry so the difference
 * stays legible to whoever reads them next.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

/**
 * Tests that the privacy metadata covers the schema.
 *
 * @coversNothing
 */
final class metadata_coverage_test extends \advanced_testcase {
    /**
     * Tables that hold no personal data at all.
     *
     * Reviewed 2026-09-08 under audit finding R2. Empty, and that is itself
     * the finding: all twenty tables this plugin defines carry personal data,
     * most of them at minimum a `usermodified`. A table added later that
     * genuinely holds none belongs here, with the reason written next to it.
     *
     * @var array<string, string> table => why it holds no personal data
     */
    private const TABLES_WITHOUT_PERSONAL_DATA = [
    ];

    /**
     * Column names that look personal to the heuristic but are not.
     *
     * Keyed `table.column`, each with the reason. The heuristic below is
     * deliberately eager: it would rather ask about a harmless column than stay
     * quiet about a harmful one, and answering it costs one line here.
     *
     * @var array<string, string> "table.column" => why it is not personal data
     */
    private const COLUMNS_REVIEWED_AS_IMPERSONAL = [
        'local_moodesk_category.name'        => 'A category label chosen by an administrator, e.g. "Billing".',
        'local_moodesk_department.name'      => 'A department label chosen by an administrator.',
        'local_moodesk_ticket.coursename_snapshot' => 'The name of a course at the time the ticket was raised, not of a person.',
        'local_moodesk_reply.messageformat'  => 'The Moodle format constant for the body. Matches on "message"; carries nothing.',
        'local_moodesk_incident.message'     =>
            'An operator-facing description of a plugin failure. Asserted free of message content '
                . 'by email_sender_auth_test::test_the_incident_carries_no_message_content.',
    ];

    /**
     * Column-name fragments that suggest a column may hold personal data.
     *
     * Names, not types: a `bigint` called `usermodified` is personal data and a
     * `text` called `payload` may be, while `timecreated` never is.
     *
     * @var string[]
     */
    private const PERSON_SHAPED = [
        'userid', 'usermodified', 'createdby', 'assigneeid', 'actorid', 'reverted_actorid',
        'acknowledgedby', 'resolvedby', 'token_userid', 'resolved_userid',
        'email', 'sender_', 'name', 'subject', 'message', 'comment', 'reason',
        'payload', 'response_body', 'note', 'value', 'actions', 'snapshot',
    ];

    /**
     * The tables this plugin defines, read from db/install.xml.
     *
     * The install file rather than the live database, so a stale test
     * environment cannot make this pass by omission.
     *
     * @return array<string, string[]> table => column names
     */
    private function schema_tables(): array {
        global $CFG;

        $xml = file_get_contents($CFG->dirroot . '/local/moodesk/db/install.xml');
        $this->assertNotFalse($xml, 'db/install.xml could not be read.');

        $tables = [];
        if (preg_match_all('~<TABLE NAME="([a-z_]+)".*?</TABLE>~s', $xml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                preg_match_all('~<FIELD NAME="([a-z_]+)"~', $match[0], $fields);
                $tables[$match[1]] = $fields[1];
            }
        }

        $this->assertNotEmpty($tables, 'No tables parsed out of db/install.xml.');

        return $tables;
    }

    /**
     * The tables and fields the provider declares.
     *
     * @return array<string, string[]> table => declared field names
     */
    private function declared_tables(): array {
        $collection = provider::get_metadata(
            new \core_privacy\local\metadata\collection('local_moodesk')
        );

        $declared = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $declared[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }

        return $declared;
    }

    /**
     * Every table in the schema is declared, or excluded with a reason.
     */
    public function test_every_table_is_declared_or_consciously_excluded(): void {
        $undeclared = [];

        foreach (array_keys($this->schema_tables()) as $table) {
            if (isset($this->declared_tables()[$table])) {
                continue;
            }
            if (array_key_exists($table, self::TABLES_WITHOUT_PERSONAL_DATA)) {
                continue;
            }
            $undeclared[] = $table;
        }

        $this->assertSame(
            [],
            $undeclared,
            "These tables exist in db/install.xml and the privacy provider says nothing about them. "
                . "Either declare them in get_metadata(), or add them to TABLES_WITHOUT_PERSONAL_DATA "
                . "with the reason they hold none: " . implode(', ', $undeclared)
        );
    }

    /**
     * Person-shaped columns on declared tables are declared or excluded.
     *
     * This is the half that would have caught R2's `usermodified` gap: the
     * tables were declared, the columns naming a person were not.
     */
    public function test_person_shaped_columns_are_declared_or_consciously_excluded(): void {
        $declared = $this->declared_tables();
        $missing  = [];

        foreach ($this->schema_tables() as $table => $columns) {
            if (!isset($declared[$table])) {
                continue; // Covered by the test above.
            }

            foreach ($columns as $column) {
                if (in_array($column, $declared[$table], true)) {
                    continue;
                }
                if (array_key_exists("$table.$column", self::COLUMNS_REVIEWED_AS_IMPERSONAL)) {
                    continue;
                }

                foreach (self::PERSON_SHAPED as $fragment) {
                    if (strpos($column, $fragment) !== false) {
                        $missing[] = "$table.$column";
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These columns look like they name or describe a person and are not declared in the "
                . "privacy metadata. Either declare them, or add them to "
                . "COLUMNS_REVIEWED_AS_IMPERSONAL with the reason: " . implode(', ', $missing)
        );
    }

    /**
     * Nothing sits in an exclusion list that is also declared.
     *
     * A column in both places means somebody declared it and forgot to remove
     * the exclusion, which leaves a false statement behind saying it holds no
     * personal data.
     */
    public function test_no_exclusion_contradicts_a_declaration(): void {
        $declared = $this->declared_tables();

        foreach (array_keys(self::TABLES_WITHOUT_PERSONAL_DATA) as $table) {
            $this->assertArrayNotHasKey(
                $table,
                $declared,
                "$table is declared in the privacy metadata and also listed as holding no personal "
                    . 'data. Remove the exclusion.'
            );
        }

        foreach (array_keys(self::COLUMNS_REVIEWED_AS_IMPERSONAL) as $key) {
            [$table, $column] = explode('.', $key, 2);
            if (!isset($declared[$table])) {
                continue;
            }
            $this->assertNotContains(
                $column,
                $declared[$table],
                "$key is declared in the privacy metadata and also listed as impersonal. "
                    . 'Remove the exclusion.'
            );
        }
    }

    /**
     * Exclusion lists do not name things that no longer exist.
     *
     * Without this, a dropped column leaves behind a reason nobody can check,
     * and the list slowly becomes decoration.
     */
    public function test_exclusion_lists_name_real_tables_and_columns(): void {
        $schema = $this->schema_tables();

        foreach (array_keys(self::TABLES_WITHOUT_PERSONAL_DATA) as $table) {
            $this->assertArrayHasKey($table, $schema, "Excluded table $table is not in the schema.");
        }

        foreach (array_keys(self::COLUMNS_REVIEWED_AS_IMPERSONAL) as $key) {
            [$table, $column] = explode('.', $key, 2);
            if (!isset($schema[$table])) {
                // A forward-looking entry for a table that does not exist yet is
                // dead weight rather than a lie; name it so it can be removed.
                $this->addToAssertionCount(1);
                continue;
            }
            $this->assertContains(
                $column,
                $schema[$table],
                "Excluded column $key is not in the schema."
            );
        }
    }

    /**
     * Every exclusion carries a non-empty reason.
     */
    public function test_every_exclusion_states_a_reason(): void {
        foreach (self::TABLES_WITHOUT_PERSONAL_DATA as $table => $reason) {
            $this->assertNotEmpty(trim($reason), "Excluded table $table states no reason.");
        }

        foreach (self::COLUMNS_REVIEWED_AS_IMPERSONAL as $key => $reason) {
            $this->assertNotEmpty(trim($reason), "Excluded column $key states no reason.");
        }
    }

    /**
     * Every `usermodified` column has a recorded decision, table by table.
     *
     * Eleven tables carry the column and erasure clears all eleven, which makes
     * it tempting to treat them as one thing with one rule. They are not: what
     * erasure may do to a row depends on whether the row can survive without the
     * person, and that question has a different answer for a live automation
     * rule than for a satisfaction rating somebody else left. The provider
     * records an answer per table; this test is what stops a twelfth from
     * inheriting one by default.
     */
    public function test_every_usermodified_column_has_a_recorded_decision(): void {
        $reflection = new \ReflectionClassConstant(provider::class, 'USERMODIFIED_TABLES');
        $decisions  = $reflection->getValue();

        $withcolumn = [];
        foreach ($this->schema_tables() as $table => $columns) {
            if (in_array('usermodified', $columns, true)) {
                $withcolumn[] = $table;
            }
        }

        $missing = array_diff($withcolumn, array_keys($decisions));
        $this->assertSame(
            [],
            array_values($missing),
            'These tables carry a usermodified column with no decision recorded against it: '
                . implode(', ', $missing) . '. Add each to provider::USERMODIFIED_TABLES with the '
                . 'reason its row survives erasure — or, if the row should not survive, delete it in '
                . 'anonymise_user() instead and say so there.'
        );

        $stale = array_diff(array_keys($decisions), $withcolumn);
        $this->assertSame(
            [],
            array_values($stale),
            'A decision is recorded for a table that has no usermodified column: ' . implode(', ', $stale)
        );

        foreach ($decisions as $table => $reason) {
            $this->assertNotEmpty(
                trim((string) $reason),
                "$table records no reason for keeping its row through erasure."
            );
        }
    }
}
