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
 * Tests for the department_form_extend hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\form\department_form;

/**
 * The department form offers its four phases to plugins, and is unchanged without them.
 *
 * @covers \local_moodesk\hook\department_form_extend
 * @covers \local_moodesk\form\department_form
 */
final class department_form_extend_hook_test extends \advanced_testcase {
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
     * A fresh form, as department_edit.php builds it.
     *
     * @return department_form
     */
    private function form(): department_form {
        return new department_form(new \moodle_url('/local/moodesk/department_edit.php'), ['id' => 0]);
    }

    /**
     * A plugin that adds a field `plg_queue` and requires it to be numeric.
     */
    private function redirect_plugin(): void {
        $this->redirectHook(department_form_extend::class, function (department_form_extend $hook): void {
            switch ($hook->get_phase()) {
                case department_form_extend::PHASE_DEFINITION:
                    $hook->get_mform()->addElement('text', 'plg_queue', 'Queue');
                    $hook->get_mform()->setType('plg_queue', PARAM_RAW);
                    break;
                case department_form_extend::PHASE_VALIDATION:
                    if (!ctype_digit((string) ($hook->get_data()['plg_queue'] ?? ''))) {
                        $hook->add_error('plg_queue', 'Numbers only');
                    }
                    break;
                case department_form_extend::PHASE_LOAD:
                    $hook->set_default('plg_queue', (string) ($hook->get_departmentid() * 10));
                    break;
            }
        });
    }

    public function test_without_a_callback_the_form_is_the_base_one(): void {
        $this->redirectHook(department_form_extend::class, static function (): void {
        });
        $form = $this->form();
        $this->assertStringNotContainsString('name="plg_queue"', $form->render());
        $this->assertSame([], $form->validation(['name' => 'Support'], []));
        $this->assertSame([], $form->plugin_defaults(3, 2));
    }

    public function test_definition_validation_and_load(): void {
        $this->redirect_plugin();
        $form = $this->form();

        $html = $form->render();
        $this->assertStringContainsString('name="plg_queue"', $html);
        $this->assertLessThan(strpos($html, 'name="submitbutton"'), strpos($html, 'name="plg_queue"'));

        $errors = $form->validation(['name' => '', 'plg_queue' => 'abc'], []);
        $this->assertSame(get_string('required'), $errors['name']);
        $this->assertSame('Numbers only', $errors['plg_queue']);

        $this->assertSame(['plg_queue' => '30'], $form->plugin_defaults(3, 2));
    }

    public function test_save_gives_the_department_and_the_submission(): void {
        $seen = null;
        $this->redirectHook(department_form_extend::class, function (department_form_extend $hook) use (&$seen): void {
            if ($hook->get_phase() === department_form_extend::PHASE_SAVE) {
                $seen = [$hook->get_departmentid(), $hook->get_data()['plg_queue'], $hook->get_userid()];
            }
        });

        $this->form()->save_plugin_fields(4, (object) ['plg_queue' => '7'], 2);

        $this->assertSame([4, '7', 2], $seen);
    }

    public function test_accessors_refuse_the_wrong_phase(): void {
        $hook = department_form_extend::for_load(3, 2);
        $this->expectException(\coding_exception::class);
        $hook->get_data();
    }
}
