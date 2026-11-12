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
 * Every message provider of local_moodesk has its name string.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * The notification preference pages label each provider with
 * `messageprovider:<name>`. A provider without it shows `[[messageprovider:…]]`
 * and, with DEVELOPER debugging, an "Invalid get_string() identifier" notice:
 * operational_alert went without one from 2.17.0 to 3.0.1.
 *
 * @coversNothing
 */
final class message_providers_strings_test extends \advanced_testcase {
    /**
     * Each provider declared in db/messages.php has its string, in every
     * language the plugin ships.
     */
    public function test_every_provider_has_its_name_string(): void {
        $dir = \core_component::get_component_directory('local_moodesk');

        $messageproviders = [];
        require($dir . '/db/messages.php');
        $this->assertNotEmpty($messageproviders);

        $langs = array_map('basename', glob($dir . '/lang/*', GLOB_ONLYDIR));
        foreach (array_keys($messageproviders) as $name) {
            $this->assertTrue(
                get_string_manager()->string_exists('messageprovider:' . $name, 'local_moodesk'),
                "messageprovider:{$name} is missing"
            );
            foreach ($langs as $lang) {
                $string = [];
                include($dir . "/lang/{$lang}/local_moodesk.php");
                $this->assertArrayHasKey('messageprovider:' . $name, $string, "{$lang}: messageprovider:{$name}");
            }
        }
    }
}
