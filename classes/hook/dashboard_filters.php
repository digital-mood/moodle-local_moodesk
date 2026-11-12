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

namespace local_moodesk\hook;

/**
 * Lets a plugin add scope filters to the dashboard.
 *
 * Phases ({@see filter_extension}): params and options from dashboard.php, sql
 * from {@see \local_moodesk\repository\dashboard_repository} for every metric
 * that takes the dashboard's scope. The values travel in the
 * {@see \local_moodesk\domain\dashboard_filter} and are part of the dashboard
 * cache key.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add scope filters (parameter, control, SQL condition) to the mooDesk dashboard.')]
#[\core\attribute\tags('local_moodesk')]
final class dashboard_filters extends filter_extension {
}
