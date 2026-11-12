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
 * Category domain constants.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Category domain constants.
 *
 * Categories classify tickets. All categories are flat in Phase 1–3.
 * The parentid column exists in the schema for future sub-category support (Pro),
 * but is always null for categories created through this interface.
 *
 * The departmentid column exists for future department-scoped categories
 * but is always null for categories created through this interface.
 *
 * Soft delete: archiving sets visible = 0. Archived categories are hidden from
 * ticket submission forms but preserved in the DB to maintain FK integrity on
 * existing tickets.
 *
 * The default category (stored in plugin config as 'defaultcategoryid') cannot
 * be archived. A different default must be configured first.
 */
class category {
    /** Active category — visible in ticket submission and agent dropdowns. */
    const VISIBLE_ACTIVE = 1;

    /** Archived category — hidden from new-ticket forms, preserved for existing tickets. */
    const VISIBLE_ARCHIVED = 0;
}
