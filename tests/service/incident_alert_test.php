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
 * Tests for operational incident alerting (OBS-03).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\incident;
use local_moodesk\task\dispatch_incident_alerts;

/**
 * When an incident alerts, and when it stays quiet.
 *
 * Most of these drive should_alert() directly. It is a pure function on
 * purpose: the rule has four interacting clauses and a cooldown measured in
 * wall-clock time, and pinning that through the database would mean either
 * sleeping or mutating timestamps behind the code's back to test arithmetic
 * that has nothing to do with storage.
 *
 * @covers \local_moodesk\service\incident_service
 * @covers \local_moodesk\task\dispatch_incident_alerts
 */
final class incident_alert_test extends \advanced_testcase {
    /** Fixed "now" so cooldown arithmetic reads as arithmetic. */
    private const NOW = 1000000;

    /**
     * Reset config and the deferral buffer between cases.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        incident_service::reset_deferred_for_testing();
    }

    /**
     * Static state outlives the per-test database reset.
     */
    protected function tearDown(): void {
        incident_service::reset_deferred_for_testing();
        parent::tearDown();
    }

    /**
     * Build an incident row in whatever state a case needs.
     *
     * @param array $overrides Fields to override on the default row.
     * @return \stdClass
     */
    private function incident(array $overrides = []): \stdClass {
        return (object) array_merge([
            'id'              => 1,
            'source'          => incident::SOURCE_UI,
            'code'            => 'webhook.delivery.abandoned',
            'severity'        => incident::SEVERITY_ERROR,
            'status'          => incident::STATUS_OPEN,
            'message'         => 'Connection refused',
            'occurrences'     => 1,
            'timefirstseen'   => self::NOW - 60,
            'timelastseen'    => self::NOW,
            'timelastalerted' => null,
            'severityalerted' => null,
        ], $overrides);
    }

    /**
     * Evaluate the rule with the default threshold and a one-hour cooldown.
     *
     * @param \stdClass $incident
     * @param int       $now
     * @return bool
     */
    private function should_alert(\stdClass $incident, int $now = self::NOW): bool {
        return incident_service::should_alert(
            $incident,
            incident::DEFAULT_ALERT_THRESHOLD,
            incident::DEFAULT_ALERT_COOLDOWN_SECONDS,
            $now
        );
    }

    /**
     * Run the alert task, swallowing its trace output.
     *
     * The task reports through mtrace() by design — it runs under cron — and
     * PHPUnit flags a test that lets output escape as risky.
     *
     * @param \core\task\scheduled_task|null $task Task to run; the real one by default.
     */
    private function run_task(?\core\task\scheduled_task $task = null): void {
        ob_start();
        ($task ?? new dispatch_incident_alerts())->execute();
        ob_end_clean();
    }

    /*
     * -------------------------------------------------------------------------
     * The rule.
     * -------------------------------------------------------------------------
     */

    /**
     * A new incident at or above the threshold alerts.
     */
    public function test_a_new_error_alerts(): void {
        $this->assertTrue($this->should_alert($this->incident()));
    }

    /**
     * A warning does not alert under the default threshold: it describes
     * degradation the system worked around.
     */
    public function test_a_warning_does_not_alert_by_default(): void {
        $this->assertFalse($this->should_alert($this->incident(['severity' => incident::SEVERITY_WARNING])));
    }

    /**
     * Lowering the threshold lets warnings through, for an operator who wants
     * everything.
     */
    public function test_lowering_the_threshold_lets_warnings_alert(): void {
        $alerts = incident_service::should_alert(
            $this->incident(['severity' => incident::SEVERITY_WARNING]),
            incident::SEVERITY_WARNING,
            incident::DEFAULT_ALERT_COOLDOWN_SECONDS,
            self::NOW
        );

        $this->assertTrue($alerts);
    }

    /**
     * Inside the cooldown, a repeat stays quiet. This is what stops a failure
     * recurring on every cron tick from flooding an inbox.
     */
    public function test_a_repeat_inside_the_cooldown_stays_quiet(): void {
        $incident = $this->incident([
            'timelastalerted' => self::NOW - 600,
            'severityalerted' => incident::SEVERITY_ERROR,
        ]);

        $this->assertFalse($this->should_alert($incident));
    }

    /**
     * Once the cooldown has elapsed, the same incident alerts again — it is
     * still happening, and the operator has not said otherwise.
     */
    public function test_a_repeat_after_the_cooldown_alerts_again(): void {
        $incident = $this->incident([
            'timelastalerted' => self::NOW - incident::DEFAULT_ALERT_COOLDOWN_SECONDS - 1,
            'severityalerted' => incident::SEVERITY_ERROR,
        ]);

        $this->assertTrue($this->should_alert($incident));
    }

    /**
     * The boundary is inclusive: exactly one cooldown after the last alert is
     * long enough.
     */
    public function test_the_cooldown_boundary_is_inclusive(): void {
        $incident = $this->incident([
            'timelastalerted' => self::NOW - incident::DEFAULT_ALERT_COOLDOWN_SECONDS,
            'severityalerted' => incident::SEVERITY_ERROR,
        ]);

        $this->assertTrue($this->should_alert($incident));
    }

    /*
     * -------------------------------------------------------------------------
     * Acknowledged, and the critical bypass.
     * -------------------------------------------------------------------------
     */

    /**
     * Acknowledged silences alerts. The incident is still live and still
     * visible — silencing the alert is not the same as hiding the problem.
     */
    public function test_acknowledged_does_not_alert(): void {
        $incident = $this->incident(['status' => incident::STATUS_ACKNOWLEDGED]);

        $this->assertFalse($this->should_alert($incident));
    }

    /**
     * A critical escalation bypasses the cooldown: the situation got worse and
     * the operator needs to know now, not in fifty minutes.
     */
    public function test_a_critical_escalation_bypasses_the_cooldown(): void {
        $incident = $this->incident([
            'severity'        => incident::SEVERITY_CRITICAL,
            'timelastalerted' => self::NOW - 60,
            'severityalerted' => incident::SEVERITY_ERROR,
        ]);

        $this->assertTrue($this->should_alert($incident));
    }

    /**
     * A critical that has already been alerted *as* critical is back under the
     * cooldown. This is what keeps "critical bypasses the cooldown" from
     * meaning "critical alerts on every cron tick for as long as it lasts" —
     * the bypass is spent on the escalation.
     */
    public function test_a_steady_state_critical_respects_the_cooldown(): void {
        $incident = $this->incident([
            'severity'        => incident::SEVERITY_CRITICAL,
            'timelastalerted' => self::NOW - 60,
            'severityalerted' => incident::SEVERITY_CRITICAL,
        ]);

        $this->assertFalse($this->should_alert($incident));
    }

    /**
     * Acknowledged beats the critical bypass. An operator who said they are on
     * it does not need telling again, however bad it got — this is the one
     * clause nothing overrides.
     */
    public function test_acknowledged_beats_a_critical_escalation(): void {
        $incident = $this->incident([
            'severity'        => incident::SEVERITY_CRITICAL,
            'status'          => incident::STATUS_ACKNOWLEDGED,
            'timelastalerted' => self::NOW - 60,
            'severityalerted' => incident::SEVERITY_ERROR,
        ]);

        $this->assertFalse($this->should_alert($incident));
    }

    /**
     * A severity that dropped back does not re-alert — the comparison is
     * against what was alerted, not merely different from it.
     */
    public function test_a_lower_severity_than_alerted_does_not_bypass(): void {
        $incident = $this->incident([
            'severity'        => incident::SEVERITY_ERROR,
            'timelastalerted' => self::NOW - 60,
            'severityalerted' => incident::SEVERITY_CRITICAL,
        ]);

        $this->assertFalse($this->should_alert($incident));
    }

    /*
     * -------------------------------------------------------------------------
     * Settings.
     * -------------------------------------------------------------------------
     */

    /**
     * Unset settings fall back to the documented defaults rather than to zero,
     * which for a cooldown would mean no throttle at all.
     */
    public function test_unset_settings_fall_back_to_defaults(): void {
        $this->assertSame(incident::DEFAULT_ALERT_THRESHOLD, incident_service::alert_threshold());
        $this->assertSame(
            incident::DEFAULT_ALERT_COOLDOWN_SECONDS,
            incident_service::alert_cooldown_seconds()
        );
    }

    /**
     * Configured settings are honoured, including an explicit zero cooldown —
     * an operator who wants every occurrence is entitled to it.
     */
    public function test_configured_settings_are_honoured(): void {
        set_config('incident_alert_severity', incident::SEVERITY_CRITICAL, 'local_moodesk');
        set_config('incident_alert_cooldown', 5, 'local_moodesk');

        $this->assertSame(incident::SEVERITY_CRITICAL, incident_service::alert_threshold());
        $this->assertSame(300, incident_service::alert_cooldown_seconds());

        set_config('incident_alert_cooldown', 0, 'local_moodesk');
        $this->assertSame(0, incident_service::alert_cooldown_seconds());
    }

    /**
     * A nonsense threshold falls back rather than silencing everything — a
     * typo in a setting must not turn alerting off.
     */
    public function test_a_nonsense_threshold_falls_back(): void {
        set_config('incident_alert_severity', 'catastrophic', 'local_moodesk');

        $this->assertSame(incident::DEFAULT_ALERT_THRESHOLD, incident_service::alert_threshold());
    }

    /*
     * -------------------------------------------------------------------------
     * End to end, through the task.
     * -------------------------------------------------------------------------
     */

    /**
     * A recorded failure produces one message to the site administrators, and
     * a second run stays quiet because the cooldown is now in force.
     */
    public function test_the_task_alerts_once_then_respects_the_cooldown(): void {
        $svc = incident_service::create();
        $svc->record(
            incident::SOURCE_UI,
            'webhook.delivery.abandoned',
            incident::SEVERITY_ERROR,
            'Connection refused'
        );
        incident_service::flush_deferred();

        $sink = $this->redirectMessages();
        $this->run_task();
        $messages = $sink->get_messages();

        $this->assertGreaterThanOrEqual(1, count($messages));
        $this->assertSame('operational_alert', $messages[0]->eventtype);
        $this->assertStringContainsString('mooDesk', $messages[0]->subject);

        // Second run, nothing new: silence.
        $sink->close();
        $sink = $this->redirectMessages();
        $this->run_task();

        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    /**
     * Alerting stamps the severity it alerted at, which is what lets a later
     * escalation bypass the cooldown.
     */
    public function test_alerting_stamps_the_severity_it_alerted_at(): void {
        global $DB;

        $svc = incident_service::create();
        $id  = $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR, 'x');
        incident_service::flush_deferred();

        $sink = $this->redirectMessages();
        $this->run_task();
        $sink->close();

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertSame(incident::SEVERITY_ERROR, $row->severityalerted);
        $this->assertNotNull($row->timelastalerted);
    }

    /**
     * An acknowledged incident produces no message even through the task, and
     * is left unstamped — acknowledging is not the same as alerting.
     */
    public function test_the_task_skips_acknowledged_incidents(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $svc  = incident_service::create();

        $id = $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_CRITICAL, 'x');
        incident_service::flush_deferred();
        $svc->acknowledge($id, (int) $user->id);

        $sink = $this->redirectMessages();
        $this->run_task();

        $this->assertCount(0, $sink->get_messages());
        $sink->close();

        $this->assertNull($DB->get_field('local_moodesk_incident', 'timelastalerted', ['id' => $id]));
    }

    /**
     * An incident that has fallen out of the active window is history, not a
     * live problem, and does not alert.
     */
    public function test_the_task_skips_incidents_outside_the_active_window(): void {
        global $DB;

        $svc = incident_service::create();
        $id  = $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR, 'x');
        incident_service::flush_deferred();

        $DB->set_field('local_moodesk_incident', 'timelastseen', time() - (25 * 3600), ['id' => $id]);

        $sink = $this->redirectMessages();
        $this->run_task();

        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    /**
     * With nothing wrong, the task sends nothing. An alerting system that
     * chatters when the site is healthy teaches operators to filter it away.
     */
    public function test_a_healthy_site_sends_no_alerts(): void {
        $sink = $this->redirectMessages();
        $this->run_task();

        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    /*
     * -------------------------------------------------------------------------
     * Delivery failure.
     *
     * These drive the production path: real pending_alerts(), real
     * notify_operational_incident(), real send(), real return check, real
     * conditional stamping in the task. Only the final hand-off to Moodle's
     * messaging stack is substituted, because arranging a genuine refusal from
     * it means reaching into processor configuration a test has no business
     * touching — and the point under test is what this plugin does with the
     * answer, not how the answer is produced.
     * -------------------------------------------------------------------------
     */

    /**
     * `message_send()` reporting refusal by returning false is treated as a
     * failure, not as a delivery. Ignoring that return was the defect: the
     * caller believed the alert went out, stamped the incident, and silenced it
     * for a whole cooldown having told nobody.
     */
    public function test_a_refused_message_is_treated_as_a_failure(): void {
        $service = $this->alert_service_returning(false);

        $this->expectException(\moodle_exception::class);
        $service->notify_operational_incident($this->stored_incident());
    }

    /**
     * The whole way through: a refused message leaves the incident unstamped
     * and still pending, so the next cron run tries again.
     */
    public function test_a_refused_message_leaves_the_incident_pending(): void {
        global $DB;

        $id = $this->record_and_settle();

        $this->run_task($this->task_sending_through($this->alert_service_returning(false)));

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertNull($row->timelastalerted, 'A refused message must not stamp the incident.');
        $this->assertNull($row->severityalerted);

        $this->assertCount(1, incident_service::create()->pending_alerts());
    }

    /**
     * A throw from the messaging stack is a different origin with the same
     * consequence, and is covered separately so a change to one path cannot
     * silently take the other with it.
     */
    public function test_a_throwing_send_leaves_the_incident_pending(): void {
        global $DB;

        $id = $this->record_and_settle();

        $this->run_task($this->task_sending_through($this->alert_service_that_throws()));

        $this->assertNull($DB->get_field('local_moodesk_incident', 'timelastalerted', ['id' => $id]));
        $this->assertCount(1, incident_service::create()->pending_alerts());
    }

    /**
     * A message that is accepted stamps the incident — the positive control for
     * the two cases above, so they cannot pass because nothing was attempted.
     */
    public function test_an_accepted_message_stamps_the_incident(): void {
        global $DB;

        $id = $this->record_and_settle();

        $this->run_task($this->task_sending_through($this->alert_service_returning(1)));

        $row = $DB->get_record('local_moodesk_incident', ['id' => $id]);
        $this->assertNotNull($row->timelastalerted);
        $this->assertSame(incident::SEVERITY_ERROR, $row->severityalerted);
    }

    /*
     * -------------------------------------------------------------------------
     * Partial delivery across several recipients.
     * -------------------------------------------------------------------------
     */

    /**
     * With two recipients and the second refusing, every recipient is still
     * attempted, and the incident is left unstamped.
     *
     * The accepted trade is a possible duplicate: the first operator was told,
     * and will be told again when the next run retries. That is deliberately
     * preferred over the alternative, which is a live incident going quiet for
     * a cooldown because one of two mailboxes was unreachable. Recorded in
     * docs/observability.md as a known limit rather than solved with
     * per-recipient state.
     */
    public function test_a_partial_delivery_does_not_stamp_the_incident(): void {
        global $DB;

        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        set_config('email_alert_recipients', $one->id . ',' . $two->id, 'local_moodesk');

        $id = $this->record_and_settle();

        $service = $this->alert_service_failing_after(1);
        $this->run_task($this->task_sending_through($service));

        // Both were attempted, not just the one before the failure.
        $this->assertSame(2, $service->attempts);

        // And nothing is stamped, so the next run will tell them both again.
        $this->assertNull($DB->get_field('local_moodesk_incident', 'timelastalerted', ['id' => $id]));
        $this->assertCount(1, incident_service::create()->pending_alerts());
    }

    /*
     * -------------------------------------------------------------------------
     * Helpers.
     * -------------------------------------------------------------------------
     */

    /**
     * Record an incident and settle it to storage.
     *
     * @return int Incident id.
     */
    private function record_and_settle(): int {
        $id = incident_service::create()->record(
            incident::SOURCE_OBSERVER,
            'observer.ticket_created.notify_failed',
            incident::SEVERITY_ERROR,
            'could not notify'
        );
        incident_service::flush_deferred();

        return $id;
    }

    /**
     * The incident row as stored, for driving the service directly.
     *
     * @return \stdClass
     */
    private function stored_incident(): \stdClass {
        global $DB;

        $this->record_and_settle();

        return $DB->get_record('local_moodesk_incident', ['code' => 'observer.ticket_created.notify_failed']);
    }

    /**
     * An alert service whose hand-off to Moodle's messaging returns a fixed
     * value. Everything above that — recipients, message, return check — is the
     * production implementation.
     *
     * @param mixed $result What the messaging stack answers.
     * @return email_alert_service
     */
    private function alert_service_returning($result): email_alert_service {
        return new class ($result) extends email_alert_service {
            /** @var mixed The scripted answer. */
            private $result;

            /**
             * Hold the scripted answer.
             *
             * @param mixed $result
             */
            public function __construct($result) {
                $this->result = $result;
            }

            /**
             * Answer as scripted instead of reaching the messaging stack.
             *
             * @param \core\message\message $message
             * @return mixed
             */
            protected function deliver(\core\message\message $message) {
                return $this->result;
            }
        };
    }

    /**
     * An alert service whose hand-off throws, as a mail transport can.
     *
     * @return email_alert_service
     */
    private function alert_service_that_throws(): email_alert_service {
        return new class extends email_alert_service {
            /**
             * Skip the parent constructor; nothing here needs it.
             */
            public function __construct() {
            }

            /**
             * Fail the way a broken transport would.
             *
             * @param \core\message\message $message
             * @return mixed
             */
            protected function deliver(\core\message\message $message) {
                throw new \moodle_exception('error', 'moodle', '', null, 'simulated transport failure');
            }
        };
    }

    /**
     * An alert service that accepts the first $accepted messages and refuses
     * everything after, counting attempts.
     *
     * @param int $accepted How many messages are accepted before refusing.
     * @return email_alert_service
     */
    private function alert_service_failing_after(int $accepted): email_alert_service {
        return new class ($accepted) extends email_alert_service {
            /** @var int How many messages are accepted before refusing. */
            private int $accepted;

            /** @var int How many messages were handed over in total. */
            public int $attempts = 0;

            /**
             * Hold the acceptance budget.
             *
             * @param int $accepted
             */
            public function __construct(int $accepted) {
                $this->accepted = $accepted;
            }

            /**
             * Accept, then refuse, counting either way.
             *
             * @param \core\message\message $message
             * @return mixed
             */
            protected function deliver(\core\message\message $message) {
                $this->attempts++;
                return $this->attempts <= $this->accepted ? 1 : false;
            }
        };
    }

    /**
     * The real task, sending through a given channel.
     *
     * @param email_alert_service $service
     * @return dispatch_incident_alerts
     */
    private function task_sending_through(email_alert_service $service): dispatch_incident_alerts {
        return new class ($service) extends dispatch_incident_alerts {
            /** @var email_alert_service The channel this run sends through. */
            private email_alert_service $service;

            /**
             * Hold the channel to send through.
             *
             * @param email_alert_service $service
             */
            public function __construct(email_alert_service $service) {
                $this->service = $service;
            }

            /**
             * Send through the injected channel. execute() is untouched.
             *
             * @return email_alert_service
             */
            protected function alert_service(): email_alert_service {
                return $this->service;
            }
        };
    }
}
