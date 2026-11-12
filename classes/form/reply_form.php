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
 * Reply / internal note form.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\form;

use local_moodesk\domain\reply;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Reply form.
 *
 * Renders a single message editor. The reply type (public/note) is submitted as
 * a hidden field; view.php decides which type is being submitted based on which
 * submit button the user clicked.
 *
 * Custom data accepted via $customdata array:
 *   ticketid           int   The ticket being replied to.
 *   type               int   reply::TYPE_PUBLIC_REPLY or TYPE_INTERNAL_NOTE.
 *   attachments_enabled bool Whether the composer accepts attachments.
 *   draftitemid        int   Draft area the attachments live in while unsent.
 *   maxbytes           int   Max upload size in bytes (0 = site default).
 *   maxfiles           int   Max number of files (default 5).
 *   can_add_note       bool  Whether to show the "Add internal note" toggle.
 */
class reply_form extends \moodleform {
    /**
     * Prefix applied to this form's DOM element ids.
     *
     * The ticket list hosts the new-ticket drawer, whose form declares
     * 'attachments' too. Once the ticket detail drawer is open on that screen
     * both forms are in the document, and Moodle initialises file managers by
     * element id (`filemanager` reads `_attributes['id']`) — the first match
     * wins, so this form's draft area would bind to the new-ticket one.
     * Prefixing this form's ids keeps every lookup unambiguous. Element *names*
     * are untouched, so nothing about the POST payload or validation changes.
     */
    private const ID_PREFIX = 'id_mhdreply_';

    /**
     * Rows the message box opens at.
     *
     * A floor, not a fixed size: the composer grows the box as the reply is
     * written (see amd/src/composer_autogrow.js) up to a ceiling set in CSS,
     * after which it scrolls. One line means the composer at rest asks for
     * exactly the room an empty reply needs, and takes the rest only once there
     * is something in it — the conversation keeps the difference.
     *
     * It is also the floor a session without JavaScript gets, where the box
     * cannot grow and scrolls from the second line on. That is the trade: the
     * common case gets a composer that stays out of the way, and the rare one
     * gets a box it has to scroll.
     */
    private const ROWS = 1;

    /**
     * {@inheritdoc}
     */
    public function definition(): void {
        $mform = $this->_form;
        $data  = $this->_customdata;

        $ticketid      = (int) ($data['ticketid'] ?? 0);
        $type          = (int) ($data['type'] ?? reply::TYPE_PUBLIC_REPLY);
        $attachenabled = !empty($data['attachments_enabled']);
        $draftitemid   = (int) ($data['draftitemid'] ?? 0);

        $mform->addElement('hidden', 'ticketid', $ticketid);
        $mform->setType('ticketid', PARAM_INT);

        $mform->addElement('hidden', 'replytype', $type);
        $mform->setType('replytype', PARAM_INT);

        // Message body.
        //
        // A plain textarea, not Moodle's editor element. A support reply is a
        // message, not a document: the rich editor cost most of the composer's
        // height, made the writing experience depend on which editor each site
        // and each user happens to have configured, and bought formatting
        // nobody asked for. What is stored is plain text, rendered through
        // FORMAT_PLAIN, so what an agent types is what every reader sees.
        //
        // PARAM_RAW rather than PARAM_TEXT deliberately. PARAM_TEXT strips tags
        // on the way IN, which silently eats legitimate support content — "use
        // the <div> tag", "if a < b". The text is kept exactly as typed and
        // escaped on the way OUT by format_text($message, FORMAT_PLAIN), which
        // is the single place that decides how it is shown.
        $label = $type === reply::TYPE_INTERNAL_NOTE
            ? get_string('reply_internal_note', 'local_moodesk')
            : get_string('reply_message', 'local_moodesk');

        $mform->addElement(
            'textarea',
            'message',
            $label,
            [
                'rows'        => self::ROWS,
                'class'       => 'mhd-composer__input',
                'placeholder' => get_string('reply_placeholder', 'local_moodesk'),
                'data-region' => 'mhd-composer-input',
            ]
        );
        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', null, 'required', null, 'client');

        // Attachments.
        //
        // A hidden draft item id, not a file manager. The composer draws its own
        // attach button and its own chips, and drives Moodle's file picker
        // against this draft area (ADR: docs/reply-composer-attachments.md).
        //
        // Nothing about the submit changes. This is the same thing the file
        // manager element ultimately emitted — it renders its widget and then a
        // hidden input carrying the draft item id (lib/form/filemanager.php),
        // and that integer is the whole contract with
        // file_save_draft_area_files(). What is gone is the widget, not the
        // draft area, the upload path or any limit Moodle enforces on it.
        if ($attachenabled) {
            $mform->addElement('hidden', 'attachments', $draftitemid);
            $mform->setType('attachments', PARAM_INT);
        }

        // Send.
        //
        // Not add_action_buttons(). That pair — a labelled submit and a Cancel
        // beside it — is a page form's footer, and this is a composer: the
        // message box is the only field, and the only thing to do with it is
        // send it. Cancel is gone entirely, on both mounts. Inside the drawer
        // the panel's own close control already leaves the ticket, and a second
        // way out sitting in the conversation was duplicating it; on the
        // canonical page it did nothing but reload the ticket with an empty box,
        // which is not worth a control. The bar they shared went with them, and
        // the conversation has the height back.
        //
        // What replaces it is the same submit, drawn differently: a real
        // <button type="submit"> inside this form, so every path that sends a
        // reply today still sends it. The form is recognised as submitted by its
        // own `_qf__` marker rather than by any button's name, so nothing about
        // get_data(), validation or the POST depends on what this control is
        // called.
        $mform->addElement('html', $this->send_button());

        $this->apply_id_prefix(['message', 'attachments']);
    }

    /**
     * The composer's send control.
     *
     * Both labels travel on the element, because the composer sends two
     * different things through one control and the mode is switched in the
     * browser: amd/src/reply_toggle.js rewrites the name when the user moves
     * between Public Reply and Internal Note, and having both strings already
     * on the button is what lets it do that without asking the server for a
     * string it could have carried.
     *
     * @return string
     */
    private function send_button(): string {
        global $OUTPUT;

        $type = (int) ($this->_customdata['type'] ?? reply::TYPE_PUBLIC_REPLY);

        $reply = get_string('reply_send', 'local_moodesk');
        $note  = get_string('reply_send_note', 'local_moodesk');

        return $OUTPUT->render_from_template('local_moodesk/composer_send', [
            'label'      => $type === reply::TYPE_INTERNAL_NOTE ? $note : $reply,
            'replylabel' => $reply,
            'notelabel'  => $note,
        ]);
    }

    /**
     * Print the form, without the global "Required" note.
     *
     * moodleform emits that note after the last field whenever the form holds a
     * required element, and in this composer it bought nothing: one line saying
     * that some field is required, above a single message box that is plainly
     * the only field there is. What it cost was a row of the composer's height
     * on every viewport, and that height is the conversation's.
     *
     * Removed at the source, not hidden: the renderer's note template is blanked
     * for the length of this render, so `finishForm()` appends nothing at all —
     * no element, no row, no reserved space. Nothing about the form as a form
     * changes: `message` keeps its required rule client-side and server-side,
     * its label keeps the required marker, and validation is untouched.
     *
     * The renderer is a process-wide singleton
     * (`$GLOBALS['_HTML_QuickForm_default_renderer']`), so the change is put
     * back before this method returns — every other form in the request, the New
     * ticket drawer's included, keeps its own note.
     */
    public function display(): void {
        $renderer = $this->_form->defaultRenderer();
        $previous = $renderer->_requiredNoteTemplate;
        $renderer->_requiredNoteTemplate = '';

        try {
            parent::display();
        } finally {
            $renderer->_requiredNoteTemplate = $previous;
        }
    }

    /**
     * Rewrite the DOM ids of the named elements with {@see self::ID_PREFIX}.
     *
     * Best-effort by design: an element that is not present (attachments when
     * uploads are disabled) or a Moodle version that names its action group
     * differently simply keeps the stock id, which is what happens today.
     *
     * @param string[] $names Element names to re-id.
     */
    private function apply_id_prefix(array $names): void {
        $mform = $this->_form;

        foreach ($names as $name) {
            if (!$mform->elementExists($name)) {
                continue;
            }

            $element = $mform->getElement($name);
            $element->updateAttributes(['id' => self::ID_PREFIX . $name]);

            // Action buttons live inside a group; their own ids collide too.
            if ($element instanceof \MoodleQuickForm_group) {
                foreach ((array) $element->getElements() as $child) {
                    if (method_exists($child, 'getName') && method_exists($child, 'updateAttributes')) {
                        $child->updateAttributes(['id' => self::ID_PREFIX . $child->getName()]);
                    }
                }
            }
        }
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

        // The field has to be text before it can be judged empty or not.
        //
        // A browser cannot submit anything else here — the composer is a plain
        // textarea — but a hand-built POST can send `message[]=foo`, and mform
        // passes it straight through: for a scalar-typed element holding an
        // array value, MoodleQuickForm::getCleanedValue() cleans it with
        // clean_param_array() and keeps the array. Casting that to a string is
        // what used to store the literal "Array" as a reply body, so the type
        // is checked rather than coerced, and the form is refused before
        // get_data() can return anything to persist.
        $message = $data['message'] ?? '';
        if (!is_string($message)) {
            $errors['message'] = get_string('reply_message_invalid', 'local_moodesk');
            return $errors;
        }

        // The old check stripped tags first, because the editor reported an
        // empty reply as "<p></p>". A textarea holds exactly what was typed, so
        // whitespace is the only thing that can hide an empty message.
        if (trim($message) === '') {
            $errors['message'] = get_string('required');
        }

        return $errors;
    }
}
