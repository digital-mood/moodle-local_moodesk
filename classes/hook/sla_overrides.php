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
 * Asks plugins for SLA hours that replace the global ones for some departments.
 *
 * Dispatched by {@see \local_moodesk\service\sla_service::prime_departments()},
 * once per batch rather than per ticket. A value of 0 disables that target; a
 * target nobody sets inherits the global setting.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to override the mooDesk SLA targets of a department.')]
#[\core\attribute\tags('local_moodesk')]
final class sla_overrides {
    /** @var array<int, array<string, int>> Hours by department, then by target. */
    private array $hours = [];

    /**
     * Create the hook.
     *
     * @param int[] $departmentids The departments asked about.
     */
    public function __construct(
        /** @var int[] */
        public readonly array $departmentids,
    ) {
    }

    /**
     * Set one target of a department.
     *
     * @param int    $departmentid
     * @param string $target       `response_` or `resolve_`, then low, normal, high or urgent.
     * @param int    $hours        0 disables the target.
     */
    public function set_hours(int $departmentid, string $target, int $hours): void {
        $this->hours[$departmentid][$target] = $hours;
    }

    /**
     * The targets set for a department.
     *
     * @param int $departmentid
     * @return array<string, int>
     */
    public function get_hours(int $departmentid): array {
        return $this->hours[$departmentid] ?? [];
    }
}
