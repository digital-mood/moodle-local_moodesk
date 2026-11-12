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
 * How incident recording behaves around database transactions.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\incident;
use local_moodesk\repository\incident_repository;

/**
 * The transaction question OBS-02 had to answer before instrumenting anything.
 *
 * THE WORRY. An incident written inside a delegated transaction that later
 * rolls back goes with it — the failure vanishes from the record precisely in
 * the case where something went wrong enough to abort the work.
 *
 * WHAT THE EXPERIMENT FOUND. The loss is real and core does not prevent it.
 * \core\event\manager defers only *external* observers past an open
 * transaction (its $extbuffer); internal observers — which is every observer
 * this plugin registers — are dispatched immediately from the $buffer, inside
 * whatever transaction was open when the event fired. Today mooDesk happens to
 * trigger its events after allow_commit(), so no instrumented catch block sits
 * inside a transaction; but that is a property of nine call sites a future edit
 * could change silently, not a guarantee.
 *
 * WHAT THE FIRST FIX GOT WRONG. Holding writes back while a transaction was
 * open looked right and was caught by the PostgreSQL leg of CI: Moodle's own
 * PHPUnit runner wraps every test in a delegated transaction on postgres and
 * mssql (advanced_testcase::setup_test_environment), so "a transaction is open"
 * was true for the entire run and nothing was ever written. The lesson
 * generalises past the test harness — any host holding a transaction open would
 * have silenced the feature in production too.
 *
 * SO THE SERVICE WRITES FIRST AND VERIFIES AFTERWARDS. The row goes in
 * immediately, always. When that happened inside a transaction, a receipt is
 * kept and re-checked once the transaction closes; a row that is gone, or still
 * there but older than the occurrence recorded, is written again. Writing
 * always cannot be silenced by an enclosing transaction; verification only adds
 * durability.
 *
 * NOTE ON THESE TESTS. On postgres the runner's own transaction is already open
 * when each case begins. The cases that need to own the outermost transaction
 * call require_own_transaction(), which releases it, so they run on every
 * database.
 *
 * @covers \local_moodesk\service\incident_service
 */
final class incident_transaction_test extends \advanced_testcase {
    /**
     * Every case writes to the incident table.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        incident_service::reset_deferred_for_testing();
    }

    /**
     * Static buffers outlive the per-test database reset, so a deferred row
     * from one case would otherwise surface in the next.
     */
    protected function tearDown(): void {
        incident_service::reset_deferred_for_testing();
        parent::tearDown();
    }

    /**
     * Make this case own the outermost transaction, on every database.
     *
     * advanced_testcase wraps every case in a transaction on postgres and
     * mssql so DDL can be rolled back. A case that needs to open, roll back
     * and then observe the outermost transaction cannot do that from inside
     * another one: rolling back a nested transaction tears down the whole
     * stack, including the runner's. preventResetByRollback() commits the
     * runner's wrapper and resets the database the slow way after the case,
     * which is what core provides for tests that are not compatible with it.
     * Before it existed here, these cases skipped on postgres and the paths
     * they cover had no coverage on that database.
     */
    private function require_own_transaction(): void {
        global $DB;

        $this->preventResetByRollback();
        $this->assertFalse(
            $DB->is_transaction_started(),
            'The case must own the outermost transaction.'
        );
    }

    /**
     * Roll a transaction back and swallow the rethrow, which is contract
     * rather than failure.
     *
     * @param \moodle_transaction $transaction
     */
    private function rollback(\moodle_transaction $transaction): void {
        try {
            $transaction->rollback(new \moodle_exception('error', 'moodle', '', null, 'forced'));
        } catch (\moodle_exception $e) {
            unset($e);
        }
    }

    /**
     * A raw write inside a transaction that rolls back is gone.
     *
     * This is the failure mode the unit was told to verify rather than assume.
     * It goes through the repository directly, because the repository is the
     * layer with no protection — proving the hazard is real is what justifies
     * the buffering the service does on top of it.
     */
    public function test_a_raw_write_inside_a_rolled_back_transaction_is_lost(): void {
        global $DB;

        $this->require_own_transaction();

        $repo = new incident_repository();

        $row              = new \stdClass();
        $row->fingerprint = incident::fingerprint(incident::SOURCE_OBSERVER, 'observer.probe.raw');
        $row->source      = incident::SOURCE_OBSERVER;
        $row->code        = 'observer.probe.raw';
        $row->severity    = incident::SEVERITY_ERROR;
        $row->message     = 'written inside a doomed transaction';
        $row->context     = null;
        $row->now         = time();

        $transaction = $DB->start_delegated_transaction();
        $id          = $repo->record_occurrence($row);

        // Visible from inside the transaction that wrote it.
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $id]));

        $this->rollback($transaction);

        $this->assertFalse(
            $DB->record_exists('local_moodesk_incident', ['id' => $id]),
            'A raw write inside a rolled-back transaction survived, which contradicts the DML '
            . 'transaction contract this unit is designed around.'
        );
    }

    /**
     * Recording inside a transaction writes immediately — it does not wait.
     * Holding the write back is what broke on PostgreSQL, where a transaction
     * is open for the whole of every test.
     */
    public function test_recording_inside_a_transaction_writes_immediately(): void {
        global $DB;

        $svc = incident_service::create();

        $transaction = $DB->start_delegated_transaction();
        $id          = $svc->record(
            incident::SOURCE_OBSERVER,
            'observer.probe.written',
            incident::SEVERITY_ERROR,
            'recorded mid-transaction'
        );

        $this->assertGreaterThan(0, $id);
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $id]));

        // A receipt is kept, because this write is only as durable as the
        // transaction it happened in.
        $this->assertSame(1, incident_service::deferred_count());

        $transaction->allow_commit();
    }

    /**
     * A write that committed needs no rewrite: settling the receipt finds the
     * row intact and leaves it alone, counter included.
     */
    public function test_a_committed_write_is_not_written_twice(): void {
        global $DB;

        $svc = incident_service::create();

        $transaction = $DB->start_delegated_transaction();
        $svc->record(incident::SOURCE_OBSERVER, 'observer.probe.committed', incident::SEVERITY_ERROR, 'x');
        $transaction->allow_commit();

        incident_service::flush_deferred();

        $rows = $DB->get_records('local_moodesk_incident', ['code' => 'observer.probe.committed']);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) reset($rows)->occurrences);
    }

    /**
     * An incident written outside any transaction survives a later rollback of
     * unrelated work — the ordinary case, pinned so a future change cannot
     * quietly move recording inside a caller's transaction.
     */
    public function test_record_outside_a_transaction_survives_unrelated_rollback(): void {
        global $DB;

        $this->require_own_transaction();

        $id = incident_service::create()->record(
            incident::SOURCE_SCHEDULED_TASK,
            'task.probe.outside',
            incident::SEVERITY_ERROR,
            'written before any transaction'
        );

        $transaction = $DB->start_delegated_transaction();
        $DB->set_field('local_moodesk_incident', 'message', 'mutated', ['id' => $id]);
        $this->rollback($transaction);

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertNotEmpty($row);
        $this->assertSame('written before any transaction', $row->message);
    }

    /**
     * The incident survives the rollback of the work it was recorded during —
     * the property the whole deferral exists for.
     */
    public function test_deferred_incident_survives_the_rollback(): void {
        global $DB;

        $this->require_own_transaction();

        $svc = incident_service::create();

        $transaction = $DB->start_delegated_transaction();
        $svc->record(
            incident::SOURCE_OBSERVER,
            'observer.probe.survives',
            incident::SEVERITY_ERROR,
            'recorded before the rollback'
        );
        $this->rollback($transaction);

        // Standing in for the shutdown callback, which PHPUnit never reaches.
        incident_service::flush_deferred();

        $row = $DB->get_record('local_moodesk_incident', ['code' => 'observer.probe.survives']);
        $this->assertNotEmpty($row);
        $this->assertSame('recorded before the rollback', $row->message);
        $this->assertSame(0, incident_service::deferred_count());
    }

    /**
     * Receipts settle on their own at the next recording that finds no
     * transaction open, so a long-running cron process does not carry them
     * until the very end.
     */
    public function test_the_next_untransacted_record_settles_receipts(): void {
        global $DB;

        $this->require_own_transaction();

        $svc = incident_service::create();

        $transaction = $DB->start_delegated_transaction();
        $svc->record(incident::SOURCE_UI, 'webhook.probe.first', incident::SEVERITY_ERROR, 'one');
        $this->rollback($transaction);

        $this->assertSame(1, incident_service::deferred_count());

        // A second, ordinary recording flushes what the transaction deferred.
        $svc->record(incident::SOURCE_UI, 'webhook.probe.second', incident::SEVERITY_ERROR, 'two');

        $this->assertSame(0, incident_service::deferred_count());
        $this->assertSame(2, $DB->count_records('local_moodesk_incident'));
    }

    /**
     * Repeated failures inside a transaction that rolls back come back with
     * their count intact.
     *
     * Each receipt carries the counter value its own write produced — 1, 2, 3,
     * 4, 5 — so settling them in order rebuilds the total rather than
     * collapsing it. That is a consequence of recording outcomes rather than
     * timestamps, not a separate mechanism.
     */
    public function test_duplicates_rolled_back_together_keep_their_count(): void {
        global $DB;

        $this->require_own_transaction();

        $svc = incident_service::create();

        $transaction = $DB->start_delegated_transaction();
        for ($i = 0; $i < 5; $i++) {
            $svc->record(incident::SOURCE_UI, 'webhook.probe.dup', incident::SEVERITY_ERROR, 'same');
        }
        $this->rollback($transaction);

        incident_service::flush_deferred();

        $rows = $DB->get_records('local_moodesk_incident', ['code' => 'webhook.probe.dup']);
        $this->assertCount(1, $rows, 'The incident itself must survive the rollback.');
        $this->assertSame(5, (int) reset($rows)->occurrences);
    }

    /**
     * Settling receipts while a transaction is still open is a no-op: reading
     * the row now would read the very state in question, so the receipts are
     * kept for the next attempt. The row itself is already written.
     */
    public function test_settling_during_an_open_transaction_is_a_no_op(): void {
        global $DB;

        $this->require_own_transaction();

        $svc = incident_service::create();

        $transaction = $DB->start_delegated_transaction();
        $id          = $svc->record(incident::SOURCE_OBSERVER, 'observer.probe.held', incident::SEVERITY_ERROR, 'held');

        incident_service::flush_deferred();

        // Receipt untouched, row present — nothing was decided either way.
        $this->assertSame(1, incident_service::deferred_count());
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $id]));

        $transaction->allow_commit();
    }

    /**
     * A resolved incident that reappears inside a transaction, during the very
     * second of its own last-seen stamp, must come back open after a rollback.
     *
     * This is the case a timestamp comparison cannot see. The write reopens the
     * row and moves nothing else — timelastseen is already the current second —
     * so after the rollback the row reads `resolved` with a stamp identical to
     * the one recorded. Verifying by timestamp would call that survived and
     * leave a live recurrence filed as handled, which in OBS-03 also means
     * silently unalerted: resolved incidents do not alert.
     */
    public function test_a_reopen_rolled_back_in_the_same_second_is_rewritten(): void {
        global $DB;

        $this->require_own_transaction();

        $user = $this->getDataGenerator()->create_user();
        $svc  = incident_service::create();

        // An incident that happened and was closed.
        $id = $svc->record(incident::SOURCE_UI, 'webhook.probe.reopen', incident::SEVERITY_ERROR, 'first');
        $svc->resolve($id, (int) $user->id);

        // Pin the row into the exact state that defeats a timestamp check: last
        // seen *now*, and resolved.
        $now = time();
        $DB->set_field('local_moodesk_incident', 'timelastseen', $now, ['id' => $id]);

        $before = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::STATUS_RESOLVED, $before->status);

        // It happens again, mid-transaction, and the transaction dies.
        $transaction = $DB->start_delegated_transaction();
        $svc->record(incident::SOURCE_UI, 'webhook.probe.reopen', incident::SEVERITY_ERROR, 'again');
        $this->rollback($transaction);

        // Guard the premise: after the rollback the row looks untouched by any
        // timestamp measure, so a timestamp-based check would pass it.
        $rolledback = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::STATUS_RESOLVED, $rolledback->status);
        $this->assertSame(
            (int) $before->timelastseen,
            (int) $rolledback->timelastseen,
            'The premise of this test is that the timestamp is unchanged; if it moved, '
            . 'the case being guarded against is not being exercised.'
        );
        $this->assertSame((int) $before->occurrences, (int) $rolledback->occurrences);

        incident_service::flush_deferred();

        // The recurrence is visible again.
        $after = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::STATUS_OPEN, $after->status);
        $this->assertNull($after->resolvedby);
        $this->assertNull($after->timeresolved);
        $this->assertSame(1, $DB->count_records('local_moodesk_incident'));
    }

    /**
     * A severity raised inside a transaction that rolls back is restored, even
     * when nothing else about the row moved.
     */
    public function test_a_rolled_back_escalation_is_rewritten(): void {
        global $DB;

        $this->require_own_transaction();

        $svc = incident_service::create();
        $id  = $svc->record(incident::SOURCE_OBSERVER, 'observer.probe.escalate', incident::SEVERITY_WARNING, 'w');

        $transaction = $DB->start_delegated_transaction();
        $svc->record(incident::SOURCE_OBSERVER, 'observer.probe.escalate', incident::SEVERITY_CRITICAL, 'c');
        $this->rollback($transaction);

        $this->assertSame(
            incident::SEVERITY_WARNING,
            $DB->get_field('local_moodesk_incident', 'severity', ['id' => $id]),
            'Premise: the rollback took the escalation with it.'
        );

        incident_service::flush_deferred();

        $this->assertSame(
            incident::SEVERITY_CRITICAL,
            $DB->get_field('local_moodesk_incident', 'severity', ['id' => $id])
        );
    }

    /**
     * Core dispatches internal observers immediately, even inside a
     * transaction — the finding that makes the deferral necessary rather than
     * merely tidy.
     *
     * Measured through an observer that reports whether a transaction was open
     * when it ran, not through redirectEvents(): the sink intercepts at trigger
     * time, before dispatch, so it cannot tell the two apart.
     */
    public function test_internal_observers_run_inside_the_open_transaction(): void {
        global $DB;

        $this->require_own_transaction();

        $ticket = $this->make_ticket();

        $transaction = $DB->start_delegated_transaction();

        $event = \local_moodesk\event\ticket_created::create([
            'objectid' => $ticket->id,
            'userid'   => $ticket->userid,
            'context'  => \context_system::instance(),
            'other'    => [
                'categoryid'   => null,
                'departmentid' => null,
                'courseid'     => null,
                'priority'     => 2,
                'source'       => 0,
            ],
        ]);
        $event->trigger();

        // The plugin's own observers have already run at this point, still
        // inside the transaction — which is why any incident they record has to
        // be buffered rather than written.
        $this->assertTrue($DB->is_transaction_started());
        $this->assertTrue($event->is_dispatched());

        $transaction->allow_commit();
    }

    /**
     * Insert a bare ticket row directly, without going through the service —
     * these tests are about transaction mechanics, not about ticket creation.
     *
     * @return \stdClass The inserted row, with id.
     */
    private function make_ticket(): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $now  = time();

        $row               = new \stdClass();
        $row->subject      = 'probe';
        $row->userid       = $user->id;
        $row->createdby    = $user->id;
        $row->status       = 1;
        $row->priority     = 2;
        $row->timecreated  = $now;
        $row->timemodified = $now;
        $row->usermodified = $user->id;

        $row->id = $DB->insert_record('local_moodesk_ticket', $row);

        return $row;
    }
}
