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
 * Drops operational incidents once they are past the retention period.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\task;

use local_moodesk\repository\incident_repository;
use local_moodesk\service\incident_service;

/**
 * Data-retention sweep over local_moodesk_incident.
 *
 * The incident table is fed by absorbed failures, so it only ever grows, and
 * it grows fastest on exactly the sites that are least well looked after. This
 * bounds it in time.
 *
 * What it deletes is decided by `timelastseen` alone — see
 * {@see incident_repository::purge_older_than()} for why status is not part of
 * the predicate. Deleting a row does not lose the failure: if the same class
 * of failure happens again the fingerprint simply misses, and the next
 * occurrence inserts a fresh row with a fresh `timefirstseen`. That is the
 * intended reading. An outage that returns after a quarter of silence is a new
 * incident, not the continuation of an old one.
 *
 * Runs on every edition, like the alert dispatcher: observability is
 * production readiness, not a licensed feature.
 */
class purge_incidents extends \core\task\scheduled_task {
    /**
     * Human-readable name shown in Moodle's task admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purge_incidents', 'local_moodesk');
    }

    /**
     * Delete every incident past the configured retention period.
     *
     * @return void
     */
    public function execute(): void {
        $cutoff  = time() - incident_service::retention_seconds();
        $deleted = (new incident_repository())->purge_older_than($cutoff);

        if ($deleted > 0) {
            mtrace("local_moodesk: purged $deleted incident(s) past the retention period");
        }
    }
}
