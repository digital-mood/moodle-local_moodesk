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

namespace local_moodesk\output;

use local_moodesk\form\submit_ticket_form;
use local_moodesk\service\department_service;
use local_moodesk\service\edition_service;
use local_moodesk\service\kb_service;

/**
 * New-ticket drawer: the right-anchored overlay panel that hosts the ticket
 * submission form on the list and dashboard screens.
 *
 * This class owns the *setup* of the submission form (option loading, draft file
 * area, form instantiation) so that submit.php and the drawer build exactly the
 * same form instead of duplicating the wiring. submit.php stays the canonical
 * POST target and the no-JS fallback: the drawer's form action points at it, so
 * sesskey, server-side validation, draft areas and the success redirect are the
 * existing Moodle flow, untouched.
 *
 * The drawer only ever restyles moodleform's own markup (ADR §4) — it does not
 * replace it — so validation and CSRF stay intact.
 *
 * What it no longer hosts is Moodle's editor and its file manager. The message
 * is a plain textarea and the attachments are the composer's own button and
 * chips over Moodle's file picker, which is the same pair of decisions the reply
 * composer made (ADR: docs/reply-composer-attachments.md, extended to this form).
 * The draft area, the upload, every limit and the save on submit are still
 * Moodle's; only the widgets are gone.
 *
 * The trade that comes with it: without JavaScript a ticket can still be raised
 * from submit.php, but not with files attached — the picker is what uploads
 * them. The message itself is unaffected.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class new_ticket_drawer {
    /** @var \context The system context the form is built against. */
    protected \context $context;

    /** @var submit_ticket_form The submission form shared with submit.php. */
    protected submit_ticket_form $form;

    /** @var bool Whether file attachments are enabled site-wide. */
    protected bool $attachmentsenabled;

    /** @var int Max upload size in bytes (0 = site default). */
    protected int $maxbytes;

    /** @var int Max number of files per ticket. */
    protected int $maxfiles;

    /** @var int|null Draft area the attachments live in until the ticket is created. */
    protected ?int $draftitemid = null;

    /** @var string|null File picker client id, unique per render. */
    protected ?string $fpclientid = null;

    /** @var bool Whether this form offers knowledge base suggestions (KB-04). */
    protected bool $suggestionsenabled;

    /**
     * Build the form and everything it depends on.
     *
     * @param int         $courseid    Optional course context carried through the form.
     * @param string|null $submitlabel Submit button label; null uses the drawer's own.
     * @param int         $categoryid  A category to preselect instead of the site default,
     *                                 or 0. Honoured only when it is one of the active
     *                                 categories the form offers; anything else falls back.
     */
    protected function __construct(int $courseid = 0, ?string $submitlabel = null, int $categoryid = 0) {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');

        $this->context = \context_system::instance();

        $categories        = $DB->get_records_menu('local_moodesk_category', ['visible' => 1], 'sortorder ASC', 'id,name');
        $defaultcategoryid = (int) get_config('local_moodesk', 'defaultcategoryid');
        // A preselection is a hint from a link ("open a ticket about this
        // article"), not a fact: it is checked against the active categories,
        // so an archived id or a made-up one lands on the site default.
        if ($categoryid > 0 && array_key_exists($categoryid, $categories)) {
            $defaultcategoryid = $categoryid;
        }

        $departments = department_service::create()->get_active_menu();

        $this->attachmentsenabled = (bool) get_config('local_moodesk', 'attachments_enabled');
        $this->maxbytes           = (int) get_config('local_moodesk', 'max_attachment_size');
        $this->maxfiles           = (int) get_config('local_moodesk', 'max_files_per_attachment') ?: 5;

        // Suggested articles (KB-04) go with the knowledge base as the reader
        // sees it: the feature, the kb_enabled switch and :viewkb. The same
        // three answers kb.php gives, so a form never offers what the
        // knowledge base itself would refuse to show.
        $this->suggestionsenabled = edition_service::has_feature('knowledge_base')
            && kb_ui::is_enabled()
            && has_capability('local/moodesk:viewkb', $this->context);

        // The form always posts to submit.php: on the list and dashboard screens
        // that is a cross-page POST, on submit.php it posts back to itself, so a
        // failed validation re-renders there with the errors. No query string —
        // moodleform turns URL parameters into hidden inputs, and the form
        // already carries courseid as one of its own.
        $action = new \moodle_url('/local/moodesk/submit.php');

        $this->form = new submit_ticket_form($action, [
            'categories'          => $categories,
            'defaultcategoryid'   => $defaultcategoryid,
            'departments'         => $departments,
            'courseid'            => $courseid,
            'attachments_enabled' => $this->attachmentsenabled,
            'draftitemid'         => $this->get_draft_itemid(),
            'maxbytes'            => $this->maxbytes,
            'maxfiles'            => $this->maxfiles,
            'attachments_ui'      => $this->render_attachments_control(),
            'suggestions_ui'      => $this->render_suggestions_block(),
            'submitlabel'         => $submitlabel ?? get_string('ticket_create', 'local_moodesk'),
        ]);
    }

    /**
     * The "Suggested articles" block, ready for the form to place (KB-04).
     *
     * Through the form for the same reason the attach control is: submit.php
     * renders the bare form with no drawer around it, so anything the drawer's
     * template owned would be missing from the page. Coming through the form
     * means it lands between the category row and the description on both
     * mounts, and there is one place it can be changed.
     *
     * Server-side this is a heading, an empty body and a live region. The
     * module fills it as the subject is typed; without JavaScript it is a
     * heading and nothing else, which is honest — nothing is going to appear.
     *
     * The subject is named by moodleform's own id (`id_subject`); the category
     * by its field name, looked up inside the subject's form, because the
     * hidden input a single-category site renders carries no id — and it is
     * still the category the ticket will be filed under.
     *
     * @return string HTML, or '' when this form offers no suggestions.
     */
    protected function render_suggestions_block(): string {
        global $OUTPUT;

        if (!$this->suggestionsenabled) {
            return '';
        }

        return $OUTPUT->render_from_template('local_moodesk/kb_suggestions', [
            'rootid'        => 'mhd-kb-suggest-' . uniqid(),
            'subjectid'     => 'id_subject',
            'categoryname'  => 'categoryid',
            'minlength'     => kb_service::MIN_TOKEN_LENGTH,
            'debounce'      => 300,
            'heading'       => get_string('kb_suggest_heading', 'local_moodesk'),
            'intro'         => get_string('kb_suggest_intro', 'local_moodesk'),
            'subjecthint'   => get_string('kb_suggest_subject_hint', 'local_moodesk'),
            'updating'      => get_string('kb_suggest_updating', 'local_moodesk'),
            'strloading'    => get_string('kb_suggest_loading', 'local_moodesk'),
            'strnone'       => get_string('kb_suggest_none', 'local_moodesk'),
            'strerror'      => get_string('kb_suggest_error', 'local_moodesk'),
            'strresults'    => get_string('kb_suggest_results', 'local_moodesk', '{$a}'),
            'strresultsone' => get_string('kb_suggest_results_one', 'local_moodesk'),
        ]);
    }

    /**
     * The attach button and its file list, ready for the form to place.
     *
     * Rendered here rather than in the drawer's own template because the two
     * mounts have to agree: submit.php renders the bare form with no drawer
     * around it, so anything the drawer's template owned would be missing from
     * the page. Coming through the form means it lands directly under the
     * message box on both, and there is one place it can be changed.
     *
     * The ids are derived from the picker's client id, which is unique per
     * render — the ticket list can hold this drawer and the ticket detail
     * drawer's composer at the same time, and both regions have to be
     * addressable on their own.
     *
     * @return string HTML, or '' when this form takes no attachments.
     */
    protected function render_attachments_control(): string {
        global $OUTPUT;

        $config = $this->attachments_config();
        if ($config === null) {
            return '';
        }

        return $OUTPUT->render_from_template('local_moodesk/composer_attachments', [
            'rootid'   => $config['rootid'],
            'panelid'  => $config['rootid'] . '-panel',
            'label'    => get_string('attachment_attach_files', 'local_moodesk'),
            'maxfiles' => $this->maxfiles,
            'config'   => json_encode($config),
        ]);
    }

    /**
     * The draft area this form's attachments live in until the ticket is created.
     *
     * Allocated once per render and shared by the form's hidden field and the
     * JavaScript config, so both name the same area — the whole point being that
     * the file picker uploads into exactly the draft the submit will read.
     *
     * Empty rather than prepared. `file_prepare_draft_area()` copies an existing
     * file area into a fresh draft, which is what an *edit* form needs; a ticket
     * being written for the first time has nothing to copy, so all that call did
     * here was mint an itemid the long way round.
     *
     * A submission that fails validation is re-rendered on the same request that
     * carried the POST, and the files the user already uploaded are sitting in
     * the draft that POST names. Allocating a fresh area there would strand
     * them: the form's hidden field is repopulated from the submitted value, so
     * the screen and its JavaScript would be pointing at two different areas.
     * Taking the submitted id back is what the file manager element does too —
     * it only mints a new one when its value is empty
     * (lib/form/filemanager.php). The id is safe to accept: every use of it
     * resolves against this user's own context, here and in the endpoints.
     *
     * Zero when attachments are off: no area is allocated for a form that cannot
     * accept files.
     *
     * @return int
     */
    protected function get_draft_itemid(): int {
        global $CFG;

        if ($this->draftitemid === null) {
            require_once($CFG->libdir . '/filelib.php');

            if (!$this->attachmentsenabled) {
                $this->draftitemid = 0;
            } else {
                $submitted = optional_param('attachments', 0, PARAM_INT);
                $this->draftitemid = $submitted > 0 ? $submitted : file_get_unused_draft_itemid();
            }
        }

        return $this->draftitemid;
    }

    /**
     * Everything the composer's attachment UI needs to drive Moodle's file picker.
     *
     * The form renders its own attach button and its own chips instead of a file
     * manager, and opens Moodle's file picker against the draft area above
     * (ADR: docs/reply-composer-attachments.md). Moodle keeps ownership of the
     * upload, the repositories, the limits and the File API; what we own is the
     * button.
     *
     * The picker options are built exactly the way core builds them — the same
     * initialise_filepicker() call form_filemanager makes, then the same fields
     * FileManagerHelper sets on it before showing the picker
     * (lib/form/filemanager.js). Calling initialise_filepicker() also queues
     * `M.core_filepicker.set_templates`, and js_init_call infers the
     * `core_filepicker` module from that function name, which is what actually
     * loads the picker's YUI module.
     *
     * `client_id` is unique per render on purpose. The picker caches instances
     * by client id and only refreshes their formcallback on later shows
     * (repository/filepicker.js) — and this drawer shares its screen with the
     * ticket detail drawer, which runs a composer of its own, so a shared id
     * would upload a new ticket's file into a reply's draft.
     *
     * `ticketid` is 0 and stays 0: there is no ticket yet. The endpoint reads
     * that as the new-ticket scope and gates on :submit instead of on a ticket's
     * own visibility (ajax.php).
     *
     * @return array|null Config for the composer_attachments module, or null when
     *                    this form takes no attachments.
     */
    public function attachments_config(): ?array {
        global $CFG;

        if (!$this->attachmentsenabled) {
            return null;
        }

        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/repository/lib.php');

        if ($this->fpclientid === null) {
            $this->fpclientid = uniqid();
        }

        // Same ceiling the file manager element resolves: the smallest of the
        // plugin's setting, the site maximum and what PHP will actually accept.
        $maxbytes = $this->maxbytes > 0
            ? get_user_max_upload_file_size($this->context, $CFG->maxbytes, $this->maxbytes)
            : get_user_max_upload_file_size($this->context, $CFG->maxbytes);

        // Types and return types are left at the element's own defaults, so the
        // set of files a ticket accepts is unchanged by this rework.
        $params                 = new \stdClass();
        $params->accepted_types = '*';
        $params->return_types   = FILE_INTERNAL | FILE_REFERENCE | FILE_CONTROLLED_LINK;
        $params->context        = $this->context;
        $params->env            = 'filemanager';
        $params->disable_types  = [];

        $fpoptions = initialise_filepicker($params);

        $fpoptions->client_id    = $this->fpclientid;
        $fpoptions->env          = 'filemanager';
        $fpoptions->itemid       = $this->get_draft_itemid();
        $fpoptions->savepath     = '/';
        $fpoptions->maxfiles     = $this->maxfiles;
        $fpoptions->maxbytes     = $maxbytes;
        $fpoptions->areamaxbytes = FILE_AREA_MAX_BYTES_UNLIMITED;
        // The picker reads nothing but the id off this, so it is all we send.
        $fpoptions->context = (object) ['id' => $this->context->id];

        return [
            'clientid'    => $this->fpclientid,
            // The region the module wires. Two composers can share a screen, so
            // it is told which one is its own rather than taking the first match
            // in the document.
            'rootid'      => 'mhd-attach-' . $this->fpclientid,
            'draftitemid' => $this->get_draft_itemid(),
            'ticketid'    => 0,
            'maxfiles'    => $this->maxfiles,
            'ajaxurl'     => (new \moodle_url('/local/moodesk/ajax.php'))->out(false),
            'sesskey'     => sesskey(),
            'filepicker'  => $fpoptions,
        ];
    }

    /**
     * Build the drawer for the current user, or null when they cannot submit.
     *
     * @param int         $courseid    Optional course context carried through the form.
     * @param string|null $submitlabel Submit button label; null uses the drawer's
     *                                 own "Create ticket". submit.php passes its
     *                                 page label so that screen reads unchanged.
     * @param int         $categoryid  Category to preselect, or 0 for the site default.
     *                                 Ignored unless it is an active category.
     * @return self|null Null when the user lacks local/moodesk:submit.
     */
    public static function create(int $courseid = 0, ?string $submitlabel = null, int $categoryid = 0): ?self {
        if (!has_capability('local/moodesk:submit', \context_system::instance())) {
            return null;
        }

        return new self($courseid, $submitlabel, $categoryid);
    }

    /**
     * The submission form, ready to process or render.
     *
     * @return submit_ticket_form
     */
    public function get_form(): submit_ticket_form {
        return $this->form;
    }

    /**
     * Let plugins save the fields they added, once the ticket exists (hook ticket_form_extend).
     *
     * @param int       $ticketid The ticket just created.
     * @param \stdClass $formdata What get_form()->get_data() returned.
     * @param int       $userid   The user who submitted it.
     */
    public function save_plugin_fields(int $ticketid, \stdClass $formdata, int $userid): void {
        \core\di::get(\core\hook\manager::class)->dispatch(
            \local_moodesk\hook\ticket_form_extend::for_save($ticketid, (array) $formdata, $userid)
        );
    }

    /**
     * Whether attachments are enabled site-wide.
     *
     * @return bool
     */
    public function attachments_enabled(): bool {
        return $this->attachmentsenabled;
    }

    /**
     * Whether this form offers knowledge base suggestions (KB-04).
     *
     * @return bool
     */
    public function suggestions_enabled(): bool {
        return $this->suggestionsenabled;
    }

    /**
     * File area options shared by the draft area and the final save.
     *
     * @return array Options accepted by file_prepare_draft_area/file_save_draft_area_files.
     */
    public function get_attachment_options(): array {
        global $CFG;

        return [
            'subdirs'  => 0,
            'maxbytes' => $this->maxbytes ?: $CFG->maxbytes,
            'maxfiles' => $this->maxfiles,
        ];
    }

    /**
     * Render the drawer markup and queue its behaviour module.
     *
     * The panel is always present in the DOM (hidden with visibility, never
     * display:none) so its controls initialise against a measurable container —
     * which the message box needs, since it sizes itself from what is written
     * into it and an element with no layout reports no height to grow from.
     *
     * @param \moodle_page $page Usually the global $PAGE.
     * @return string Drawer HTML, to be appended to the page content.
     */
    public function render(\moodle_page $page): string {
        global $OUTPUT;

        $page->requires->js_call_amd('local_moodesk/ticket_drawer', 'init');
        $this->require_composer_js($page);

        return $OUTPUT->render_from_template('local_moodesk/new_ticket_drawer', [
            'title'    => get_string('page_submit_heading', 'local_moodesk'),
            'subtitle' => get_string('submit_intro', 'local_moodesk'),
            'closelabel' => get_string('drawer_close', 'local_moodesk'),
            'unsavedconfirm' => get_string('detail_unsaved_confirm', 'local_moodesk'),
            'form'     => $this->form->render(),
        ]);
    }

    /**
     * Queue the behaviour the form's own controls need.
     *
     * Both mounts call this — the drawer from render(), submit.php for the bare
     * form — because the controls belong to the form, not to the panel around
     * it. Without it the markup is inert: the message box does not grow and the
     * attach button opens nothing.
     *
     * The attachment module is only queued when there is something for it to
     * wire. With uploads off site-wide the control was never rendered, and
     * asking the module to find it would be asking for a region that is not
     * there.
     *
     * @param \moodle_page $page Usually the global $PAGE; carries the JS requirements.
     */
    public function require_composer_js(\moodle_page $page): void {
        $page->requires->js_call_amd('local_moodesk/composer_autogrow', 'init');

        // No arguments: the module reads each control's configuration off the
        // element it is wiring. The picker options alone run well past the 1024
        // characters Moodle accepts as a js_call_amd argument.
        if ($this->attachmentsenabled) {
            $page->requires->js_call_amd('local_moodesk/composer_attachments', 'init');
        }

        // Same rule: no block rendered, no module asked to find it.
        if ($this->suggestionsenabled) {
            $page->requires->js_call_amd('local_moodesk/kb_suggestions', 'init');
        }
    }
}
