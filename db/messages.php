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
 * Message provider declaration.
 *
 * Declares the single 'ticket_notification' provider used by observer.php.
 * Users can control delivery via Moodle's notification preferences UI.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [

    // Covers all three notification types: new unassigned ticket (to agents),
    // agent replied (to requester), ticket assigned (to new assignee). Phase 2:
    // split into granular providers if users need independent control per type.
    // In Moodle 4.x the defaults array is keyed by processor name (string),
    // not by the removed MESSAGE_OUTPUT_* constants.
    'ticket_notification' => [
        'defaults' => [
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // Operator alert for an operational incident — a failure mooDesk absorbed,
    // under any registered incident source. Deliberately its own provider: an
    // operator silencing a narrower alert (local_moodeskpro's
    // email_ingestion_alert, which until 3.0.1 was declared here) must not
    // thereby silence "a scheduled task keeps failing".
    'operational_alert' => [
        'defaults' => [
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

];
