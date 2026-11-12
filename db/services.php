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
 * The base's session endpoints. The REST API is in local_moodeskpro.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // UI-only endpoint, same footing as the one above: called over AJAX from
    // the New Ticket form with a session, so it stays out of the mooDesk API
    // service and is not gated on api_access. Its gate is the knowledge base's
    // own — :viewkb and kb_access_policy — not the form's :submit, because what
    // it returns is the same set of published articles kb.php shows.
    'local_moodesk_search_kb_articles' => [
        'classname'    => 'local_moodesk\external\search_kb_articles',
        'methodname'   => 'execute',
        'description'  => 'Suggest published knowledge base articles for a ticket subject, for the New Ticket form.',
        'type'         => 'read',
        'capabilities' => 'local/moodesk:viewkb',
        'ajax'         => true,
    ],

];
