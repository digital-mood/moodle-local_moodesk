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
 * Hook listener registrations.
 *
 * Registers the callback that injects the Help Desk node into Moodle's
 * primary navigation (the header bar that contains Home / Dashboard /
 * My courses / Site administration).
 *
 * This mechanism requires Moodle 4.3+ (hook system). On earlier versions
 * the file is silently ignored and no primary-nav node is added.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\navigation\primary_extend::class,
        'callback' => [\local_moodesk\hook_callbacks::class, 'extend_primary_navigation'],
        'priority' => 500,
    ],
];
