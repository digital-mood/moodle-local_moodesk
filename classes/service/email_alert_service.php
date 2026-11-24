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
 * Operator alerts sent through the Moodle message API.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Sends operator-facing alerts via the Moodle message API, so recipients keep
 * control over delivery channels through their notification preferences. The
 * base sends one kind, operational incidents (provider operational_alert); a
 * plugin that extends this class sends its own under its own provider and
 * component.
 *
 * Recipients come from the `email_alert_recipients` setting (an
 * admin_setting_users list of user ids); when it is empty the alert falls back
 * to the site administrators.
 *
 * DELIVERY FAILURE IS REPORTED, NOT SWALLOWED. `message_send()` signals refusal
 * by returning false rather than by throwing, so its return is checked and a
 * refusal is raised. Whether that matters is the caller's call: the ingestion
 * paths still absorb it, because a notification failure must not turn a handled
 * email into a retryable error. The operational alert path does not, because
 * marking an incident alerted when nobody was told silences it for a whole
 * cooldown — see {@see \local_moodesk\task\dispatch_incident_alerts}.
 */
class email_alert_service {
    /**
     * Build the service.
     *
     * @return static
     */
    public static function create(): static {
        return new static();
    }

    /**
     * Notify the configured recipients that an operational incident is live.
     *
     * Rides its own message provider rather than the ingestion one, so an
     * operator can silence "an email could not be routed" without also
     * silencing "webhooks have stopped delivering". Whether this incident
     * *should* alert at all is decided upstream by incident_service, not here.
     *
     * The body carries only what the incident row already holds — which the
     * payload allowlist and the redactor have already scrubbed — so the alert
     * cannot leak what the record refused to store.
     *
     * @param \stdClass $incident The incident row.
     */
    public function notify_operational_incident(\stdClass $incident): void {
        // Upstream filters these out already; an incident whose source no
        // installed plugin declares has no label to send.
        if (!incident_source_registry::is_registered($incident->source)) {
            return;
        }

        $recipients = $this->resolve_recipients();
        if (!$recipients) {
            return;
        }

        $from    = \core_user::get_noreply_user();
        $subject = get_string('incident_alert_subject', 'local_moodesk', (object) [
            'severity' => get_string('incident_severity_' . $incident->severity, 'local_moodesk'),
            'source'   => incident_source_registry::label($incident->source),
        ]);
        $body = get_string('incident_alert_body', 'local_moodesk', (object) [
            'code'        => $incident->code,
            'message'     => $incident->message,
            'occurrences' => (int) $incident->occurrences,
            'firstseen'   => userdate((int) $incident->timefirstseen),
            'lastseen'    => userdate((int) $incident->timelastseen),
        ]);

        // Every recipient is attempted before anything is reported, so one
        // unreachable operator does not deprive the others of the alert. The
        // failure is raised afterwards, because the caller's decision — whether
        // to mark this incident alerted — turns on whether *all* the configured
        // recipients were told, not on whether the loop got started.
        $failures = [];

        foreach ($recipients as $userto) {
            try {
                $this->send($from, $userto, $subject, $body, 'operational_alert');
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }

        if ($failures) {
            throw new \moodle_exception(
                'error',
                'moodle',
                '',
                null,
                'local_moodesk: ' . count($failures) . ' of ' . count($recipients)
                . ' operational alert recipient(s) could not be notified: ' . implode('; ', $failures)
            );
        }
    }

    /**
     * Resolve the alert recipients: the configured users if any are set and
     * active, otherwise the site administrators.
     *
     * @return \stdClass[] Full user records, keyed arbitrarily.
     */
    protected function resolve_recipients(): array {
        global $DB;

        $configured = (string) get_config('local_moodesk', 'email_alert_recipients');
        $ids        = array_filter(array_map('intval', explode(',', $configured)));

        if ($ids) {
            $users = $DB->get_records_list('user', 'id', $ids, '', '*');
            $users = array_filter($users, static function (\stdClass $u): bool {
                return empty($u->deleted) && empty($u->suspended);
            });
            if ($users) {
                return $users;
            }
        }

        // Empty or no longer valid → fall back to the site administrators.
        return get_admins();
    }

    /**
     * Send one alert message. Mirrors the re-fetch done elsewhere so
     * message_send() has the full user record it needs to route on.
     *
     * The provider has no default. Until 3.0.1 it defaulted to the ingestion
     * alert, which now belongs to local_moodeskpro; local_moodeskpro 3.0.1
     * still calls with four arguments, and that call is refused here without
     * an exception, so an older add-on loses those alerts and nothing else.
     *
     * @param \stdClass   $from      Sender (noreply) user.
     * @param \stdClass   $to        Recipient user; only ->id is required.
     * @param string      $subject
     * @param string      $body      Plain-text body.
     * @param string|null $provider  Message provider to send under. Null only
     *                               from a caller written for 3.0.1.
     * @param string      $component The component that declares the provider.
     * @return bool True when the message went out; false when it was not sent
     *              (no provider, or a recipient who is gone or suspended).
     * @throws \moodle_exception When the message subsystem refuses the message.
     */
    protected function send(
        \stdClass $from,
        \stdClass $to,
        string $subject,
        string $body,
        ?string $provider = null,
        string $component = 'local_moodesk'
    ): bool {
        if ($provider === null) {
            debugging(
                'local_moodesk: email_alert_service::send() was called without a message provider, '
                . 'as the mooDesk add-on 3.0.1 does; the alert was not sent. Update the add-on to 3.0.2.',
                DEBUG_DEVELOPER
            );
            return false;
        }

        if (empty($to->id)) {
            return false;
        }

        $userto = \core_user::get_user((int) $to->id, '*', IGNORE_MISSING);
        if (!$userto || !empty($userto->deleted) || !empty($userto->suspended)) {
            return false;
        }

        $message                    = new \core\message\message();
        $message->component         = $component;
        $message->name              = $provider;
        $message->userfrom          = $from;
        $message->userto            = $userto;
        $message->subject           = $subject;
        $message->fullmessage       = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml   = text_to_html($body, false, false, true);
        $message->smallmessage      = $subject;
        $message->notification      = 1;
        $message->contexturl        = '';
        $message->contexturlname    = '';

        // Moodle reports refusal by returning false from message_send(), not
        // by throwing: a disabled provider and a processor that will not take
        // the message both come back that way. Ignoring the return let a caller
        // believe a notification went out when it never did, which for an
        // operational
        // alert means the incident gets marked alerted and goes quiet for a
        // whole cooldown having told nobody.
        if ($this->deliver($message) === false) {
            throw new \moodle_exception(
                'error',
                'moodle',
                '',
                null,
                'local_moodesk: message_send refused the ' . $provider . ' message for user ' . $userto->id
            );
        }
        return true;
    }

    /**
     * Hand a built message to Moodle's message subsystem.
     *
     * The single seam over `message_send()`, so a test can exercise everything
     * around it — recipient resolution, message construction, the return check
     * and the failure it raises — without needing to arrange a real refusal
     * from the messaging stack, which depends on processor configuration a test
     * has no business reaching into.
     *
     * @param \core\message\message $message
     * @return mixed The message id, or false when the subsystem refused it.
     */
    protected function deliver(\core\message\message $message) {
        return message_send($message);
    }
}
