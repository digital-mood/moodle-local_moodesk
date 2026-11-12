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
 * Lets a plugin add filters and one column to the agent directory.
 *
 * Phases ({@see filter_extension}): params and options from agents.php. There
 * is no sql phase: the directory filters its roster in memory, so a plugin
 * narrows it through {@see agent_groups}.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add filters and a column to the mooDesk agent directory.')]
#[\core\attribute\tags('local_moodesk')]
final class agent_directory_filters extends filter_extension {
    /** @var array{label: string, empty: string}|null The column, when a plugin adds one. */
    private ?array $column = null;

    /**
     * Show the groups a plugin reports through {@see agent_groups} in a column.
     *
     * @param string $label The column heading.
     * @param string $empty What a row without groups says.
     */
    public function set_groups_column(string $label, string $empty): void {
        $this->column = ['label' => $label, 'empty' => $empty];
    }

    /**
     * The column, when a plugin added one.
     *
     * @return array{label: string, empty: string}|null
     */
    public function get_groups_column(): ?array {
        return $this->column;
    }
}
