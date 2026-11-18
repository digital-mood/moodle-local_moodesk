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
 * Every cache definition of local_moodesk has its name string.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * The cache administration page labels each definition with
 * `cachedef_<name>`. A definition without it shows `[[cachedef_…]]` and, with
 * DEVELOPER debugging, an "Invalid get_string() identifier" notice:
 * dashboard_metrics went without one until this fix.
 *
 * @coversNothing
 */
final class cache_definitions_strings_test extends \advanced_testcase {
    /**
     * Each definition declared in db/caches.php has its string, in every
     * language the plugin ships.
     */
    public function test_every_definition_has_its_name_string(): void {
        $dir = \core_component::get_component_directory('local_moodesk');

        $definitions = [];
        require($dir . '/db/caches.php');
        $this->assertNotEmpty($definitions);

        $langs = array_map('basename', glob($dir . '/lang/*', GLOB_ONLYDIR));
        foreach (array_keys($definitions) as $name) {
            $this->assertTrue(
                get_string_manager()->string_exists('cachedef_' . $name, 'local_moodesk'),
                "cachedef_{$name} is missing"
            );
            foreach ($langs as $lang) {
                $string = [];
                include($dir . "/lang/{$lang}/local_moodesk.php");
                $this->assertArrayHasKey('cachedef_' . $name, $string, "{$lang}: cachedef_{$name}");
            }
        }
    }
}
