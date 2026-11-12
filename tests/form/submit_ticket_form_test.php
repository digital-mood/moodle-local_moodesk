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

namespace local_moodesk\form;

/**
 * The new-ticket form refuses a subject or message that is not text (audit H14).
 *
 * A browser cannot send an array for these fields, but a hand-built POST can
 * (`subject[]=`, `message[]=`), and mform passes it through for a scalar
 * element. The subject used to reach trim() and throw a TypeError; the
 * message was cast to the string "Array".
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\form\submit_ticket_form
 */
final class submit_ticket_form_test extends \advanced_testcase {
    /**
     * Validate a submission against a minimal form.
     *
     * @param array $data
     * @return array The errors.
     */
    private function validate(array $data): array {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = new submit_ticket_form(null, ['categories' => [1 => 'General']]);
        return $form->validation($data + ['categoryid' => 1], []);
    }

    public function test_a_subject_that_is_not_text_is_refused(): void {
        $errors = $this->validate(['subject' => ['x'], 'message' => 'Help']);

        $this->assertSame(get_string('ticket_field_not_text', 'local_moodesk'), $errors['subject']);
    }

    public function test_a_message_that_is_not_text_is_refused(): void {
        $errors = $this->validate(['subject' => 'Help', 'message' => ['x']]);

        $this->assertSame(get_string('ticket_field_not_text', 'local_moodesk'), $errors['message']);
    }

    public function test_text_fields_pass(): void {
        $this->assertSame([], $this->validate(['subject' => 'Help', 'message' => 'I cannot log in']));
    }

    public function test_blank_fields_are_still_required(): void {
        $errors = $this->validate(['subject' => '  ', 'message' => '']);

        $this->assertSame(get_string('required'), $errors['subject']);
        $this->assertSame(get_string('required'), $errors['message']);
    }

    /**
     * The subject check is empty(), as before: "0" is not a subject.
     */
    public function test_a_zero_subject_is_still_required(): void {
        $errors = $this->validate(['subject' => '0', 'message' => 'Help']);

        $this->assertSame(get_string('required'), $errors['subject']);
    }
}
