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
 * New ticket submission form.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for submitting a new help desk ticket.
 *
 * Custom data accepted via $customdata array:
 *   categories          array    id => name map of available categories.
 *   defaultcategoryid   int|0    Pre-selected category, or 0 for none.
 *   departments         array    id => name map of active departments (Phase 3).
 *   courseid            int|0    Optional course context for this ticket.
 *   attachments_enabled bool     Whether the composer accepts attachments.
 *   draftitemid         int      Draft area the attachments live in while unsent.
 *   attachments_ui      string   Rendered attachment control, placed under the
 *                                message box. Empty means no button is drawn —
 *                                the draft area and the submit path are
 *                                unaffected either way.
 *   maxbytes            int      Max upload size in bytes (0 = site default).
 *   maxfiles            int      Max number of files (default 5).
 *   suggestions_ui      string   Rendered "Suggested articles" block (KB-04), placed
 *                                between the category row and the message box. Empty
 *                                means no block is drawn; the submission is unaffected
 *                                either way.
 *   submitlabel         string   Label for the submit button; defaults to
 *                                'ticket_submit'. The drawer passes its own.
 */
class submit_ticket_form extends \moodleform {
    /**
     * Rows the message box opens at.
     *
     * A floor, not a fixed size: the box grows with what is written (see
     * amd/src/composer_autogrow.js) up to a ceiling set in CSS, after which it
     * scrolls.
     *
     * Four rather than the reply composer's one. The two floors answer
     * different questions. A reply sits under a conversation and every line it
     * claims at rest is a line of thread the agent cannot see, so it opens at
     * the room an empty reply needs. This box is the form's subject matter —
     * the whole point of the screen is to describe a problem — and opening it
     * at one line would read as an afterthought next to the Subject field above
     * it. Four lines is also what a session without JavaScript is left with,
     * where the box cannot grow.
     */
    private const ROWS = 4;

    /**
     * {@inheritdoc}
     */
    public function definition(): void {
        global $USER;

        $mform = $this->_form;
        $data  = $this->_customdata;

        $categories        = $data['categories'] ?? [];
        $defaultcategoryid = $data['defaultcategoryid'] ?? 0;
        $departments       = $data['departments'] ?? [];
        $courseid          = $data['courseid'] ?? 0;
        $attachenabled     = !empty($data['attachments_enabled']);
        $draftitemid       = (int) ($data['draftitemid'] ?? 0);

        // Hidden: pass courseid through the form if set.
        if ($courseid) {
            $mform->addElement('hidden', 'courseid', $courseid);
            $mform->setType('courseid', PARAM_INT);
        }

        // Subject.
        $mform->addElement(
            'text',
            'subject',
            get_string('ticket_subject', 'local_moodesk'),
            ['maxlength' => 255, 'size' => 60]
        );
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required', null, 'client');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Category (shown when more than one exists).
        if (count($categories) > 1) {
            $options = [0 => get_string('choosedots')] + $categories;
            $mform->addElement('select', 'categoryid', get_string('ticket_category', 'local_moodesk'), $options);
            $mform->setType('categoryid', PARAM_INT);
            $mform->setDefault('categoryid', $defaultcategoryid);
        } else {
            $catid = $defaultcategoryid ?: (int) array_key_first($categories);
            $mform->addElement('hidden', 'categoryid', $catid);
            $mform->setType('categoryid', PARAM_INT);
        }

        // Department selector (Phase 3: only shown when active departments exist).
        if (!empty($departments)) {
            $deptopt = [0 => get_string('department_none', 'local_moodesk')] + $departments;
            $mform->addElement(
                'select',
                'departmentid',
                get_string('ticket_department', 'local_moodesk'),
                $deptopt
            );
            $mform->setType('departmentid', PARAM_INT);
            $mform->setDefault('departmentid', 0);
        } else {
            $mform->addElement('hidden', 'departmentid', 0);
            $mform->setType('departmentid', PARAM_INT);
        }

        // Suggested articles (KB-04).
        //
        // Placed here, after the two fields that drive it and before the
        // description, so the requester sees a possible answer before spending
        // effort on describing the problem. An 'html' element like the attach
        // control below: markup emitted verbatim with no .fitem wrapper, so it
        // shares the form's left edge. Nothing in it submits — the block is
        // filled by JavaScript from the knowledge base and changes neither the
        // payload nor validation. The caller decides whether there is one.
        $suggestionsui = (string) ($data['suggestions_ui'] ?? '');
        if ($suggestionsui !== '') {
            $mform->addElement('html', $suggestionsui);
        }

        // Message body.
        //
        // A plain textarea, not Moodle's editor element — the same box the reply
        // composer writes into, for the same reasons. A support request is a
        // message, not a document: the rich editor cost most of the panel's
        // height, made the writing experience depend on which editor each site
        // and each user happens to have configured, and bought formatting nobody
        // asked for. What is stored is plain text, rendered through FORMAT_PLAIN,
        // so what a requester types is what every reader sees.
        //
        // PARAM_RAW rather than PARAM_TEXT deliberately. PARAM_TEXT strips tags
        // on the way IN, which silently eats legitimate support content — "the
        // <div> tag renders wrong", "if a < b". The text is kept exactly as typed
        // and escaped on the way OUT by format_text($message, FORMAT_PLAIN).
        $mform->addElement(
            'textarea',
            'message',
            get_string('ticket_message', 'local_moodesk'),
            [
                'rows'        => self::ROWS,
                'class'       => 'mhd-composer__input',
                'placeholder' => get_string('ticket_message_placeholder', 'local_moodesk'),
                'data-region' => 'mhd-composer-input',
            ]
        );
        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', null, 'required', null, 'client');

        // Attachments.
        //
        // A hidden draft item id, not a file manager. The form draws its own
        // attach button and its own file list, and drives Moodle's file picker
        // against this draft area (ADR: docs/reply-composer-attachments.md).
        //
        // Nothing about the submit changes. This is the same thing the file
        // manager element ultimately emitted — it renders its widget and then a
        // hidden input carrying the draft item id (lib/form/filemanager.php), and
        // that integer is the whole contract with file_save_draft_area_files().
        // What is gone is the widget, not the draft area, the upload path or any
        // limit Moodle enforces on it.
        //
        // The control itself is placed here, directly under the message box,
        // rather than in the drawer's footer: attaching a file is part of
        // describing the problem, and it belongs next to the description it
        // belongs to. It arrives already rendered (new_ticket_drawer builds it
        // from local_moodesk/composer_attachments) and goes in as an 'html'
        // element, which emits markup verbatim with no .fitem wrapper — so it
        // shares the message box's left edge instead of being indented into
        // moodleform's field column. Both mounts get it in the same place.
        //
        // Nothing in it submits, so it changes neither the payload nor
        // validation; without JavaScript to drive the picker the caller passes
        // no markup and the button is simply absent.
        if ($attachenabled) {
            $mform->addElement('hidden', 'attachments', $draftitemid);
            $mform->setType('attachments', PARAM_INT);

            $attachmentsui = (string) ($data['attachments_ui'] ?? '');
            if ($attachmentsui !== '') {
                $mform->addElement('html', $attachmentsui);
            }
        }

        // Fields plugins add (hook ticket_form_extend), before the buttons.
        \core\di::get(\core\hook\manager::class)->dispatch(
            \local_moodesk\hook\ticket_form_extend::for_definition($mform, (int) $USER->id)
        );

        // The label is the caller's: the drawer says "Create ticket", the
        // standalone page keeps "Submit ticket". The button name is untouched,
        // so nothing about the submission changes.
        $this->add_action_buttons(
            true,
            $data['submitlabel'] ?? get_string('ticket_submit', 'local_moodesk')
        );
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

        // Both fields have to be text before they can be judged empty or not
        // (audit H14, the guard reply_form already has). A browser cannot send
        // anything else, but a hand-built POST can send `subject[]=` or
        // `message[]=`, and mform passes the array straight through for a
        // scalar-typed element: trim() on it was a TypeError, and a cast to
        // string would store the literal "Array". Checked, not coerced.
        $nottext = false;
        foreach (['subject', 'message'] as $field) {
            if (!is_string($data[$field] ?? '')) {
                $errors[$field] = get_string('ticket_field_not_text', 'local_moodesk');
                $nottext = true;
            }
        }
        if ($nottext) {
            return $errors;
        }

        if (empty(trim($data['subject'] ?? ''))) {
            $errors['subject'] = get_string('required');
        }

        // The old check stripped tags first, because the editor reported an
        // empty message as "<p></p>". A textarea holds exactly what was typed,
        // so whitespace is the only thing that can hide an empty message.
        if (trim($data['message'] ?? '') === '') {
            $errors['message'] = get_string('required');
        }

        // Plugins validate their own fields (hook ticket_form_extend). Their
        // errors never replace one of the base's.
        $hook = \local_moodesk\hook\ticket_form_extend::for_validation((array) $data, (array) $files, (int) $USER->id);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        $errors += $hook->get_errors();

        return $errors;
    }
}
