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
 * Event observer registrations.
 *
 * Each observer entry maps an event class to a static callback on
 * \local_moodesk\observer. The observer class sends Moodle messages
 * based on current plugin settings.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [

    // Event ticket_created → notify all agents when the ticket is unassigned.
    // (gated on notify_agents_new setting).
    [
        'eventname' => '\local_moodesk\event\ticket_created',
        'callback'  => '\local_moodesk\observer::ticket_created',
    ],

    // Event ticket_replied → notify requester when an agent posts a public reply.
    // (gated on notify_requester_reply setting).
    [
        'eventname' => '\local_moodesk\event\ticket_replied',
        'callback'  => '\local_moodesk\observer::ticket_replied',
    ],

    // Event ticket_assigned → notify the newly assigned agent.
    // (gated on notify_assignee_assign setting).
    [
        'eventname' => '\local_moodesk\event\ticket_assigned',
        'callback'  => '\local_moodesk\observer::ticket_assigned',
    ],

    // Event ticket_sla_breached → notify assignee (or all agents if unassigned).
    // (gated on notify_sla_breach setting).
    [
        'eventname' => '\local_moodesk\event\ticket_sla_breached',
        'callback'  => '\local_moodesk\observer::ticket_sla_breached',
    ],

];
