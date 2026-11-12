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
 * Lets a plugin add one column to the department list.
 *
 * Dispatched by departments.php with the departments on the page. A cell is
 * text, set off as a pill when it is highlighted.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add a column to the mooDesk department list.')]
#[\core\attribute\tags('local_moodesk')]
final class department_list_columns {
    /** @var string|null The column heading. */
    private ?string $label = null;

    /** @var array<int, array{text: string, highlighted: bool}> Cells by department. */
    private array $cells = [];

    /**
     * Create the hook.
     *
     * @param \stdClass[] $departments The department rows.
     */
    public function __construct(
        /** @var \stdClass[] */
        public readonly array $departments,
    ) {
    }

    /**
     * Add the column.
     *
     * @param string $label The heading.
     */
    public function set_column(string $label): void {
        $this->label = $label;
    }

    /**
     * Fill a cell.
     *
     * @param int    $departmentid
     * @param string $text
     * @param bool   $highlighted
     */
    public function set_cell(int $departmentid, string $text, bool $highlighted = false): void {
        $this->cells[$departmentid] = ['text' => $text, 'highlighted' => $highlighted];
    }

    /**
     * The column heading.
     *
     * @return string|null Null when no plugin added the column.
     */
    public function get_label(): ?string {
        return $this->label;
    }

    /**
     * The cell of a department.
     *
     * @param int $departmentid
     * @return array{text: string, highlighted: bool}
     */
    public function get_cell(int $departmentid): array {
        return $this->cells[$departmentid] ?? ['text' => '', 'highlighted' => false];
    }
}
