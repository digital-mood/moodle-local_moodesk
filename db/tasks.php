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
 * Scheduled task definitions.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [

    // Auto-close tickets that have been in RESOLVED status for longer than
    // the configured threshold (autoclose_resolved_days setting).
    // Default schedule: once daily at 02:00 site time.
    [
        'classname' => '\local_moodesk\task\autoclose_resolved_tickets',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '2',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Evaluate open tickets against SLA thresholds and flag breaches.
    // Default schedule: every hour.
    [
        'classname' => '\local_moodesk\task\check_sla_breaches',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Operational incident alerts (OBS-03). Every 5 minutes: the trade is up to
    // one interval of latency in exchange for keeping message_send() — and the
    // SMTP conversation behind it — off the path of the request that hit the
    // failure. Registered enabled in every edition; observability is production
    // readiness, not a licensed feature, and an edition without webhooks or
    // ingestion simply never produces incidents from those sources.
    [
        'classname' => '\local_moodesk\task\dispatch_incident_alerts',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // Data-retention sweep for operational incidents (OBS-05). Daily, and an
    // hour after the merge-snapshot sweep so the two never contend for the
    // same cron slot. Retention is measured in days, so the hour it runs at is
    // immaterial; what matters is that it runs unattended, because the table
    // only grows on the sites nobody is watching. Enabled in every edition,
    // for the same reason the alert dispatcher is.
    [
        'classname' => '\local_moodesk\task\purge_incidents',
        'blocking'  => 0,
        'minute'    => '40',
        'hour'      => '5',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],

    // ROADMAP (Pro+):
    // - \local_moodesk\task\process_automation_rules  (every 15 min)
    // These task classes ship with their respective Pro features. Re-add the
    // entries here in the same upgrade that introduces the implementing class
    // — registering a task class that does not exist would leave Moodle's
    // scheduler holding a broken handle.

];
