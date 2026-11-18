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
 * Plugin version metadata.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_moodesk';
$plugin->version   = 2026111800;         // YYYYMMDDXX — bump on every release.
$plugin->requires  = 2024100700;         // Moodle 4.5 LTS minimum (2024-10-07); 4.1–4.4 are EOL.
$plugin->maturity  = MATURITY_STABLE;
$plugin->supported = [405, 502];        // Moodle 4.5 LTS to 5.2, the range CI tests.
$plugin->release   = '3.0.4';            // The missing Dashboard metrics cache name string; see CHANGELOG.
