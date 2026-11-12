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
 * Category create / edit form.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for creating and editing a help desk category.
 *
 * Custom data accepted via $customdata array:
 *   id   int   Category ID (0 = create mode).
 */
class category_form extends \moodleform {
    /**
     * {@inheritdoc}
     */
    public function definition(): void {
        $mform = $this->_form;

        // Name (required).
        $mform->addElement(
            'text',
            'name',
            get_string('category_name', 'local_moodesk'),
            ['maxlength' => 255, 'size' => 50]
        );
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Description (optional).
        $mform->addElement(
            'textarea',
            'description',
            get_string('category_description', 'local_moodesk'),
            ['rows' => 3, 'cols' => 50]
        );
        $mform->setType('description', PARAM_TEXT);

        // Sort order.
        $mform->addElement(
            'text',
            'sortorder',
            get_string('category_sortorder', 'local_moodesk'),
            ['size' => 5]
        );
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);
        $mform->addRule('sortorder', null, 'numeric', null, 'client');

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
        $errors = parent::validation($data, $files);

        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = get_string('required');
        }

        if (!is_numeric($data['sortorder'] ?? 0) || (int) ($data['sortorder'] ?? 0) < 0) {
            $errors['sortorder'] = get_string('required');
        }

        return $errors;
    }
}
