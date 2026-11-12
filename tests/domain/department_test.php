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
 * Domain unit tests for department workspace views and status constants.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Tests for department.
 *
 * @covers \local_moodesk\domain\department
 */
final class department_test extends \basic_testcase {
    public function test_valid_views_contains_all_workspace_identifiers(): void {
        $views = department::valid_views();

        $this->assertContains(department::VIEW_ALL, $views);
        $this->assertContains(department::VIEW_MINE, $views);
        $this->assertContains(department::VIEW_UNASSIGNED, $views);
        $this->assertContains(department::VIEW_DEPARTMENT, $views);
        $this->assertCount(4, $views);
    }

    public function test_active_and_archived_status_values_distinct(): void {
        $this->assertNotEquals(department::STATUS_ACTIVE, department::STATUS_ARCHIVED);
        $this->assertSame(1, department::STATUS_ACTIVE);
        $this->assertSame(0, department::STATUS_ARCHIVED);
    }
}
