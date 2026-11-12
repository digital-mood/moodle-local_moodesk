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

use local_moodesk\domain\kb_article;
use local_moodesk\output\kb_ui;
use local_moodesk\service\kb_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');
// FILE_INTERNAL lives here, not in filelib.
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * Form for writing a knowledge base article.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Title, then where it is filed and who it is for, then the body — the order
 * a writer thinks in, and the one that keeps the two short controls above the
 * one that takes the rest of the screen.
 *
 * The form writes CONTENT and nothing else. Status is not a field: a new
 * article is always a draft, and saving an existing one leaves its status
 * where it was. Publishing, unpublishing, archiving, restoring and deleting
 * are actions on the article page, each its own request, so that saving a
 * paragraph can never accidentally take an article live or off the shelf.
 *
 * The body is Moodle's editor element on the `kb_article` file area, so an
 * embedded image travels with the article. It is PARAM_RAW on the way in —
 * what the author wrote is what is stored — and it is never trusted:
 * everything that shows a body goes through format_text(). The options come
 * from {@see kb_service::body_file_options()} so the form and the save path
 * cannot disagree about what a body may embed.
 *
 * Language (KB-06, Pro): a select of the installed packs, defaulting to the
 * language the editor reads in; hidden and fixed when the row is a translation
 * being started (the language was chosen one step earlier) and absent in
 * Starter (the service writes the site language, as before). Category and
 * audience belong to the article's group, so on a row with siblings — and on a
 * translation being started — they are frozen with a note saying so: what the
 * translation inherits is shown, not hidden.
 *
 * What reaches the service is {@see to_service_data()}, not get_data() as
 * it comes: a hard-frozen element is not submitted, and formslib then exports
 * the element's DEFAULT for it — the "No category" and "Everyone" set in
 * definition() — which on an edit would read as a change. The page therefore
 * asks the form which fields were frozen and leaves those out, so nothing
 * pretends the user made a choice they were not offered.
 *
 * Custom data ($customdata):
 *   id          int    Article id, 0 when creating.
 *   status      int    Current status; only read when id > 0.
 *   canmanage   bool   Whether the actor holds :managekb.
 *   categories  array  id => name of the categories an article may be filed under.
 *   languages   array  code => name of the packs a row may be in; empty hides the field (Starter).
 *   shared      bool   Freeze category and audience: the row has siblings, or is a translation being started.
 *   translate   bool   The row is a translation being started: language hidden.
 */
class kb_article_form extends \moodleform {
    /**
     * {@inheritdoc}
     */
    public function definition(): void {
        $mform      = $this->_form;
        $id         = (int) ($this->_customdata['id'] ?? 0);
        $status     = (int) ($this->_customdata['status'] ?? kb_article::STATUS_DRAFT);
        $canmanage  = (bool) ($this->_customdata['canmanage'] ?? false);
        $categories = (array) ($this->_customdata['categories'] ?? []);
        $languages  = (array) ($this->_customdata['languages'] ?? []);
        $shared     = (bool) ($this->_customdata['shared'] ?? false);
        $translate  = (bool) ($this->_customdata['translate'] ?? false);

        $mform->addElement('hidden', 'id', $id);
        $mform->setType('id', PARAM_INT);

        // Title.
        $mform->addElement(
            'text',
            'title',
            get_string('kb_field_title', 'local_moodesk'),
            ['maxlength' => 255, 'size' => 60]
        );
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addRule('title', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Category — the ticket taxonomy, on purpose (roadmap decision). "None"
        // is a real choice: an article that answers a question about the help
        // desk itself belongs to no ticket category.
        $mform->addElement(
            'select',
            'categoryid',
            get_string('kb_field_category', 'local_moodesk'),
            [0 => get_string('kb_category_none', 'local_moodesk')] + $categories
        );
        $mform->setType('categoryid', PARAM_INT);
        $mform->setDefault('categoryid', 0);

        // Audience. On a live or archived article this is an access rule, and
        // only a manager may change one: the service refuses the write, so the
        // form does not offer it. A draft is nobody's yet, so its writer decides.
        $mform->addElement(
            'select',
            'audience',
            get_string('kb_field_audience', 'local_moodesk'),
            kb_ui::audience_labels()
        );
        $mform->setType('audience', PARAM_INT);
        $mform->setDefault('audience', kb_article::AUDIENCE_EVERYONE);
        $mform->addHelpButton('audience', 'kb_field_audience', 'local_moodesk');
        if ($id > 0 && $status !== kb_article::STATUS_DRAFT && !$canmanage) {
            $mform->hardFreeze('audience');
        }

        // Shared with the group (KB-06): frozen, not hidden, so the editor
        // sees what this translation inherits — and changed on any one row,
        // which is why the note says where. A frozen element is not submitted
        // and the page does not pass it on, so nothing pretends a change was made.
        if ($shared) {
            $mform->hardFreeze('categoryid');
            $mform->hardFreeze('audience');
            $mform->addElement('static', 'sharednote', '', get_string('kb_shared_fields_note', 'local_moodesk'));
        }

        // Language (KB-06, Pro). Hidden on a translation being started — the
        // panel's select chose it — and absent altogether in Starter.
        if ($languages && !$translate) {
            $mform->addElement('select', 'lang', get_string('kb_field_lang', 'local_moodesk'), $languages);
            $mform->setType('lang', PARAM_LANG);
            $mform->addHelpButton('lang', 'kb_field_lang', 'local_moodesk');
        } else if ($languages) {
            $mform->addElement('hidden', 'lang');
            $mform->setType('lang', PARAM_LANG);
        }

        // Body. No trusttext, no noclean — see the class comment.
        $mform->addElement(
            'editor',
            'body',
            get_string('kb_field_body', 'local_moodesk'),
            ['rows' => 18],
            self::editor_options()
        );
        $mform->setType('body', PARAM_RAW);

        $this->add_action_buttons(
            true,
            $id > 0
                ? get_string('savechanges')
                : get_string('kb_save_draft', 'local_moodesk')
        );
    }

    /**
     * The editor's file options: the service's, plus what the element itself needs.
     *
     * @return array
     */
    public static function editor_options(): array {
        global $CFG;
        // FILE_INTERNAL only: an image in an article lives in the article's
        // file area, so it is served through the policy like the text is —
        // not linked from wherever it happened to be when the author wrote.
        return kb_service::body_file_options() + [
            'maxbytes'     => $CFG->maxbytes,
            'return_types' => \FILE_INTERNAL,
        ];
    }

    /**
     * The payload for the service, from what the form validated.
     *
     * Title, body and the editor's draft area always; category and audience
     * only when the form offered them — an element frozen by definition()
     * (a row with siblings, a translation being started, a live article for
     * an editor without :managekb) is left out, because formslib exports the
     * definition default for a hard-frozen element and that default is not
     * what the user asked for; language only when the field exists (Pro) and
     * carries a code.
     *
     * @param \stdClass $formdata What get_data() returned.
     * @return \stdClass title, body, draftitemid, and any of categoryid, audience, lang.
     */
    public function to_service_data(\stdClass $formdata): \stdClass {
        $mform = $this->_form;

        $data              = new \stdClass();
        $data->title       = (string) ($formdata->title ?? '');
        $data->body        = (string) ($formdata->body['text'] ?? '');
        $data->draftitemid = (int) ($formdata->body['itemid'] ?? 0);

        foreach (['categoryid', 'audience'] as $field) {
            if (isset($formdata->{$field}) && $mform->elementExists($field) && !$mform->isElementFrozen($field)) {
                $data->{$field} = (int) $formdata->{$field};
            }
        }
        if ($mform->elementExists('lang') && !empty($formdata->lang)) {
            $data->lang = (string) $formdata->lang;
        }

        return $data;
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

        if (trim((string) ($data['title'] ?? '')) === '') {
            $errors['title'] = get_string('required');
        }

        return $errors;
    }
}
