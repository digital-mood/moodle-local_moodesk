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
 * Department create / edit form.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating and editing a help desk department.
 *
 * Custom data accepted via $customdata array:
 *   id   int   Department ID (0 = create mode).
 */
class department_form extends \moodleform {
    /**
     * {@inheritdoc}
     */
    public function definition(): void {
        global $USER;

        $mform = $this->_form;

        // Name (required).
        $mform->addElement(
            'text',
            'name',
            get_string('department_name', 'local_moodesk'),
            ['maxlength' => 255, 'size' => 50]
        );
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Description (optional).
        $mform->addElement(
            'textarea',
            'description',
            get_string('department_description', 'local_moodesk'),
            ['rows' => 4, 'cols' => 50]
        );
        $mform->setType('description', PARAM_TEXT);

        // Active flag.
        $mform->addElement('checkbox', 'isactive', get_string('department_isactive', 'local_moodesk'));
        $mform->setDefault('isactive', 1);

        // Fields plugins add (hook department_form_extend), before the buttons.
        \core\di::get(\core\hook\manager::class)->dispatch(
            \local_moodesk\hook\department_form_extend::for_definition($mform, (int) $USER->id)
        );

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validate the submitted values.
     *
     * @param array $data  Submitted form values.
     * @param array $files Files submitted with the form.
     * @return array Errors keyed by field name; empty when the form is valid.
     */
    public function validation($data, $files): array {
        global $USER;

        $errors = parent::validation($data, $files);

        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = get_string('required');
        }

        // Plugins validate their own fields; their errors never replace one of ours.
        $hook = \local_moodesk\hook\department_form_extend::for_validation((array) $data, (array) $files, (int) $USER->id);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        $errors += $hook->get_errors();

        return $errors;
    }

    /**
     * The values plugins fill the form with when editing a department (hook department_form_extend).
     *
     * @param int $departmentid
     * @param int $userid
     * @return array Element name => value, to merge into set_data().
     */
    public function plugin_defaults(int $departmentid, int $userid): array {
        $hook = \local_moodesk\hook\department_form_extend::for_load($departmentid, $userid);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return $hook->get_defaults();
    }

    /**
     * Let plugins save their fields once the department is created or updated (hook department_form_extend).
     *
     * @param int       $departmentid
     * @param \stdClass $formdata What get_data() returned.
     * @param int       $userid
     */
    public function save_plugin_fields(int $departmentid, \stdClass $formdata, int $userid): void {
        \core\di::get(\core\hook\manager::class)->dispatch(
            \local_moodesk\hook\department_form_extend::for_save($departmentid, (array) $formdata, $userid)
        );
    }
}
