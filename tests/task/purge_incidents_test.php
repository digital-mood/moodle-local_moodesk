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
 * Tests for the operational incident retention sweep.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\task;

use local_moodesk\domain\incident;
use local_moodesk\repository\incident_repository;
use local_moodesk\service\incident_service;

/**
 * Retention tests.
 *
 * Two things are worth protecting here and neither is "the DELETE works". The
 * first is the predicate: retention is measured from the last occurrence and
 * ignores status, so a test has to prove an old `open` row goes and a recent
 * one stays. The second is what happens after: the fingerprint index is
 * unique, so purging and then failing again must insert cleanly rather than
 * collide with the row that is no longer there.
 *
 * @covers \local_moodesk\task\purge_incidents
 * @covers \local_moodesk\repository\incident_repository::purge_older_than
 * @covers \local_moodesk\service\incident_service::retention_seconds
 */
final class purge_incidents_test extends \advanced_testcase {
    /** @var int Fixed "now" so age arithmetic in the cases stays readable. */
    private int $now;

    /**
     * Every case writes to the incident table.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->now = time();
    }

    /**
     * Seed one incident aged a given number of days since its last occurrence.
     *
     * Inserted directly rather than through the repository: the point of these
     * cases is age, and record_occurrence() always stamps now.
     *
     * @param string $code   Failure code; also makes the fingerprint unique.
     * @param int    $agedays How long ago the last occurrence was.
     * @param string $status One of the incident::STATUS_* constants.
     * @return int The row id.
     */
    private function seed(string $code, int $agedays, string $status = incident::STATUS_OPEN): int {
        global $DB;

        $lastseen = $this->now - ($agedays * DAYSECS);

        return (int) $DB->insert_record('local_moodesk_incident', (object) [
            'fingerprint'   => incident::fingerprint(incident::SOURCE_OBSERVER, $code),
            'source'        => incident::SOURCE_OBSERVER,
            'code'          => $code,
            'severity'      => incident::SEVERITY_ERROR,
            'message'       => 'boom',
            'context'       => null,
            'occurrences'   => 1,
            'status'        => $status,
            'timefirstseen' => $lastseen,
            'timelastseen'  => $lastseen,
        ]);
    }

    /**
     * Run the task, swallowing its mtrace output.
     */
    private function run_task(): void {
        ob_start();
        (new purge_incidents())->execute();
        ob_end_clean();
    }

    /**
     * The headline contract: old rows go, recent rows stay, and nothing else
     * moves. A sweep that takes one row too many is worse than one that takes
     * none, because the evidence it destroyed is the reason the table exists.
     */
    public function test_it_deletes_the_old_and_only_the_old(): void {
        global $DB;

        $ancient = $this->seed('a.old', 200);
        $stale   = $this->seed('a.stale', 91);
        $edge    = $this->seed('a.edge', 89);
        $fresh   = $this->seed('a.fresh', 1);

        $this->run_task();

        $this->assertFalse($DB->record_exists('local_moodesk_incident', ['id' => $ancient]));
        $this->assertFalse($DB->record_exists('local_moodesk_incident', ['id' => $stale]));
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $edge]));
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $fresh]));
    }

    /**
     * Status buys no exemption. This is the decision the ticket spelled out:
     * ninety days without recurring makes an `open` row a problem nobody
     * closed, not a problem still happening.
     */
    public function test_an_open_incident_is_not_spared(): void {
        global $DB;

        $open         = $this->seed('b.open', 120, incident::STATUS_OPEN);
        $acknowledged = $this->seed('b.ack', 120, incident::STATUS_ACKNOWLEDGED);
        $resolved     = $this->seed('b.res', 120, incident::STATUS_RESOLVED);

        $this->run_task();

        $this->assertSame(0, $DB->count_records('local_moodesk_incident'));
        $this->assertFalse($DB->record_exists('local_moodesk_incident', ['id' => $open]));
        $this->assertFalse($DB->record_exists('local_moodesk_incident', ['id' => $acknowledged]));
        $this->assertFalse($DB->record_exists('local_moodesk_incident', ['id' => $resolved]));
    }

    /**
     * Retention dates from the last occurrence, never from the first. A
     * failure that has recurred for a year is a live problem; dating it from
     * when it started would delete it mid-incident, which is precisely when
     * an administrator needs to see it.
     */
    public function test_a_long_running_incident_survives_on_recent_recurrence(): void {
        global $DB;

        $id = $this->seed('c.chronic', 400);
        $DB->set_field('local_moodesk_incident', 'timelastseen', $this->now - HOURSECS, ['id' => $id]);

        $this->run_task();

        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $id]));
    }

    /**
     * Purging and then failing again inserts a fresh row rather than colliding
     * with the unique fingerprint index — and the new row's timefirstseen is
     * the new occurrence, not the deleted one's. An outage that returns after
     * a quarter of silence is a new incident.
     */
    public function test_purging_then_failing_again_inserts_a_new_row(): void {
        global $DB;

        $code   = 'd.recurring';
        $oldid  = $this->seed($code, 200);
        $oldrow = $DB->get_record('local_moodesk_incident', ['id' => $oldid]);

        $this->run_task();

        $occurrence                = new \stdClass();
        $occurrence->fingerprint   = incident::fingerprint(incident::SOURCE_OBSERVER, $code);
        $occurrence->source        = incident::SOURCE_OBSERVER;
        $occurrence->code          = $code;
        $occurrence->severity      = incident::SEVERITY_ERROR;
        $occurrence->message       = 'it is back';
        $occurrence->context       = null;
        $occurrence->now           = $this->now;

        $newid = (new incident_repository())->record_occurrence($occurrence);
        $new   = $DB->get_record('local_moodesk_incident', ['id' => $newid]);

        $this->assertNotEquals($oldid, $newid);
        $this->assertSame(1, (int) $new->occurrences);
        $this->assertSame($this->now, (int) $new->timefirstseen);
        $this->assertGreaterThan((int) $oldrow->timefirstseen, (int) $new->timefirstseen);
        $this->assertSame(1, $DB->count_records('local_moodesk_incident', ['code' => $code]));
    }

    /**
     * A configured retention shortens the window.
     */
    public function test_the_setting_is_honoured(): void {
        global $DB;

        set_config('incident_retention_days', 7, 'local_moodesk');

        $outside = $this->seed('e.outside', 10);
        $inside  = $this->seed('e.inside', 3);

        $this->run_task();

        $this->assertFalse($DB->record_exists('local_moodesk_incident', ['id' => $outside]));
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $inside]));
    }

    /**
     * An empty or nonsensical setting falls back to the default rather than to
     * zero. Zero would read as "delete everything on the next cron run", which
     * is not a thing an operator should be able to do by clearing a field.
     *
     * @param mixed $stored Value written to the setting.
     * @dataProvider unusable_retention_provider
     */
    public function test_an_unusable_setting_falls_back_to_the_default($stored): void {
        global $DB;

        set_config('incident_retention_days', $stored, 'local_moodesk');

        $this->assertSame(
            incident::DEFAULT_RETENTION_DAYS * DAYSECS,
            incident_service::retention_seconds()
        );

        $recent = $this->seed('f.recent', 5);
        $this->run_task();
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $recent]));
    }

    /**
     * Settings values that must not be taken at face value.
     *
     * @return array<string, array{mixed}>
     */
    public static function unusable_retention_provider(): array {
        return [
            'empty'    => [''],
            'zero'     => [0],
            'negative' => [-30],
        ];
    }
}
