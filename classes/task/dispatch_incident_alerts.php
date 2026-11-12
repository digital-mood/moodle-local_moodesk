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
 * Scheduled task: alert operators about live operational incidents.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\task;

use local_moodesk\service\email_alert_service;
use local_moodesk\service\incident_service;

/**
 * Sends the operator alert for incidents that are live and due one.
 *
 * WHY A TASK, NOT THE RECORDING PATH. Alerting from inside record() would put
 * message_send() — and behind it an SMTP conversation — on the path of a
 * failure that has already happened, inside a request the user is waiting on.
 * The one invariant this whole stream is built around is that observing a
 * failure cannot make the original operation worse, and a blocking mail server
 * is exactly how that promise breaks. Recording stays cheap and local; alerting
 * happens out of band.
 *
 * The cost is latency: an incident waits up to one cron interval for its alert.
 * For "webhooks stopped delivering" that is an acceptable trade against the
 * risk of a slow mail server holding a ticket page open.
 *
 * Not gated by edition — the same reasoning as the rest of the stream, and the
 * sources a given edition cannot use never produce incidents in the first
 * place. Never throws: alerting failures must not abort cron.
 */
class dispatch_incident_alerts extends \core\task\scheduled_task {
    /**
     * Human-readable name shown in Moodle's task admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_dispatch_incident_alerts', 'local_moodesk');
    }

    /**
     * Alert about every incident currently due one. Never throws.
     */
    public function execute(): void {
        $service = incident_service::create();
        $alerts  = $this->alert_service();

        $sent = 0;

        foreach ($service->pending_alerts() as $incident) {
            try {
                $alerts->notify_operational_incident($incident);

                // Stamped only after the send returned. A failed send that
                // still marked the incident alerted would silence it for a
                // whole cooldown over a mail server hiccup.
                $service->mark_alerted((int) $incident->id, $incident->severity);
                $sent++;
            } catch (\Throwable $e) {
                // Left unstamped on purpose, so the next run tries again. A
                // send that failed but stamped anyway would silence a live
                // incident for a whole cooldown over one mail server hiccup.
                //
                // Not recorded as an incident of its own: the alerting path
                // failing while alerting about a failure is the one place where
                // recording could feed itself. mtrace() is enough.
                mtrace(
                    'local_moodesk: could not alert about incident ' . $incident->id
                    . ': ' . $e->getMessage()
                );
            }
        }

        if ($sent > 0) {
            mtrace("local_moodesk: sent {$sent} operational incident alert(s).");
        }
    }

    /**
     * The alert channel this run sends through.
     *
     * A seam, so a test can drive the real execute() — the loop, the failure
     * handling and the conditional stamping — against a channel it controls.
     * Everything that decides whether an incident gets stamped stays under
     * test; only the messaging stack underneath is substituted.
     *
     * @return email_alert_service
     */
    protected function alert_service(): email_alert_service {
        return email_alert_service::create();
    }
}
