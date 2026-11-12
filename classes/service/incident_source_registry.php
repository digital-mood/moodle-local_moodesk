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
 * The incident sources this site knows about.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\incident;
use local_moodesk\hook\health_sources;

/**
 * Which incident sources are registered, and how each is labelled.
 *
 * The base's own sources ({@see incident::base_sources()}) plus whatever other
 * plugins declare through the {@see health_sources} hook. Everything that shows
 * or alerts about incidents asks here, so an incident whose source nobody
 * declares — one recorded by an add-on that has since been removed, or by an
 * add-on too old to know the hook — stays in the table but is neither shown
 * nor alerted, and no code asks for a label string that may not exist.
 *
 * The hook is dispatched once per request and the result kept: incidents are
 * recorded from observers and inside transactions, and a callback only
 * declares, so the answer cannot change within a request. Under PHPUnit
 * nothing is kept, so a test that redirects the hook sees its own callbacks.
 */
class incident_source_registry {
    /** @var array<string, array{component: string, labelstring: string}>|null Kept for the request. */
    private static ?array $sources = null;

    /** @var bool|null Strictness forced by a test; null means the default. */
    private static ?bool $strictoverride = null;

    /**
     * Every registered source with its label string, base sources first.
     *
     * @return array<string, array{component: string, labelstring: string}>
     */
    public static function all(): array {
        if (self::$sources !== null) {
            return self::$sources;
        }

        $hook = new health_sources();
        foreach (incident::base_sources() as $source) {
            $hook->add_source($source, 'local_moodesk', 'incident_source_' . $source);
        }
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        $sources = $hook->get_sources();

        if (!self::is_phpunit()) {
            self::$sources = $sources;
        }
        return $sources;
    }

    /**
     * The registered source values.
     *
     * @return string[]
     */
    public static function sources(): array {
        return array_keys(self::all());
    }

    /**
     * Whether a source is registered.
     *
     * @param string $source
     * @return bool
     */
    public static function is_registered(string $source): bool {
        return array_key_exists($source, self::all());
    }

    /**
     * The label of a registered source.
     *
     * @param string $source
     * @return string
     * @throws \coding_exception For a source nobody registered: callers filter first.
     */
    public static function label(string $source): string {
        $all = self::all();
        if (!isset($all[$source])) {
            throw new \coding_exception('local_moodesk: no label for unregistered incident source ' . $source);
        }
        return get_string($all[$source]['labelstring'], $all[$source]['component']);
    }

    /**
     * Whether recording under an unregistered source is an error.
     *
     * Strict under PHPUnit, so a misspelt source fails the test that wrote it.
     * Tolerant everywhere else: an add-on that predates the hook records under
     * sources it cannot declare, and that must not break it.
     *
     * @return bool
     */
    public static function is_strict(): bool {
        return self::$strictoverride ?? self::is_phpunit();
    }

    /**
     * Force strict or tolerant mode. Honoured only under PHPUnit.
     *
     * @param bool|null $strict Null restores the default.
     */
    public static function set_strict_for_testing(?bool $strict): void {
        if (self::is_phpunit()) {
            self::$strictoverride = $strict;
        }
    }

    /**
     * Forget what this request learned. For tests.
     */
    public static function reset_for_testing(): void {
        self::$sources        = null;
        self::$strictoverride = null;
    }

    /**
     * Whether this is a PHPUnit run.
     *
     * @return bool
     */
    private static function is_phpunit(): bool {
        return defined('PHPUNIT_TEST') && PHPUNIT_TEST;
    }
}
