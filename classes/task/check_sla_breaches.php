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
 * Scheduled task: detect and flag SLA breaches on open tickets.
 *
 * Runs hourly. For each active ticket, evaluates the configured SLA thresholds
 * and keeps sla_breached in step with the verdict — setting it when a deadline
 * has passed, and clearing it when the ticket is no longer past one.
 *
 * The flag used to be write-once, which stopped being tenable when the
 * resolution clock learned to pause: a ticket that goes into pending has its
 * deadline pushed out, and a permanent flag would keep it red for the rest of
 * its life while the live list showed it green. So on an active ticket the flag
 * means "in breach now", and the reports that read it agree with the list.
 *
 * Terminal tickets are never revisited. Once resolved or closed, the flag stops
 * being current state and becomes the historical record of whether that ticket
 * was answered in time — which is what the reports need it to be.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\task;

use local_moodesk\domain\incident;
use local_moodesk\repository\ticket_repository;
use local_moodesk\service\edition_service;
use local_moodesk\service\incident_service;
use local_moodesk\service\sla_service;

/**
 * Evaluates open tickets against configured SLA thresholds and sets the
 * sla_breached flag on tickets that have exceeded their deadlines.
 */
class check_sla_breaches extends \core\task\scheduled_task {
    /** Maximum tickets evaluated per task run to prevent memory pressure. */
    const BATCH_SIZE = 500;

    /**
     * Return the human-readable task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_check_sla_breaches', 'local_moodesk');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        if (!edition_service::has_feature('sla')) {
            mtrace('local_moodesk SLA: feature not available in current edition. Skipping.');
            return;
        }

        global $DB;

        $ticketrepo = new ticket_repository();
        $slaservice = new sla_service();

        $now       = time();
        $adminid   = get_admin()->id;
        $evaluated = 0;
        $flagged   = 0;
        $cleared   = 0;
        $afterid   = 0;

        // Walk every active ticket, a page at a time. BATCH_SIZE caps how many
        // are held in memory at once, not how many the run covers: a fixed
        // ceiling would leave whatever falls past it permanently unevaluated.
        while (true) {
            $candidates = $ticketrepo->get_open_for_sla_check(self::BATCH_SIZE, $afterid);

            if (empty($candidates)) {
                break;
            }

            $afterid = (int) end($candidates)->id;

            foreach ($candidates as $ticket) {
                try {
                    $evaluated++;

                    $wasbreached = (int) $ticket->sla_breached === 1;
                    $isbreached  = $slaservice->is_breached($ticket, $now);

                    if ($wasbreached === $isbreached) {
                        continue;
                    }

                    $update               = new \stdClass();
                    $update->id           = (int) $ticket->id;
                    $update->sla_breached = $isbreached ? 1 : 0;
                    $update->timemodified = $now;
                    $update->usermodified = 0;

                    $transaction = $DB->start_delegated_transaction();
                    try {
                        $ticketrepo->update($update);
                        $transaction->allow_commit();
                    } catch (\Throwable $e) {
                        $transaction->rollback($e);
                    }

                    if (!$isbreached) {
                        // Dropping out of breach is not an event. Nothing happened
                        // to the ticket — the requester was handed the ball, and
                        // the deadline moved with them.
                        $cleared++;
                        continue;
                    }

                    $flagged++;

                    $event = \local_moodesk\event\ticket_sla_breached::create([
                        'objectid' => (int) $ticket->id,
                        'userid'   => $adminid,
                        'context'  => \context_system::instance(),
                        'other'    => [
                            'priority'   => (int) $ticket->priority,
                            'assigneeid' => (int) ($ticket->assigneeid ?? 0),
                        ],
                    ]);
                    $event->trigger();
                } catch (\Throwable $e) {
                    // Same reasoning as autoclose: skipping one ticket keeps the
                    // batch alive, but a breach that was never evaluated is an
                    // SLA the site believes it is meeting.
                    incident_service::create()->record(
                        incident::SOURCE_SCHEDULED_TASK,
                        'scheduled_task.check_sla.ticket_failed',
                        incident::SEVERITY_ERROR,
                        $e->getMessage(),
                        [
                            'taskname'        => 'check_sla_breaches',
                            'ticketid'        => (int) $ticket->id,
                            'exception_class' => get_class($e),
                        ]
                    );
                    mtrace("local_moodesk SLA: error evaluating ticket {$ticket->id}: " . $e->getMessage());
                }
            }
        }

        if ($evaluated === 0) {
            mtrace('local_moodesk SLA: no open tickets to evaluate.');
            return;
        }

        mtrace(
            "local_moodesk SLA: evaluated {$evaluated} ticket(s), "
                . "flagged {$flagged} as breached, cleared {$cleared}."
        );
    }
}
