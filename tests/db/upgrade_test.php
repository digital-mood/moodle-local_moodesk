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
 * Regression tests for the plugin upgrade routine.
 *
 * The Moodle test bootstrap installs the plugin via install.xml + install.php,
 * which leaves `mdl_config_plugins.local_moodesk.version` at the latest value
 * declared in version.php. To exercise the upgrade.php blocks we therefore
 * have to "rewind" the recorded plugin version with set_config() before
 * invoking the function, otherwise upgrade_plugin_savepoint() raises a
 * downgrade_exception inside the very first block.
 *
 * Each block in db/upgrade.php is documented as idempotent. These tests assert:
 *
 *   1. Running with an oldversion above every savepoint is a clean no-op.
 *   2. Walking every block from an oldversion below the earliest savepoint
 *      against an already-installed schema short-circuits cleanly inside each
 *      table_exists / field_exists guard and advances the savepoint marker
 *      to the latest version.
 *   3. Dropping a structure introduced by a specific savepoint and re-running
 *      from the previous savepoint recreates that structure.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\db;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/moodesk/db/upgrade.php');
require_once($CFG->dirroot . '/lib/upgradelib.php');
require_once(__DIR__ . '/../fixtures/legacy_pro_tables.php');

/**
 * Tests for upgrade.
 *
 * @covers ::xmldb_local_moodesk_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    use legacy_pro_tables;

    /** Latest savepoint declared in db/upgrade.php. */
    private const LATEST_SAVEPOINT = 2026081400;

    /** Savepoint immediately preceding the table-prefix rename block. */
    private const BEFORE_RENAME = 2026080700;

    /** Savepoint immediately preceding the ticket-merge F0 block. */
    private const BEFORE_MERGE_F0 = 2026081000;

    /** Savepoint immediately preceding the SLA resolution-pause block. */
    private const BEFORE_SLA_PAUSE = 2026081200;

    /** Savepoint immediately preceding the licence-host block. */
    private const BEFORE_CRL_HOST = 2026091603;

    /** Last version before the IMAP checkbox became a three-way transport. */
    private const BEFORE_IMAP_TRANSPORT = 2026100100;

    /** Default revocation-list URL up to 2.29.x — the host never resolved. */
    private const OLD_CRL_URL = 'https://licenses.digitalmood.io/crl/revocations.json';

    /** Default revocation-list URL from 2.30.0; retired by the 2026-10 key rotation. */
    private const NEW_CRL_URL = 'https://licenses.moodesk.io/crl/revocations.json';

    /** Last version before the licence key rotation. */
    private const BEFORE_KEY_ROTATION = 2026100103;

    /** Version of 2.38.0, the last release whose install created no reply-token key. */
    private const BEFORE_REPLY_TOKEN_KEY = 2026100600;

    /** Ticket status: waiting on the requester. Mirrors ticket::STATUS_PENDING. */
    private const STATUS_PENDING = 2;

    /** Ticket status: open. Mirrors ticket::STATUS_OPEN. */
    private const STATUS_OPEN = 1;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->install_legacy_pro_tables();
    }

    /**
     * Leave no legacy table behind.
     *
     * resetAfterTest rolls back rows, not DDL, so a rename test that fails between
     * moving the table to its legacy name and the upgrade moving it back would
     * leave it for the rest of the suite.
     */
    protected function tearDown(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $legacy = new \xmldb_table('local_mhd_csat_rating');
        if ($dbman->table_exists($legacy)) {
            $dbman->drop_table($legacy);
        }
        $this->drop_legacy_pro_tables();

        parent::tearDown();
    }

    public function test_the_legacy_fixture_leaves_the_schema_of_the_base_clean(): void {
        global $DB;

        $this->drop_legacy_pro_tables();

        $this->assertArrayNotHasKey('merged_status', $DB->get_columns('local_moodesk_ticket', false));
        $this->assertArrayNotHasKey('teamid', $DB->get_columns('local_moodesk_ticket', false));
        $this->assertArrayNotHasKey('sla_resolve_hours_low', $DB->get_columns('local_moodesk_department', false));
    }

    public function test_upgrade_is_noop_when_already_at_latest_version(): void {
        // Sentinel oldversion above every if-block. Function must short-circuit
        // through all of them and return true without touching the schema.
        $sentinel = 2099010100;
        $this->assertTrue(xmldb_local_moodesk_upgrade($sentinel));
    }

    public function test_upgrade_walks_every_block_idempotently_against_installed_schema(): void {
        global $DB;

        // Pretend we are upgrading from a state predating every savepoint.
        set_config('version', 2026040701, 'local_moodesk');

        $dbman = $DB->get_manager();
        $this->assertTrue($dbman->table_exists('local_moodesk_department'));
        $this->assertTrue($dbman->table_exists('local_moodesk_team'));

        $this->assertTrue(xmldb_local_moodesk_upgrade(2026040701));

        // Schema must remain intact. Every historical block is gated on the
        // legacy local_mhd_ schema still being present, which it is not here —
        // this database was installed from install.xml, which already carries
        // the renamed tables. So they all short-circuit.
        $this->assertTrue($dbman->table_exists('local_moodesk_department'));
        $this->assertTrue($dbman->table_exists('local_moodesk_team'));
        $this->assertTrue($dbman->table_exists('local_moodesk_team_member'));
        $this->assertTrue($dbman->table_exists('local_moodesk_csat_rating'));
        $this->assertTrue($dbman->table_exists('local_moodesk_cfield'));
        $this->assertTrue($dbman->table_exists('local_moodesk_cfield_value'));
        $this->assertTrue($dbman->table_exists('local_moodesk_rule'));
        $this->assertTrue($dbman->table_exists('local_moodesk_rule_log'));
        $this->assertTrue($dbman->table_exists('local_moodesk_email_message'));
        $this->assertTrue($dbman->table_exists('local_moodesk_webhook'));
        $this->assertTrue($dbman->table_exists('local_moodesk_webhook_log'));

        // The latest savepoint marker must have been recorded.
        $this->assertGreaterThanOrEqual(
            self::LATEST_SAVEPOINT,
            (int) get_config('local_moodesk', 'version')
        );
    }

    public function test_rename_moves_a_legacy_table_to_the_component_prefix(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $legacy = new \xmldb_table('local_mhd_csat_rating');
        $renamed = new \xmldb_table('local_moodesk_csat_rating');

        // Put the table back under its old name, which is exactly the state a
        // site upgrading from before 2026081000 is in.
        $dbman->rename_table($renamed, 'local_mhd_csat_rating');
        $this->assertTrue($dbman->table_exists($legacy));

        $now = time();
        $DB->insert_record('local_mhd_csat_rating', (object) [
            'ticketid'      => 7,
            'rating'        => 5,
            'token'         => 'rename-probe',
            'token_expires' => $now + DAYSECS,
            'timecreated'   => $now,
            'timemodified'  => $now,
            'usermodified'  => 2,
        ]);

        set_config('version', self::BEFORE_RENAME, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_RENAME));

        $this->assertTrue($dbman->table_exists($renamed), 'legacy table should have been renamed');
        $this->assertFalse($dbman->table_exists($legacy), 'legacy table should no longer exist');

        // The rename must carry the rows across, not recreate an empty table.
        $this->assertSame(1, $DB->count_records('local_moodesk_csat_rating', ['ticketid' => 7]));
    }

    public function test_rename_is_idempotent_when_already_renamed(): void {
        global $DB;

        $dbman = $DB->get_manager();

        // The installed schema is already renamed, so the step has nothing to
        // move and must not touch what is there.
        $before = $DB->count_records('local_moodesk_csat_rating');

        set_config('version', self::BEFORE_RENAME, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_RENAME));

        $this->assertTrue($dbman->table_exists('local_moodesk_csat_rating'));
        $this->assertFalse($dbman->table_exists('local_mhd_csat_rating'));
        $this->assertSame($before, $DB->count_records('local_moodesk_csat_rating'));
    }

    /**
     * Insert a ticket straight into the table, bypassing the service layer.
     *
     * The backfill reads raw columns, so the fixtures have to be raw too:
     * ticket_service would refuse to write the legacy mergedinto value these
     * tests depend on.
     *
     * @param int|null $mergedinto Legacy merge pointer, or null for a plain ticket.
     * @return int The new ticket id.
     */
    private function insert_legacy_ticket(?int $mergedinto = null): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('local_moodesk_ticket', (object) [
            'userid'       => 3,
            'createdby'    => 3,
            'subject'      => 'Backfill fixture',
            'status'       => 1,
            'priority'     => 1,
            'source'       => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
            'sla_breached' => 0,
            'mergedinto'   => $mergedinto,
            'usermodified' => 3,
        ]);
    }

    public function test_merge_backfill_marks_legacy_sources_and_their_targets(): void {
        global $DB;

        $target = $this->insert_legacy_ticket();
        $source = $this->insert_legacy_ticket($target);
        $untouched = $this->insert_legacy_ticket();

        // Both rows start at the column default, which is the state a site
        // sitting on 2026081000 would be in the moment the columns are added.
        $DB->set_field('local_moodesk_ticket', 'merged_status', 'active', []);

        set_config('version', self::BEFORE_MERGE_F0, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_MERGE_F0));

        $this->assertSame(
            'merged_into',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $source]),
            'a ticket carrying mergedinto was merged away'
        );
        $this->assertSame(
            'merged_target',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $target]),
            'the ticket it pointed at received a source'
        );
        $this->assertSame(
            'active',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $untouched]),
            'a ticket unrelated to any merge must be left alone'
        );

        // The legacy column is deliberately preserved for one compat release.
        $this->assertSame(
            (string) $target,
            (string) $DB->get_field('local_moodesk_ticket', 'mergedinto', ['id' => $source])
        );
    }

    public function test_merge_backfill_keeps_a_chained_ticket_read_only(): void {
        global $DB;

        // Legacy data could contain A -> B -> C, which the feature now forbids.
        // B is both a source and a target; it must come out merged_into, the
        // read-only state, not merged_target. This is what the 'active' filter
        // on the second UPDATE is there for.
        $c = $this->insert_legacy_ticket();
        $b = $this->insert_legacy_ticket($c);
        $a = $this->insert_legacy_ticket($b);

        $DB->set_field('local_moodesk_ticket', 'merged_status', 'active', []);

        set_config('version', self::BEFORE_MERGE_F0, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_MERGE_F0));

        $this->assertSame(
            'merged_into',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $a])
        );
        $this->assertSame(
            'merged_into',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $b]),
            'a ticket that is both source and target stays frozen'
        );
        $this->assertSame(
            'merged_target',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $c])
        );
    }

    public function test_merge_f0_step_is_idempotent(): void {
        global $DB;

        $target = $this->insert_legacy_ticket();
        $source = $this->insert_legacy_ticket($target);

        $DB->set_field('local_moodesk_ticket', 'merged_status', 'active', []);

        set_config('version', self::BEFORE_MERGE_F0, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_MERGE_F0));

        $auditcount = $DB->count_records('local_moodesk_merge_audit');

        // Re-running must not flip states a second time nor duplicate the table.
        set_config('version', self::BEFORE_MERGE_F0, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_MERGE_F0));

        $this->assertSame(
            'merged_into',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $source])
        );
        $this->assertSame(
            'merged_target',
            $DB->get_field('local_moodesk_ticket', 'merged_status', ['id' => $target])
        );
        $this->assertSame($auditcount, $DB->count_records('local_moodesk_merge_audit'));
    }

    /*
     * -------------------------------------------------------------------------
     * SLA resolution pause — savepoint 2026081400
     * -------------------------------------------------------------------------
     */

    /**
     * Insert a ticket straight into the table, bypassing the service layer.
     *
     * @param array $overrides Column values to override on the fixture.
     * @return int The new ticket id.
     */
    private function insert_sla_ticket(array $overrides = []): int {
        global $DB;

        $now = time();

        $record = (array) [
            'userid'             => 3,
            'createdby'          => 3,
            'subject'            => 'SLA pause fixture',
            'status'             => self::STATUS_OPEN,
            'priority'           => 1,
            'source'             => 0,
            'timecreated'        => $now - (10 * DAYSECS),
            'timemodified'       => $now,
            'sla_breached'       => 0,
            'sla_paused_seconds' => 0,
            'sla_paused_since'   => null,
            'usermodified'       => 3,
        ];

        return (int) $DB->insert_record('local_moodesk_ticket', (object) ($overrides + $record));
    }

    /**
     * Write one status_changed row the way ticket_service::write_history does.
     *
     * @param int $ticketid
     * @param int $newstatus
     * @param int $at        Timestamp of the transition.
     */
    private function insert_status_history(int $ticketid, int $newstatus, int $at): void {
        global $DB;

        $DB->insert_record('local_moodesk_ticket_history', (object) [
            'ticketid'    => $ticketid,
            'userid'      => 3,
            'action'      => 'status_changed',
            'oldvalue'    => json_encode(['status' => self::STATUS_OPEN]),
            'newvalue'    => json_encode(['status' => $newstatus]),
            'timecreated' => $at,
        ]);
    }

    /**
     * Rewind the recorded version and run the SLA pause block.
     */
    private function run_sla_pause_upgrade(): void {
        set_config('version', self::BEFORE_SLA_PAUSE, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_SLA_PAUSE));
    }

    public function test_sla_pause_columns_are_installed(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_moodesk_ticket');

        $this->assertTrue($dbman->field_exists($table, new \xmldb_field('sla_paused_seconds')));
        $this->assertTrue($dbman->field_exists($table, new \xmldb_field('sla_paused_since')));
    }

    public function test_sla_pause_backfill_sums_closed_intervals_from_history(): void {
        global $DB;

        $now      = time();
        $ticketid = $this->insert_sla_ticket(['status' => self::STATUS_OPEN]);

        // Two separate stays in pending: 2 days, then 1 day. Both closed.
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (9 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - (7 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (5 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - (4 * DAYSECS));

        $this->run_sla_pause_upgrade();

        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid]);

        $this->assertSame(3 * DAYSECS, (int) $ticket->sla_paused_seconds);
        $this->assertNull($ticket->sla_paused_since);
    }

    public function test_sla_pause_backfill_leaves_the_open_interval_on_a_pending_ticket(): void {
        global $DB;

        $now      = time();
        $enteredpending = $now - (2 * DAYSECS);
        $ticketid = $this->insert_sla_ticket([
            'status'       => self::STATUS_PENDING,
            'timemodified' => $enteredpending,
        ]);

        // One closed interval of 1 day, then the stay it is still in.
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (8 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - (7 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $enteredpending);

        $this->run_sla_pause_upgrade();

        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid]);

        // The open stay stays out of the total — it lives in sla_paused_since.
        $this->assertSame(DAYSECS, (int) $ticket->sla_paused_seconds);
        $this->assertSame($enteredpending, (int) $ticket->sla_paused_since);
    }

    public function test_sla_pause_backfill_ignores_a_repeated_pending_transition(): void {
        global $DB;

        $now      = time();
        $ticketid = $this->insert_sla_ticket();

        // Two consecutive entries into pending. The second must not restart the
        // interval, or the time between them would be dropped from the total.
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (6 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (5 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - (3 * DAYSECS));

        $this->run_sla_pause_upgrade();

        $this->assertSame(
            3 * DAYSECS,
            (int) $DB->get_field('local_moodesk_ticket', 'sla_paused_seconds', ['id' => $ticketid])
        );
    }

    public function test_sla_pause_backfill_is_idempotent(): void {
        global $DB;

        $now      = time();
        $ticketid = $this->insert_sla_ticket([
            // A stale total from a previous partial run. The step must overwrite
            // it with the computed value, never add to it.
            'sla_paused_seconds' => 999999,
        ]);

        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (6 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - (4 * DAYSECS));

        $this->run_sla_pause_upgrade();
        $first = (int) $DB->get_field('local_moodesk_ticket', 'sla_paused_seconds', ['id' => $ticketid]);

        $this->run_sla_pause_upgrade();
        $second = (int) $DB->get_field('local_moodesk_ticket', 'sla_paused_seconds', ['id' => $ticketid]);

        $this->assertSame(2 * DAYSECS, $first);
        $this->assertSame($first, $second);
    }

    public function test_sla_pause_backfill_falls_back_to_timemodified_without_history(): void {
        global $DB;

        $now          = time();
        $timemodified = $now - (3 * DAYSECS);

        // Pending, but nothing in history accounts for it: a ticket older than
        // the history table, or one written by a path that skipped it.
        $ticketid = $this->insert_sla_ticket([
            'status'       => self::STATUS_PENDING,
            'timemodified' => $timemodified,
        ]);

        $this->run_sla_pause_upgrade();

        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid]);

        $this->assertSame(0, (int) $ticket->sla_paused_seconds);
        $this->assertSame($timemodified, (int) $ticket->sla_paused_since);
    }

    public function test_sla_pause_backfill_caps_an_unclosed_interval_at_timemodified(): void {
        global $DB;

        $now          = time();
        $timemodified = $now - (4 * DAYSECS);

        // History opens a pause and never closes it, yet the ticket is no longer
        // pending: the closing transition never reached history.
        $ticketid = $this->insert_sla_ticket([
            'status'       => self::STATUS_OPEN,
            'timemodified' => $timemodified,
        ]);
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (6 * DAYSECS));

        $this->run_sla_pause_upgrade();

        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid]);

        $this->assertSame(2 * DAYSECS, (int) $ticket->sla_paused_seconds);
        $this->assertNull($ticket->sla_paused_since);
    }

    public function test_sla_pause_backfill_clears_a_breach_the_pause_now_covers(): void {
        global $DB;

        set_config('sla_response_hours_normal', 0, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 72, 'local_moodesk');

        $now = time();

        // Open 5 days against a 72h resolution target, but 4 of those days were
        // spent waiting on the requester — inside the budget once paused.
        $ticketid = $this->insert_sla_ticket([
            'status'       => self::STATUS_OPEN,
            'timecreated'  => $now - (5 * DAYSECS),
            'sla_breached' => 1,
        ]);
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (5 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - DAYSECS);

        $this->run_sla_pause_upgrade();

        $this->assertSame(
            0,
            (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid])
        );
    }

    public function test_sla_pause_backfill_keeps_a_breach_the_pause_does_not_cover(): void {
        global $DB;

        set_config('sla_response_hours_normal', 0, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 72, 'local_moodesk');

        $now = time();

        // 20 days open, 1 of them paused. Still well past the deadline.
        $ticketid = $this->insert_sla_ticket([
            'status'       => self::STATUS_OPEN,
            'timecreated'  => $now - (20 * DAYSECS),
            'sla_breached' => 1,
        ]);
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (5 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - (4 * DAYSECS));

        $this->run_sla_pause_upgrade();

        $this->assertSame(
            1,
            (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid])
        );
    }

    public function test_sla_pause_backfill_leaves_terminal_tickets_flagged(): void {
        global $DB;

        set_config('sla_response_hours_normal', 0, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 72, 'local_moodesk');

        $now = time();

        // Resolved long after its deadline. The flag is history now, and the
        // step must not rewrite it however the pause maths come out.
        $ticketid = $this->insert_sla_ticket([
            'status'       => 4,
            'timecreated'  => $now - (30 * DAYSECS),
            'timeresolved' => $now - DAYSECS,
            'sla_breached' => 1,
        ]);
        $this->insert_status_history($ticketid, self::STATUS_PENDING, $now - (29 * DAYSECS));
        $this->insert_status_history($ticketid, self::STATUS_OPEN, $now - (2 * DAYSECS));

        $this->run_sla_pause_upgrade();

        $this->assertSame(
            1,
            (int) $DB->get_field('local_moodesk_ticket', 'sla_breached', ['id' => $ticketid])
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Licence host — 2026091604
     *
     * Since 3.0.2 the step 2026111002 deletes every revocation row under
     * local_moodesk, and a call to the upgrade runs every step to the end. So
     * these cases pin the outcome of the whole path: whatever 2026091604 and
     * 2026100104 did to the row on the way (rewrite only the retired default,
     * keep a mirror) is no longer visible from here, and nothing is built to
     * stop an upgrade half way to look at it.
     * -------------------------------------------------------------------------
     */

    /**
     * Run only the licence-host block, as a site upgrading from 2026091603 would.
     */
    private function run_crl_host_upgrade(): void {
        set_config('version', self::BEFORE_CRL_HOST, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_CRL_HOST));
    }

    public function test_a_retired_crl_default_does_not_survive_the_upgrade(): void {
        // What every site installed before 2.30.0 holds: Moodle persisted the
        // admin setting's default when the plugin was installed or upgraded.
        // The chain carries it on through the 2026-10 rotation to /crl/v2/.
        set_config('revocation_crl_url', self::OLD_CRL_URL, 'local_moodesk');

        $this->run_crl_host_upgrade();

        $this->assertFalse(get_config('local_moodesk', 'revocation_crl_url'));
        $this->assertGreaterThanOrEqual(2026091604, (int) get_config('local_moodesk', 'version'));
    }

    public function test_a_custom_crl_url_under_the_base_does_not_survive_the_upgrade(): void {
        // A mirror under local_moodesk is a 2.x leftover too: since 3.0.0 the
        // add-on reads its own row (local_moodeskpro/revocation_crl_url).
        $mirror = 'https://mirror.example.test/moodesk/revocations.json';
        set_config('revocation_crl_url', $mirror, 'local_moodesk');

        $this->run_crl_host_upgrade();

        $this->assertFalse(get_config('local_moodesk', 'revocation_crl_url'));
    }

    public function test_crl_host_upgrade_leaves_an_unset_row_alone(): void {
        // No row at all → the runtime default applies; the step must not
        // invent a row just to write the new host into it.
        unset_config('revocation_crl_url', 'local_moodesk');

        $this->run_crl_host_upgrade();

        $this->assertFalse(get_config('local_moodesk', 'revocation_crl_url'));
    }

    public function test_crl_host_upgrade_is_idempotent(): void {
        set_config('revocation_crl_url', self::OLD_CRL_URL, 'local_moodesk');

        $this->run_crl_host_upgrade();
        $this->assertFalse(get_config('local_moodesk', 'revocation_crl_url'));

        // A second pass over the same blocks finds no row and writes none.
        $this->run_crl_host_upgrade();
        $this->assertFalse(get_config('local_moodesk', 'revocation_crl_url'));
    }

    /*
     * -------------------------------------------------------------------------
     * Licence key rotation — 2026100104
     * -------------------------------------------------------------------------
     */

    /**
     * Run the key-rotation block, as a site upgrading from 2026100103 would.
     */
    private function run_key_rotation_upgrade(): void {
        set_config('version', self::BEFORE_KEY_ROTATION, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_KEY_ROTATION));
    }

    public function test_the_key_rotation_path_leaves_no_retired_crl_default(): void {
        set_config('revocation_crl_url', self::NEW_CRL_URL, 'local_moodesk');

        $this->run_key_rotation_upgrade();

        $this->assertFalse(get_config('local_moodesk', 'revocation_crl_url'));
    }

    /**
     * Gate #3: the legacy `edition` row does not survive the full upgrade path.
     * 2026040701 predates every savepoint.
     */
    public function test_the_legacy_edition_row_does_not_survive_the_full_upgrade(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/moodesk/db/upgrade.php');
        require_once($CFG->dirroot . '/lib/upgradelib.php');
        set_config('edition', 'enterprise', 'local_moodesk');
        set_config('version', 2026040701, 'local_moodesk');

        $this->assertTrue(\xmldb_local_moodesk_upgrade(2026040701));

        $this->assertFalse(get_config('local_moodesk', 'edition'));
    }

    public function test_the_key_rotation_path_leaves_no_custom_crl_url(): void {
        $mirror = 'https://mirror.example.test/moodesk/revocations.json';
        set_config('revocation_crl_url', $mirror, 'local_moodesk');

        $this->run_key_rotation_upgrade();

        $this->assertFalse(get_config('local_moodesk', 'revocation_crl_url'));
    }

    public function test_key_rotation_forgets_the_list_verified_with_the_old_key(): void {
        // A list signed with the leaked key could carry an updated_at far in the
        // future and lock out every legitimate list after it.
        set_config('revocation_list', '["victim|1"]', 'local_moodesk');
        set_config('revocation_updated_at', 4102444800, 'local_moodesk');

        $this->run_key_rotation_upgrade();

        $this->assertFalse(get_config('local_moodesk', 'revocation_list'));
        $this->assertFalse(get_config('local_moodesk', 'revocation_updated_at'));
    }

    /*
     * -------------------------------------------------------------------------
     * Leftover revocation rows — 2026111002 (3.0.2)
     * -------------------------------------------------------------------------
     */

    /** The four rows local_moodesk 2.x kept and nothing reads since 3.0.0. */
    private const REVOCATION_ROWS = ['revocation_crl_url', 'revocation_enabled', 'revocation_list', 'revocation_updated_at'];

    /**
     * Run the 3.0.2 steps, as a site upgrading from 3.0.1 would.
     */
    private function run_302_upgrade(): void {
        set_config('version', 2026110700, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(2026110700));
    }

    public function test_302_deletes_the_four_revocation_rows(): void {
        set_config('revocation_crl_url', 'https://mirror.example.test/revocations.json', 'local_moodesk');
        set_config('revocation_enabled', 1, 'local_moodesk');
        set_config('revocation_list', '["a|1"]', 'local_moodesk');
        set_config('revocation_updated_at', 1700000000, 'local_moodesk');

        $this->run_302_upgrade();

        foreach (self::REVOCATION_ROWS as $name) {
            $this->assertFalse(get_config('local_moodesk', $name), $name);
        }
        $this->assertSame(2026111002, (int) get_config('local_moodesk', 'version'));
    }

    public function test_302_without_the_rows_does_not_fail(): void {
        foreach (self::REVOCATION_ROWS as $name) {
            unset_config($name, 'local_moodesk');
        }

        $this->run_302_upgrade();

        foreach (self::REVOCATION_ROWS as $name) {
            $this->assertFalse(get_config('local_moodesk', $name), $name);
        }
    }

    public function test_302_leaves_the_add_on_revocation_rows_alone(): void {
        $addon = [
            'revocation_crl_url'    => 'https://mirror.example.test/revocations.json',
            'revocation_enabled'    => '0',
            'revocation_list'       => '["b|2"]',
            'revocation_updated_at' => '1700000001',
        ];
        foreach ($addon as $name => $value) {
            set_config($name, $value, 'local_moodeskpro');
            set_config($name, 'base copy', 'local_moodesk');
        }

        $this->run_302_upgrade();

        foreach ($addon as $name => $value) {
            $this->assertSame($value, get_config('local_moodeskpro', $name), $name);
            $this->assertFalse(get_config('local_moodesk', $name), $name);
        }
    }

    /**
     * Run only the IMAP transport block, as a site upgrading from 2.35.0 would.
     */
    private function run_imap_transport_upgrade(): void {
        set_config('version', self::BEFORE_IMAP_TRANSPORT, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_IMAP_TRANSPORT));
    }

    public function test_imap_transport_upgrade_keeps_each_mailbox_on_its_transport(): void {
        // SSL on stays SSL; SSL off stays in the clear, and is warned about
        // elsewhere rather than broken here.
        foreach (['1' => 'ssl', '0' => 'none'] as $checkbox => $transport) {
            unset_config('email_imap_security', 'local_moodesk');
            set_config('email_imap_ssl', $checkbox, 'local_moodesk');

            $this->run_imap_transport_upgrade();

            $this->assertSame($transport, get_config('local_moodesk', 'email_imap_security'));
            $this->assertFalse(get_config('local_moodesk', 'email_imap_ssl'), 'The old row must go');
        }
    }

    public function test_imap_transport_upgrade_leaves_an_unset_row_alone(): void {
        // No checkbox row → no transport row: the new default applies.
        unset_config('email_imap_ssl', 'local_moodesk');
        unset_config('email_imap_security', 'local_moodesk');

        $this->run_imap_transport_upgrade();

        $this->assertFalse(get_config('local_moodesk', 'email_imap_security'));
        $this->assertFalse(get_config('local_moodesk', 'email_imap_security'));
    }

    public function test_imap_transport_upgrade_is_idempotent(): void {
        set_config('email_imap_ssl', '0', 'local_moodesk');
        $this->run_imap_transport_upgrade();

        // A second pass finds no checkbox row, and a transport chosen since
        // the first pass is not overwritten.
        set_config('email_imap_security', 'starttls', 'local_moodesk');
        $this->run_imap_transport_upgrade();

        $this->assertSame('starttls', get_config('local_moodesk', 'email_imap_security'));
    }

    /*
     * -------------------------------------------------------------------------
     * Reply-token key for sites installed fresh — 2026100800
     * -------------------------------------------------------------------------
     */

    /**
     * Run the reply-token key block, as a site upgrading from 2.38.0 would.
     */
    private function run_reply_token_key_upgrade(): void {
        set_config('version', self::BEFORE_REPLY_TOKEN_KEY, 'local_moodesk');
        $this->assertTrue(xmldb_local_moodesk_upgrade(self::BEFORE_REPLY_TOKEN_KEY));
    }
}
