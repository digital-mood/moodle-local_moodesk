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
 * Tests for the operational incident repository.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\incident;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/racing_incident_repository.php');

/**
 * Incident repository tests, covering the upsert the service depends on and
 * the unique-fingerprint contract that makes it safe.
 *
 * @covers \local_moodesk\repository\incident_repository
 */
final class incident_repository_test extends \advanced_testcase {
    /**
     * Every case writes to the incident table.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Build a well-formed occurrence to hand to the repository.
     *
     * @param string $code     Failure code.
     * @param string $message  Message to store.
     * @param string $severity One of the incident::SEVERITY_* constants.
     * @return \stdClass
     */
    private function occurrence(
        string $code = 'a.b.c',
        string $message = 'boom',
        string $severity = incident::SEVERITY_ERROR
    ): \stdClass {
        $row              = new \stdClass();
        $row->fingerprint = incident::fingerprint(incident::SOURCE_OBSERVER, $code);
        $row->source      = incident::SOURCE_OBSERVER;
        $row->code        = $code;
        $row->severity    = $severity;
        $row->message     = $message;
        $row->context     = null;
        $row->now         = time();

        return $row;
    }

    /**
     * The fingerprint column is unique, which is what makes the upsert safe
     * rather than merely convenient. Without it a concurrent double-insert
     * would split one incident in two and the occurrence counter would lie.
     */
    public function test_fingerprint_is_unique_in_the_schema(): void {
        global $DB;

        $repo = new incident_repository();
        $repo->record_occurrence($this->occurrence());

        $duplicate                = new \stdClass();
        $duplicate->fingerprint   = incident::fingerprint(incident::SOURCE_OBSERVER, 'a.b.c');
        $duplicate->source        = incident::SOURCE_OBSERVER;
        $duplicate->code          = 'a.b.c';
        $duplicate->severity      = incident::SEVERITY_ERROR;
        $duplicate->message       = 'again';
        $duplicate->occurrences   = 1;
        $duplicate->status        = incident::STATUS_OPEN;
        $duplicate->timefirstseen = time();
        $duplicate->timelastseen  = time();

        $this->expectException(\dml_write_exception::class);
        $DB->insert_record('local_moodesk_incident', $duplicate);
    }

    /**
     * Losing the insert race is not an error: the row the winner created is the
     * row this call wanted, so the loser retries as an update.
     *
     * The race is reproduced by making the pre-insert lookup miss once while
     * the row is really there — which is exactly the interleaving two web
     * requests can produce.
     */
    public function test_losing_the_insert_race_bumps_instead_of_failing(): void {
        global $DB;

        $racing = new class extends incident_repository {
            /** @var bool Whether the first lookup has already been answered. */
            private bool $liedonce = false;

            /**
             * Miss on the first lookup, tell the truth afterwards.
             *
             * @param string $fingerprint
             * @return \stdClass|null
             */
            public function get_by_fingerprint(string $fingerprint): ?\stdClass {
                if (!$this->liedonce) {
                    $this->liedonce = true;
                    return null;
                }
                return parent::get_by_fingerprint($fingerprint);
            }
        };

        // The "winner" writes first, through a plain repository.
        $winnerid = (new incident_repository())->record_occurrence($this->occurrence());

        // The "loser" misses the lookup, collides on the unique index, recovers.
        $loserid = $racing->record_occurrence($this->occurrence('a.b.c', 'second'));

        $this->assertSame($winnerid, $loserid);
        $this->assertSame(1, $DB->count_records('local_moodesk_incident'));
        $this->assertSame(2, (int) $DB->get_field('local_moodesk_incident', 'occurrences', ['id' => $winnerid]));
    }

    /**
     * A write that fails for a reason other than the unique index is a real
     * error and must reach the caller — the service is what decides to swallow
     * it, and it can only decide that if it is told.
     */
    public function test_a_genuine_write_failure_still_propagates(): void {
        $broken = new class extends incident_repository {
            /**
             * Never find anything, so the insert path always runs.
             *
             * @param string $fingerprint
             * @return \stdClass|null
             */
            public function get_by_fingerprint(string $fingerprint): ?\stdClass {
                return null;
            }
        };

        // A message longer than char(255) is rejected by the DB layer, and no
        // row appears — so the recovery lookup finds nothing and rethrows.
        $this->expectException(\dml_write_exception::class);
        $broken->record_occurrence($this->occurrence('a.b.c', str_repeat('x', 500)));
    }

    /**
     * Active incidents come back newest first so the health page leads with
     * what is happening now.
     */
    public function test_active_incidents_are_ordered_newest_first(): void {
        global $DB;

        $repo = new incident_repository();
        $old  = $repo->record_occurrence($this->occurrence('a.b.old'));
        $new  = $repo->record_occurrence($this->occurrence('a.b.new'));

        $DB->set_field('local_moodesk_incident', 'timelastseen', time() - 600, ['id' => $old]);

        $active = array_keys($repo->get_active(time() - 3600));
        $this->assertSame([$new, $old], $active);
    }

    /**
     * The per-source count only counts what is inside the window and unresolved.
     */
    public function test_per_source_counts_exclude_stale_and_resolved(): void {
        global $DB;

        $repo = new incident_repository();

        $live    = $repo->record_occurrence($this->occurrence('a.b.live'));
        $stale   = $repo->record_occurrence($this->occurrence('a.b.stale'));
        $handled = $repo->record_occurrence($this->occurrence('a.b.handled'));

        $DB->set_field('local_moodesk_incident', 'timelastseen', time() - 7200, ['id' => $stale]);
        $repo->set_status($handled, incident::STATUS_RESOLVED, 2, time());

        $this->assertSame([incident::SOURCE_OBSERVER => 1], $repo->count_active_by_source(time() - 3600));
        $this->assertSame([$live], array_keys($repo->get_active(time() - 3600)));
    }

    /**
     * Acknowledging stamps the actor and the time; resolving stamps its own
     * pair and leaves the acknowledgement intact as the record of who looked
     * first.
     */
    public function test_status_changes_stamp_their_own_columns(): void {
        global $DB;

        $repo = new incident_repository();
        $id   = $repo->record_occurrence($this->occurrence());

        $this->assertTrue($repo->set_status($id, incident::STATUS_ACKNOWLEDGED, 11, 1000));
        $this->assertTrue($repo->set_status($id, incident::STATUS_RESOLVED, 22, 2000));

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::STATUS_RESOLVED, $row->status);
        $this->assertSame(11, (int) $row->acknowledgedby);
        $this->assertSame(1000, (int) $row->timeacknowledged);
        $this->assertSame(22, (int) $row->resolvedby);
        $this->assertSame(2000, (int) $row->timeresolved);
    }

    /**
     * Setting the status of a row that is not there reports failure instead of
     * creating one.
     */
    public function test_status_change_on_a_missing_row_returns_false(): void {
        $this->assertFalse((new incident_repository())->set_status(424242, incident::STATUS_RESOLVED, 1, time()));
    }

    /**
     * An occurrence survives retention deleting the row mid-upsert.
     *
     * The window is narrow and entirely real: record_occurrence() reads a row
     * by fingerprint, and purge_incidents can delete that row before the
     * UPDATE lands. Moodle's update_record() issues `UPDATE ... WHERE id = ?`
     * and never asks how many rows it matched, so writing to a row that no
     * longer exists succeeds — and the old code returned that id, reporting an
     * occurrence it had not stored. Silent loss, in the one subsystem whose
     * whole purpose is that failures stop disappearing quietly.
     *
     * The seam deletes the row at exactly that instant. The upsert must notice
     * and insert instead of trusting the write.
     */
    public function test_an_occurrence_survives_the_row_being_purged_mid_upsert(): void {
        global $DB;

        $repo = new incident_repository();
        $first = $repo->record_occurrence($this->occurrence('race.a.b', 'first'));

        $racing = new racing_incident_repository();
        $second = $racing->record_occurrence($this->occurrence('race.a.b', 'second'));

        // The seam fired, and on the row the lookup had just handed back.
        $this->assertSame(1, $racing->deletions);
        $this->assertSame($first, $racing->deletedid);

        // A row exists, and the returned id is that row — not the dead one.
        $this->assertNotSame($first, $second);
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $second]));
        $this->assertSame(1, $DB->count_records('local_moodesk_incident'));

        // It is a genuine first occurrence of the class, not a resurrected
        // counter: retention deleted the history, so the count starts again.
        $row = $DB->get_record('local_moodesk_incident', ['id' => $second]);
        $this->assertSame('second', $row->message);
        $this->assertSame(1, (int) $row->occurrences);
        $this->assertSame(
            incident::fingerprint(incident::SOURCE_OBSERVER, 'race.a.b'),
            $row->fingerprint
        );
    }

    /**
     * Every id record_occurrence() returns points at a row that exists.
     *
     * The contract the caller actually relies on, stated without any knowledge
     * of how the upsert reaches it. incident_service hands this id to the
     * alerting path; an id for a deleted row would have alerting write into
     * nothing and report success.
     *
     * Checked at the instant each call returns, not once at the end. The
     * guarantee is "this id was live when you got it", never "this id is live
     * forever" — retention is entitled to delete the row a moment later, and a
     * test that asserted otherwise would be asserting that retention does not
     * work.
     *
     * Three shapes, one contract: the insert, the bump onto an existing row,
     * and the recovery after the row is purged mid-upsert.
     */
    public function test_the_returned_id_always_identifies_a_live_row(): void {
        global $DB;

        $plain  = new incident_repository();
        $racing = new racing_incident_repository();

        $calls = [
            'insert'   => fn(): int => $plain->record_occurrence($this->occurrence('live.a', 'one')),
            'bump'     => fn(): int => $plain->record_occurrence($this->occurrence('live.a', 'two')),
            'recovery' => fn(): int => $racing->record_occurrence($this->occurrence('live.a', 'three')),
        ];

        foreach ($calls as $shape => $call) {
            $id = $call();

            $this->assertGreaterThan(0, $id, "the $shape path returned no id");
            $this->assertTrue(
                $DB->record_exists('local_moodesk_incident', ['id' => $id]),
                "the $shape path returned id $id for a row that does not exist"
            );
        }

        // The recovery replaced the row rather than adding a second one: the
        // fingerprint is unique, so there is exactly one row for this class.
        $this->assertSame(1, $DB->count_records('local_moodesk_incident'));
        $this->assertSame(1, $racing->deletions);
    }

    /**
     * The recovery is bounded. Nothing here may spin.
     *
     * A repository that retried forever would turn a lost occurrence into a
     * hung request — a worse failure than the one being fixed, since the
     * caller is already in the middle of an operation that went wrong.
     *
     * The seam below is pathological on purpose: it deletes the row the lookup
     * just returned *and* puts a colliding one back, so every pass finds a row
     * to bump and every bump finds it gone. Real retention cannot do this; the
     * point is that even when the table refuses to settle, the loop stops.
     */
    public function test_the_upsert_gives_up_rather_than_spinning(): void {
        $repo = new class extends incident_repository {
            /** @var int How many passes reached the bump. */
            public int $calls = 0;

            /**
             * Delete the row, let the parent write into the hole, then restore
             * a colliding row so the next pass cannot settle either.
             *
             * @param \stdClass $existing
             * @param \stdClass $incoming
             * @return int|null
             */
            protected function bump(\stdClass $existing, \stdClass $incoming): ?int {
                global $DB;

                $this->calls++;
                $DB->delete_records('local_moodesk_incident', ['id' => $existing->id]);

                $result = parent::bump($existing, $incoming);

                $replacement = clone $existing;
                unset($replacement->id);
                $DB->insert_record('local_moodesk_incident', $replacement);

                return $result;
            }
        };

        $repo->record_occurrence($this->occurrence('bounded.a', 'first'));

        $this->expectException(\moodle_exception::class);

        try {
            $repo->record_occurrence($this->occurrence('bounded.a', 'second'));
        } finally {
            // Bounded, and bounded at the documented number rather than at
            // whatever the loop happened to do.
            $this->assertSame(3, $repo->calls);
        }
    }
}
