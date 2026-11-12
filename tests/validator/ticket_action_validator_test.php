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
 * Unit tests for ticket action parameter validation.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\validator;

/**
 * Tests for ticket_action_validator's parameter guards.
 *
 * @covers \local_moodesk\validator\ticket_action_validator
 */
final class ticket_action_validator_test extends \basic_testcase {
    public function test_a_string_passes(): void {
        ticket_action_validator::require_string('Hello world', 'message');

        $this->expectNotToPerformAssertions();
    }

    public function test_an_empty_string_passes(): void {
        // Emptiness is a separate question, answered by the caller.
        ticket_action_validator::require_string('', 'message');

        $this->expectNotToPerformAssertions();
    }

    public function test_a_multiline_unicode_string_passes(): void {
        ticket_action_validator::require_string("Olá — ✓\nsegunda línea", 'message');

        $this->expectNotToPerformAssertions();
    }

    public function test_an_array_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        ticket_action_validator::require_string(['foo'], 'message');
    }

    public function test_an_empty_array_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        ticket_action_validator::require_string([], 'message');
    }

    public function test_an_object_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        ticket_action_validator::require_string(new \stdClass(), 'message');
    }

    public function test_an_object_with_tostring_is_rejected(): void {
        // Deliberately strict: a text field that did not receive text is a
        // malformed request, not a value to be salvaged by coercion.
        $stringable = new class {
            /**
             * Render as text.
             *
             * @return string
             */
            public function __toString(): string {
                return 'looks like text';
            }
        };

        $this->expectException(\invalid_parameter_exception::class);
        ticket_action_validator::require_string($stringable, 'message');
    }

    public function test_null_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        ticket_action_validator::require_string(null, 'message');
    }

    public function test_an_integer_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        ticket_action_validator::require_string(42, 'message');
    }

    public function test_the_message_names_the_field_and_the_type(): void {
        try {
            ticket_action_validator::require_string(['foo'], 'message');
            $this->fail('An array should have been rejected.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('message', $e->getMessage());
            $this->assertStringContainsString('array', $e->getMessage());
        }
    }
}
