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
 * Lets a plugin declare incident sources of its own.
 *
 * Dispatched once per request by
 * {@see \local_moodesk\service\incident_source_registry}. The base declares its
 * own sources; a plugin adds the ones its code records incidents under, with
 * the string that labels them. A source nobody declares is still accepted by
 * {@see \local_moodesk\service\incident_service::record()} outside PHPUnit, but
 * System Health does not show it and it does not alert.
 *
 * A callback must only declare: it runs inside whatever transaction or
 * observer first asked for the sources, so it must not query the database.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to declare the sources of the operational incidents they record in mooDesk.')]
#[\core\attribute\tags('local_moodesk')]
final class health_sources {
    /** @var array<string, array{component: string, labelstring: string}> Source => label. */
    private array $sources = [];

    /**
     * Declare a source, or relabel it if it is already there.
     *
     * @param string $source      The value stored in the incident's source column (30 characters at most).
     * @param string $component   The component that owns the label string.
     * @param string $labelstring The string identifier that labels the source.
     * @throws \coding_exception For a source that is not a lowercase identifier.
     */
    public function add_source(string $source, string $component, string $labelstring): void {
        if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/', $source)) {
            throw new \coding_exception("Invalid incident source: {$source}");
        }
        $this->sources[$source] = ['component' => $component, 'labelstring' => $labelstring];
    }

    /**
     * The sources declared so far, in the order they were added.
     *
     * @return array<string, array{component: string, labelstring: string}>
     */
    public function get_sources(): array {
        return $this->sources;
    }
}
