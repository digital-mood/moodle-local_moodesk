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
 * Tests for the ticket_form_extend hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\output\new_ticket_drawer;

/**
 * The New ticket form offers its three phases to plugins, and is unchanged without them.
 *
 * @covers \local_moodesk\hook\ticket_form_extend
 * @covers \local_moodesk\form\submit_ticket_form
 * @covers \local_moodesk\output\new_ticket_drawer::save_plugin_fields
 */
final class ticket_form_extend_hook_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * A fresh form, built the way both mounts build it.
     *
     * @return \local_moodesk\form\submit_ticket_form
     */
    private function form(): \local_moodesk\form\submit_ticket_form {
        return new_ticket_drawer::create()->get_form();
    }

    public function test_without_a_callback_the_form_is_the_base_one(): void {
        $form = $this->form();
        $this->assertStringNotContainsString('name="pro_field"', $form->render());
        $this->assertSame([], $form->validation(['subject' => 'Help', 'message' => 'Body'], []));
    }

    public function test_definition_lets_a_plugin_add_elements_before_the_buttons(): void {
        $this->redirectHook(ticket_form_extend::class, function (ticket_form_extend $hook): void {
            if ($hook->get_phase() === ticket_form_extend::PHASE_DEFINITION) {
                $hook->get_mform()->addElement('text', 'pro_field', 'Pro field');
                $hook->get_mform()->setType('pro_field', PARAM_TEXT);
            }
        });

        $html = $this->form()->render();
        $this->assertStringContainsString('name="pro_field"', $html);
        $this->assertLessThan(strpos($html, 'name="submitbutton"'), strpos($html, 'name="pro_field"'));
    }

    public function test_validation_adds_plugin_errors_without_replacing_the_base_ones(): void {
        $seen = null;
        $this->redirectHook(ticket_form_extend::class, function (ticket_form_extend $hook) use (&$seen): void {
            if ($hook->get_phase() === ticket_form_extend::PHASE_VALIDATION) {
                $seen = $hook->get_data()['subject'];
                $hook->add_error('pro_field', 'Pro field is wrong');
                $hook->add_error('subject', 'Plugin says no');
            }
        });

        $errors = $this->form()->validation(['subject' => '  ', 'message' => 'Body'], []);

        $this->assertSame('  ', $seen);
        $this->assertSame(get_string('required'), $errors['subject']);
        $this->assertSame('Pro field is wrong', $errors['pro_field']);
    }

    public function test_save_gives_the_new_ticket_and_the_submission(): void {
        $seen = null;
        $this->redirectHook(ticket_form_extend::class, function (ticket_form_extend $hook) use (&$seen): void {
            if ($hook->get_phase() === ticket_form_extend::PHASE_SAVE) {
                $seen = [$hook->get_ticketid(), $hook->get_data()['pro_field'], $hook->get_userid()];
            }
        });

        new_ticket_drawer::create()->save_plugin_fields(42, (object) ['pro_field' => 'value'], 7);

        $this->assertSame([42, 'value', 7], $seen);
    }

    public function test_accessors_refuse_the_wrong_phase(): void {
        $hook = ticket_form_extend::for_save(42, [], 7);
        $this->expectException(\coding_exception::class);
        $hook->add_error('pro_field', 'No');
    }
}
