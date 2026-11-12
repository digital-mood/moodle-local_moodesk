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
 * MUC cache definitions for local_moodesk.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [

    // Raw dashboard aggregates, keyed by a hash of the exact filter that
    // produced them (window, department, team, agent, requester) plus the
    // viewer's role and the active edition. Role and scope are baked into the
    // key, so two viewers with different visibility can never share an entry —
    // the cache cannot widen what a user is allowed to see.
    //
    // Only numbers are stored, never rendered strings: formatting depends on
    // the viewer's language, and caching translated output would serve one
    // user's locale to another. The service formats on every request.
    //
    // The short TTL is what makes this transparent: a dashboard is a live
    // operational panel, and a stale one is worse than a slow one.
    'dashboard_metrics' => [
        'mode'                    => cache_store::MODE_APPLICATION,
        'simplekeys'              => true,
        'simpledata'              => false,
        'ttl'                     => 90, // 90 seconds.
        'staticacceleration'      => true,
        'staticaccelerationsize'  => 4,
    ],

];
