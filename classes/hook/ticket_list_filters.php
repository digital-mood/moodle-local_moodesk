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
 * Lets a plugin add filters to the ticket list (agents only).
 *
 * Phases ({@see filter_extension}): params from
 * {@see \local_moodesk\output\ticket_list_query}, options from index.php, sql
 * from {@see \local_moodesk\repository\ticket_repository} for every list and
 * count query that takes the list's filters.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add filters (parameter, control, SQL condition) to the mooDesk ticket list.')]
#[\core\attribute\tags('local_moodesk')]
final class ticket_list_filters extends filter_extension {
}
