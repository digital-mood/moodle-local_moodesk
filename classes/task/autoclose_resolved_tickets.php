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
 * Scheduled task: auto-close resolved tickets after N days.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\task;

use local_moodesk\domain\incident;
use local_moodesk\domain\ticket;
use local_moodesk\domain\ticket_history;
use local_moodesk\repository\ticket_repository;
use local_moodesk\repository\ticket_history_repository;
use local_moodesk\event\ticket_status_changed;
use local_moodesk\service\incident_service;

/**
 * Closes tickets that have been in STATUS_RESOLVED for more than
 * autoclose_resolved_days days without any requester action.
 *
 * The threshold is controlled by the plugin setting 'autoclose_resolved_days'.
 * When the value is 0 (or not set), the task is a no-op — auto-close is disabled.
 *
 * Each auto-closed ticket gets:
 *  - status updated to STATUS_CLOSED
 *  - timeclosed timestamp set
 *  - an audit history entry with userid = 0 (system action)
 *  - a ticket_status_changed event emitted
 *
 * The task processes tickets in batches of 200 to avoid memory pressure on
 * large installations.
 */
class autoclose_resolved_tickets extends \core\task\scheduled_task {
    /** Maximum tickets processed per task run. */
    const BATCH_SIZE = 200;

    /**
     * Return the human-readable task name shown in Moodle's task admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_autoclose_resolved', 'local_moodesk');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        $days = (int) get_config('local_moodesk', 'autoclose_resolved_days');

        if ($days <= 0) {
            mtrace('local_moodesk autoclose: disabled (autoclose_resolved_days = 0). Skipping.');
            return;
        }

        $threshold = time() - ($days * DAYSECS);

        mtrace("local_moodesk autoclose: closing tickets resolved before " . userdate($threshold) . " ({$days} days ago).");

        $ticketrepo  = new ticket_repository();
        $historyrepo = new ticket_history_repository();
        $context      = \context_system::instance();

        $candidates = $ticketrepo->get_resolved_before($threshold, self::BATCH_SIZE);

        if (empty($candidates)) {
            mtrace('local_moodesk autoclose: no tickets to close.');
            return;
        }

        global $DB;

        $now   = time();
        $count = 0;

        foreach ($candidates as $ticketobj) {
            $ticketid = (int) $ticketobj->id;

            try {
                $update               = new \stdClass();
                $update->id           = $ticketid;
                $update->status       = ticket::STATUS_CLOSED;
                $update->timeclosed   = $now;
                $update->timemodified = $now;
                $update->usermodified = 0; // 0 = system action.

                $entry              = new \stdClass();
                $entry->ticketid    = $ticketid;
                $entry->userid      = 0; // 0 = system action.
                $entry->action      = ticket_history::ACTION_STATUS_CHANGED;
                $entry->oldvalue    = json_encode(['status' => ticket::STATUS_RESOLVED]);
                $entry->newvalue    = json_encode(['status' => ticket::STATUS_CLOSED]);
                $entry->timecreated = $now;

                $transaction = $DB->start_delegated_transaction();
                try {
                    $ticketrepo->update($update);
                    $historyrepo->insert($entry);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e); // Throws — prevents post-commit code below.
                }

                ticket_status_changed::create([
                    'objectid' => $ticketid,
                    'userid'   => 0,
                    'context'  => $context,
                    'other'    => [
                        'oldstatus' => ticket::STATUS_RESOLVED,
                        'newstatus' => ticket::STATUS_CLOSED,
                    ],
                ])->trigger();

                $count++;
            } catch (\Throwable $e) {
                // Log and continue — one failed ticket must not abort the batch.
                // Continuing is right; being the only trace of it in a cron log
                // nobody reads is not.
                incident_service::create()->record(
                    incident::SOURCE_SCHEDULED_TASK,
                    'scheduled_task.autoclose.ticket_failed',
                    incident::SEVERITY_ERROR,
                    $e->getMessage(),
                    [
                        'taskname'        => 'autoclose_resolved_tickets',
                        'ticketid'        => (int) $ticketid,
                        'exception_class' => get_class($e),
                    ]
                );
                mtrace("local_moodesk autoclose: failed to close ticket {$ticketid}: " . $e->getMessage());
            }
        }

        mtrace("local_moodesk autoclose: closed {$count} ticket(s).");
    }
}
