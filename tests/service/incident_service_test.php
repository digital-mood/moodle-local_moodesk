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
 * Tests for the operational incident service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\incident;
use local_moodesk\repository\incident_repository;

/**
 * Incident service tests.
 *
 * Two properties carry most of the weight here: recording the same failure
 * repeatedly must collapse onto one row, and recording must never be able to
 * throw at its caller — every call site is code that already decided the
 * failure must not break the operation the user asked for.
 *
 * @covers \local_moodesk\service\incident_service
 * @covers \local_moodesk\domain\incident
 */
final class incident_service_test extends \advanced_testcase {
    /**
     * Every case writes to the incident table.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Record one failure and read the row back.
     */
    public function test_records_a_first_occurrence(): void {
        global $DB;

        $id = incident_service::create()->record(
            incident::SOURCE_OBSERVER,
            'observer.ticket_created.notify_failed',
            incident::SEVERITY_ERROR,
            'Could not notify agents'
        );

        $this->assertGreaterThan(0, $id);

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::SOURCE_OBSERVER, $row->source);
        $this->assertSame('observer.ticket_created.notify_failed', $row->code);
        $this->assertSame(incident::SEVERITY_ERROR, $row->severity);
        $this->assertSame('Could not notify agents', $row->message);
        $this->assertSame(1, (int) $row->occurrences);
        $this->assertSame(incident::STATUS_OPEN, $row->status);
        $this->assertSame((int) $row->timefirstseen, (int) $row->timelastseen);
    }

    /**
     * Ten identical failures are one incident with a counter, not ten rows.
     * This is what keeps the table bounded when an endpoint dies and every cron
     * tick fails against it.
     */
    public function test_repeated_failures_collapse_onto_one_row(): void {
        global $DB;

        $svc = incident_service::create();
        for ($i = 0; $i < 10; $i++) {
            $svc->record(
                incident::SOURCE_UI,
                'webhook.delivery.failed',
                incident::SEVERITY_ERROR,
                'Connection refused'
            );
        }

        $rows = $DB->get_records('local_moodesk_incident');
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertSame(10, (int) $row->occurrences);
    }

    /**
     * The first-seen stamp survives later occurrences — it is the answer to
     * "since when has this been happening".
     */
    public function test_first_seen_is_not_overwritten(): void {
        global $DB;

        $svc = incident_service::create();
        $id  = $svc->record(
            incident::SOURCE_UI,
            'webhook.delivery.failed',
            incident::SEVERITY_ERROR,
            'first'
        );

        $first = (int) $DB->get_field('local_moodesk_incident', 'timefirstseen', ['id' => $id]);

        // Push the row into the past so a later occurrence has something to
        // overwrite if the code were wrong.
        $DB->set_field('local_moodesk_incident', 'timefirstseen', $first - 3600, ['id' => $id]);
        $DB->set_field('local_moodesk_incident', 'timelastseen', $first - 3600, ['id' => $id]);

        $svc->record(incident::SOURCE_UI, 'webhook.delivery.failed', incident::SEVERITY_ERROR, 'second');

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame($first - 3600, (int) $row->timefirstseen);
        $this->assertGreaterThan((int) $row->timefirstseen, (int) $row->timelastseen);
        // The newest detail is what an operator sees.
        $this->assertSame('second', $row->message);
    }

    /**
     * Different sources, codes or scope keys are different incidents.
     */
    public function test_distinct_failures_are_distinct_rows(): void {
        global $DB;

        $svc = incident_service::create();
        $svc->record(incident::SOURCE_UI, 'webhook.delivery.failed', incident::SEVERITY_ERROR, 'a');
        $svc->record(incident::SOURCE_UI, 'webhook.enqueue.failed', incident::SEVERITY_ERROR, 'b');
        $svc->record(incident::SOURCE_OBSERVER, 'webhook.delivery.failed', incident::SEVERITY_ERROR, 'c');
        $svc->record(incident::SOURCE_UI, 'webhook.delivery.failed', incident::SEVERITY_ERROR, 'd', [], '7');

        $this->assertSame(4, $DB->count_records('local_moodesk_incident'));
    }

    /**
     * Severity is raised but never lowered while an incident is alive: the
     * source that failed critically once is still the thing that failed
     * critically.
     */
    public function test_severity_escalates_but_does_not_decay(): void {
        global $DB;

        $svc = incident_service::create();
        $id  = $svc->record(incident::SOURCE_KNOWLEDGE_BASE, 'x.y.z', incident::SEVERITY_WARNING, 'w');

        $svc->record(incident::SOURCE_KNOWLEDGE_BASE, 'x.y.z', incident::SEVERITY_CRITICAL, 'c');
        $this->assertSame(
            incident::SEVERITY_CRITICAL,
            $DB->get_field('local_moodesk_incident', 'severity', ['id' => $id])
        );

        $svc->record(incident::SOURCE_KNOWLEDGE_BASE, 'x.y.z', incident::SEVERITY_WARNING, 'w again');
        $this->assertSame(
            incident::SEVERITY_CRITICAL,
            $DB->get_field('local_moodesk_incident', 'severity', ['id' => $id])
        );
    }

    /**
     * A failure that comes back after somebody closed it reopens the same row.
     * Splitting it would lose the original first-seen stamp, which is the one
     * number that says "this has been going on for a week".
     */
    public function test_reappearance_reopens_the_resolved_row(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $svc  = incident_service::create();

        $id = $svc->record(incident::SOURCE_SCHEDULED_TASK, 'task.sla.failed', incident::SEVERITY_ERROR, 'boom');
        $svc->resolve($id, (int) $user->id);

        $this->assertSame(
            incident::STATUS_RESOLVED,
            $DB->get_field('local_moodesk_incident', 'status', ['id' => $id])
        );

        $reid = $svc->record(incident::SOURCE_SCHEDULED_TASK, 'task.sla.failed', incident::SEVERITY_ERROR, 'boom');

        $this->assertSame($id, $reid);
        $this->assertSame(1, $DB->count_records('local_moodesk_incident'));

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::STATUS_OPEN, $row->status);
        $this->assertNull($row->resolvedby);
        $this->assertNull($row->timeresolved);
    }

    /**
     * Acknowledged means "I am on it", and it keeps meaning that while the
     * failure continues — a further occurrence must not silently undo it.
     */
    public function test_reappearance_leaves_acknowledged_alone(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $svc  = incident_service::create();

        $id = $svc->record(incident::SOURCE_UI, 'webhook.delivery.failed', incident::SEVERITY_ERROR, 'x');
        $svc->acknowledge($id, (int) $user->id);

        $svc->record(incident::SOURCE_UI, 'webhook.delivery.failed', incident::SEVERITY_ERROR, 'x');

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::STATUS_ACKNOWLEDGED, $row->status);
        $this->assertSame((int) $user->id, (int) $row->acknowledgedby);
        $this->assertSame(2, (int) $row->occurrences);
    }

    /*
     * -------------------------------------------------------------------------
     * Payload safety.
     * -------------------------------------------------------------------------
     */

    /**
     * Context keys outside the allowlist never reach the database. The
     * allowlist is the control; a denylist would have to predict every field
     * name that might one day hold a secret.
     */
    public function test_context_keys_outside_the_allowlist_are_dropped(): void {
        global $DB;

        $id = incident_service::create()->record(
            incident::SOURCE_UI,
            'webhook.delivery.failed',
            incident::SEVERITY_ERROR,
            'nope',
            [
                'ticketid'      => 42,
                'http_status'   => 500,
                'password'      => 'hunter2',
                'token'         => 'abc123',
                'sender_email'  => 'someone@example.org',
                'raw_body'      => 'Dear support, my password is hunter2',
            ]
        );

        $context = $DB->get_field('local_moodesk_incident', 'context', ['id' => $id]);
        $decoded = json_decode($context, true);

        $this->assertSame(['ticketid' => 42, 'http_status' => 500], $decoded);
        $this->assertStringNotContainsString('hunter2', $context);
        $this->assertStringNotContainsString('example.org', $context);
    }

    /**
     * A non-scalar value is dropped even under an allowed key — a nested
     * structure is exactly how a payload or a user record would arrive wearing
     * an innocent name.
     */
    public function test_non_scalar_context_values_are_dropped(): void {
        $encoded = incident_service::encode_context([
            'ticketid' => ['nested' => 'structure'],
            'ruleid'   => 7,
        ]);

        $this->assertSame(['ruleid' => 7], json_decode($encoded, true));
    }

    /**
     * A context with nothing usable in it stores null rather than an empty
     * JSON object.
     */
    public function test_empty_context_stores_null(): void {
        $this->assertNull(incident_service::encode_context([]));
        $this->assertNull(incident_service::encode_context(['password' => 'x']));
    }

    /**
     * Secret-shaped text in a message is redacted. imap_client throws
     * `'IMAP command failed: ' . $line` with a raw server line attached, so a
     * message is never trusted verbatim.
     *
     * @dataProvider secret_message_provider
     * @param string $message The raw message a caller might hand over.
     * @param string $secret  The substring that must not survive.
     */
    public function test_secrets_are_redacted_from_messages(string $message, string $secret): void {
        $clean = incident_service::sanitise_message($message);

        $this->assertStringNotContainsString($secret, $clean);
        $this->assertStringContainsString('[redacted]', $clean);
    }

    /**
     * Messages whose secrets must not reach the database.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function secret_message_provider(): array {
        return [
            'imap login'    => ['IMAP command failed: LOGIN support@example.org s3cr3tpass', 's3cr3tpass'],
            'password kv'   => ['Connection failed, password=hunter2 rejected', 'hunter2'],
            'token colon'   => ['Refused: token: abc123def456', 'abc123def456'],
            'bearer header' => ['Sent Authorization: Bearer eyJhbGciOiJIUzI1NiJ9', 'eyJhbGciOiJIUzI1NiJ9'],
            'api key'       => ['Rejected api_key=live_9f8e7d6c', 'live_9f8e7d6c'],
            // Not a secret, but the payload policy forbids storing addresses,
            // and failure messages quote them freely.
            'email address' => ['Could not deliver to alice@example.org', 'alice@example.org'],
        ];
    }

    /**
     * A message longer than the column is cut, and redaction runs first so a
     * truncation can never leave the visible half of a token behind.
     */
    public function test_message_is_truncated_after_redaction(): void {
        $long  = 'password=supersecretvalue ' . str_repeat('x', 400);
        $clean = incident_service::sanitise_message($long);

        $this->assertLessThanOrEqual(incident::MESSAGE_MAX_LENGTH, \core_text::strlen($clean));
        $this->assertStringNotContainsString('supersecretvalue', $clean);
    }

    /**
     * An empty message still satisfies the NOT NULL column.
     */
    public function test_blank_message_becomes_a_placeholder(): void {
        $this->assertSame('-', incident_service::sanitise_message('   '));
    }

    /*
     * -------------------------------------------------------------------------
     * The invariant.
     * -------------------------------------------------------------------------
     */

    /**
     * A repository that throws on every call must not make record() throw.
     *
     * This is the guarantee the whole design rests on: every call site is code
     * that already decided the failure must not break the operation the user
     * asked for. Observability able to violate that is worse than none.
     */
    public function test_record_never_propagates_a_repository_failure(): void {
        $exploding = new class extends incident_repository {
            /**
             * Skip the parent constructor; nothing here touches the database.
             */
            public function __construct() {
            }

            /**
             * Fail the way a database outage would.
             *
             * @param \stdClass $incident
             * @return int
             */
            public function record_occurrence(\stdClass $incident): int {
                throw new \dml_exception('error', 'simulated database outage');
            }
        };

        $svc = new incident_service($exploding);

        // The documented last resort is a debugging() call, so one is expected
        // here.
        $id = $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR, 'x');
        $this->assertDebuggingCalled();

        $this->assertSame(0, $id);
    }

    /**
     * An unregistered source, since 3.0.2. Under PHPUnit it is a typo and
     * throws, so the test that wrote it fails.
     */
    public function test_an_unregistered_source_throws_under_phpunit(): void {
        $this->expectException(\coding_exception::class);
        incident_service::create()->record('not_a_source', 'a.b.c', incident::SEVERITY_ERROR, 'x');
    }

    /**
     * Anywhere else it must not reach the caller: an add-on older than the
     * health_sources hook records under sources it cannot declare. The row is
     * kept, a developer is told, and nothing shows it until a plugin declares
     * the source.
     */
    public function test_an_unregistered_source_is_stored_outside_phpunit(): void {
        global $DB;

        \local_moodesk\service\incident_source_registry::set_strict_for_testing(false);
        try {
            $id = incident_service::create()->record('not_a_source', 'a.b.c', incident::SEVERITY_ERROR, 'x');
        } finally {
            \local_moodesk\service\incident_source_registry::reset_for_testing();
        }
        $this->assertDebuggingCalled();

        $this->assertGreaterThan(0, $id);
        $this->assertSame('not_a_source', $DB->get_field('local_moodesk_incident', 'source', ['id' => $id]));
        $this->assertSame([], incident_service::create()->get_active());
    }

    /**
     * Likewise for an unknown severity: nothing is stored and nothing throws.
     */
    public function test_unknown_severity_is_swallowed(): void {
        global $DB;

        $id = incident_service::create()->record(incident::SOURCE_OBSERVER, 'a.b.c', 'catastrophic', 'x');
        $this->assertDebuggingCalled();

        $this->assertSame(0, $id);
        $this->assertSame(0, $DB->count_records('local_moodesk_incident'));
    }

    /*
     * -------------------------------------------------------------------------
     * Derived health state.
     * -------------------------------------------------------------------------
     */

    /**
     * An incident last seen outside the active window is history, not a current
     * problem. Nothing marks it — the state is read off the clock.
     */
    public function test_incident_outside_the_window_is_not_active(): void {
        global $DB;

        $svc = incident_service::create();
        $id  = $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR, 'x');

        $this->assertCount(1, $svc->get_active());

        $DB->set_field('local_moodesk_incident', 'timelastseen', time() - (25 * 3600), ['id' => $id]);

        $this->assertCount(0, $svc->get_active());
    }

    /**
     * Resolved incidents drop out of the active view even while still inside
     * the window: an administrator saying "handled" is exactly the signal that
     * it should stop being shown as live.
     */
    public function test_resolved_incident_is_not_active(): void {
        $user = $this->getDataGenerator()->create_user();
        $svc  = incident_service::create();

        $id = $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR, 'x');
        $this->assertCount(1, $svc->get_active());

        $svc->resolve($id, (int) $user->id);
        $this->assertCount(0, $svc->get_active());
    }

    /**
     * Acknowledged incidents stay visible — silencing the alert is not the same
     * as hiding the problem.
     */
    public function test_acknowledged_incident_is_still_active(): void {
        $user = $this->getDataGenerator()->create_user();
        $svc  = incident_service::create();

        $id = $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR, 'x');
        $svc->acknowledge($id, (int) $user->id);

        $this->assertCount(1, $svc->get_active());
    }

    /**
     * The active view can be narrowed to one source, and the per-source counts
     * back the health summary.
     */
    public function test_active_incidents_can_be_counted_per_source(): void {
        $svc = incident_service::create();
        $svc->record(incident::SOURCE_UI, 'w.1', incident::SEVERITY_ERROR, 'x');
        $svc->record(incident::SOURCE_UI, 'w.2', incident::SEVERITY_ERROR, 'x');
        $svc->record(incident::SOURCE_OBSERVER, 'o.1', incident::SEVERITY_WARNING, 'x');

        $this->assertCount(2, $svc->get_active(incident::SOURCE_UI));
        $this->assertSame(
            [incident::SOURCE_OBSERVER => 1, incident::SOURCE_UI => 2],
            $svc->count_active_by_source()
        );
    }

    /**
     * The window comes from configuration, and an unset or nonsensical setting
     * falls back to the 24-hour default rather than to zero — which would make
     * every incident look current forever.
     */
    public function test_active_window_falls_back_to_the_default(): void {
        $this->assertSame(
            incident::DEFAULT_ACTIVE_WINDOW_SECONDS,
            incident_service::active_window_seconds()
        );

        set_config('incident_active_window_hours', 0, 'local_moodesk');
        $this->assertSame(
            incident::DEFAULT_ACTIVE_WINDOW_SECONDS,
            incident_service::active_window_seconds()
        );

        set_config('incident_active_window_hours', 6, 'local_moodesk');
        $this->assertSame(6 * 3600, incident_service::active_window_seconds());
    }

    /**
     * Acknowledging or resolving something that is not there reports failure
     * rather than inventing a row.
     */
    public function test_status_change_on_a_missing_incident_returns_false(): void {
        $svc = incident_service::create();

        $this->assertFalse($svc->acknowledge(999999, 2));
        $this->assertFalse($svc->resolve(999999, 2));
    }
}
