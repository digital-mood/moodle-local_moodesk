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
 * Domain unit tests for reply visibility rules.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Tests for reply.
 *
 * @covers \local_moodesk\domain\reply
 */
final class reply_test extends \basic_testcase {
    public function test_requester_visible_types_excludes_internal_notes(): void {
        // The single hard rule: a requester must never see internal notes.
        // Regression check — if this assertion ever flips, requesters would be
        // exposed to agent-only conversation.
        $visible = reply::requester_visible_types();

        $this->assertContains(reply::TYPE_PUBLIC_REPLY, $visible);
        $this->assertContains(reply::TYPE_SYSTEM_EVENT, $visible);
        $this->assertNotContains(reply::TYPE_INTERNAL_NOTE, $visible);
    }

    public function test_is_valid_type_accepts_known_constants(): void {
        $this->assertTrue(reply::is_valid_type(reply::TYPE_PUBLIC_REPLY));
        $this->assertTrue(reply::is_valid_type(reply::TYPE_INTERNAL_NOTE));
        $this->assertTrue(reply::is_valid_type(reply::TYPE_SYSTEM_EVENT));
    }

    public function test_is_valid_type_rejects_unknown_values(): void {
        $this->assertFalse(reply::is_valid_type(99));
        $this->assertFalse(reply::is_valid_type(-1));
    }
}
