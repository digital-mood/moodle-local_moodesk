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
 * Tests for what the base plugin may contain.
 *
 * @package   local_moodesk
 * @category  test
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\policy;

/**
 * The base holds no licence code and never depends on the add-on.
 *
 * It ships on its own as the free Starter listing, so a licence check or a call
 * into local_moodeskpro here would be a bug that nothing else notices: on the
 * legs with both plugins it would simply work (docs/two-plugin-split-plan.md §3).
 *
 * @coversNothing
 */
final class package_boundary_test extends \basic_testcase {
    /**
     * Every PHP file of the base that ships and runs, tests left out.
     *
     * @return string[]
     */
    private function code_files(): array {
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/*.php');
        foreach (['classes', 'db', 'cli', 'admin'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    public function test_the_base_has_no_public_keys_and_no_licence_classes(): void {
        $root = dirname(__DIR__, 2);
        $this->assertDirectoryDoesNotExist($root . '/license_keys');
        $this->assertSame([], glob($root . '/classes/service/{license,revocation}*.php', GLOB_BRACE));
        $this->assertSame([], glob($root . '/classes/task/*license*.php'));
        $this->assertSame([], glob($root . '/classes/event/license*.php'));
    }

    public function test_the_base_never_references_a_class_of_the_add_on(): void {
        $found = [];
        foreach ($this->code_files() as $path) {
            // One exception, by name only: the 3.0.2 upgrade step hands the
            // message provider email_ingestion_alert over to the add-on, so it
            // reads whether the add-on is installed and copies to its names.
            // A bare component string, never a class or a call.
            $upgrade = $path === dirname(__DIR__, 2) . '/db/upgrade.php';
            foreach (\PhpToken::tokenize(file_get_contents($path)) as $token) {
                if ($upgrade && $token->is(T_CONSTANT_ENCAPSED_STRING) && $token->text === "'local_moodeskpro'") {
                    continue;
                }
                if (!$token->is([T_COMMENT, T_DOC_COMMENT]) && str_contains($token->text, 'local_moodeskpro')) {
                    $found[] = basename($path) . ':' . $token->line;
                }
            }
        }
        $this->assertSame([], $found);
    }

    public function test_the_incident_settings_are_saved_where_the_base_reads_them(): void {
        $settings = file_get_contents(dirname(__DIR__, 2) . '/settings.php');
        foreach (['alert_severity', 'alert_cooldown', 'active_window_hours', 'retention_days'] as $name) {
            $this->assertStringContainsString("'local_moodesk/incident_{$name}'", $settings);
        }
    }

    public function test_the_base_names_no_licence_host(): void {
        $found = [];
        foreach ($this->code_files() as $path) {
            // The upgrade steps of old releases rewrite old URLs; they are history.
            if (basename($path) === 'upgrade.php') {
                continue;
            }
            if (str_contains(file_get_contents($path), 'licenses.moodesk.io')) {
                $found[] = basename($path);
            }
        }
        $this->assertSame([], $found);
    }
}
