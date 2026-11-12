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

use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;
use local_moodesk\form\reply_form;
use local_moodesk\policy\kb_access_policy;
use local_moodesk\service\category_service;
use local_moodesk\service\department_service;
use local_moodesk\service\edition_service;
use local_moodesk\service\kb_service;
use local_moodesk\policy\ticket_access_policy;
use local_moodesk\service\reply_service;
use local_moodesk\service\sla_service;
use local_moodesk\service\ticket_service;
use local_moodesk\util\user_fields;

/**
 * The ticket detail screen: header, conversation, management rail and composer.
 *
 * One component, two mounts. view.php renders it as the canonical page and the
 * ticket list renders the very same object inside an overlay drawer through the
 * fragment callback in lib.php, so there is exactly one place where access,
 * gating and context building happen. The only difference between the two is
 * the `variant`, which swaps the breadcrumb for a close button and an
 * "Open full page" link.
 *
 * Everything this class exposes is read-only. State changes keep going through
 * ticket_page_handler (POST) and ajax.php (auto-save), untouched.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_detail {
    /** @var string Canonical full-page mount (view.php). */
    public const VARIANT_PAGE = 'page';

    /** @var string Overlay drawer mount (ticket list). */
    public const VARIANT_DRAWER = 'drawer';

    /** @var int The ticket being displayed. */
    protected int $ticketid;

    /** @var int The user looking at it. */
    protected int $userid;

    /** @var \stdClass The ticket record (access already checked). */
    protected \stdClass $ticket;

    /** @var \context The system context this plugin lives in. */
    protected \context $context;

    /** @var bool Whether the viewer holds local/moodesk:viewall. */
    protected bool $isagent;

    /** @var bool Whether the viewer holds local/moodesk:manage. */
    protected bool $canmanage;

    /** @var bool Whether the viewer holds local/moodesk:addnote. */
    protected bool $canadnote;

    /** @var bool Whether the ticket is in a terminal status. */
    protected bool $isterminal;

    /** @var bool Whether the ticket was merged away and is read-only. */
    protected bool $ismerged;

    /** @var bool Whether the viewer may post a public reply. */
    protected bool $canreply;

    /** @var bool Whether site-wide attachments are enabled. */
    protected bool $attachenabled;

    /** @var int Max upload size in bytes (0 = site default). */
    protected int $maxbytes;

    /** @var int Max files per reply. */
    protected int $maxfiles;

    /** @var reply_form|null The composer form, built on first use. */
    protected ?reply_form $replyform = null;

    /** @var int|null Draft area holding this composer's unsent attachments. */
    protected ?int $draftitemid = null;

    /** @var string|null File picker client id, unique to this render. */
    protected ?string $fpclientid = null;

    /**
     * Load the ticket and resolve everything the viewer is allowed to do with it.
     *
     * @param int $ticketid Ticket to display.
     * @param int $userid   Viewer.
     */
    protected function __construct(int $ticketid, int $userid) {
        $this->ticketid = $ticketid;
        $this->userid   = $userid;
        $this->context  = \context_system::instance();

        // Access is enforced inside the service, exactly as view.php did.
        $this->ticket = ticket_service::create()->get_ticket($ticketid, $userid);

        $this->isagent   = has_capability('local/moodesk:viewall', $this->context);
        $this->canmanage = has_capability('local/moodesk:manage', $this->context);

        $this->isterminal = ticket::is_terminal((int) $this->ticket->status);
        $lock = new \local_moodesk\hook\ticket_lock($this->ticket);
        \core\di::get(\core\hook\manager::class)->dispatch($lock);
        $this->ismerged   = $lock->is_locked();

        // What the viewer may write is the policy's answer; what state the
        // ticket is in is this screen's. Asking :viewall here was the older
        // shape, and it offered a composer to every agent who could see the
        // ticket — including one holding no :reply at all, whose send the
        // service then refused. A screen must not offer an operation its
        // service will reject.
        $policy          = new ticket_access_policy();
        $this->canadnote = $policy->can_add_note($userid, $this->ticket);
        $this->canreply  = !$this->ismerged
            && !$this->isterminal
            && $policy->can_reply($userid, $this->ticket);

        $this->attachenabled = (bool) get_config('local_moodesk', 'attachments_enabled');
        $this->maxbytes      = (int) get_config('local_moodesk', 'max_attachment_size');
        $this->maxfiles      = (int) get_config('local_moodesk', 'max_files_per_attachment') ?: 5;
    }

    /**
     * Build the detail for a ticket the viewer is allowed to see.
     *
     * @param int $ticketid Ticket to display.
     * @param int $userid   Viewer.
     * @return self
     * @throws \moodle_exception When the ticket does not exist or is out of reach.
     */
    public static function create(int $ticketid, int $userid): self {
        return new self($ticketid, $userid);
    }

    /**
     * The ticket record behind this screen.
     *
     * @return \stdClass
     */
    public function get_ticket(): \stdClass {
        return $this->ticket;
    }

    /**
     * Whether a composer should be rendered at all.
     *
     * @return bool
     */
    public function has_composer(): bool {
        return ($this->canreply || $this->canadnote) && !$this->isterminal && !$this->ismerged;
    }

    /**
     * The reply/note form, built once.
     *
     * Starts as PUBLIC_REPLY; the JS toggle flips the hidden replytype field to
     * TYPE_INTERNAL_NOTE. The action URL is unchanged from the standalone page,
     * so a reply raised in the drawer posts through the same handler and lands
     * on the canonical page with the thread already updated.
     *
     * @return reply_form
     */
    public function get_reply_form(): reply_form {
        if ($this->replyform === null) {
            $this->replyform = new reply_form(
                new \moodle_url('/local/moodesk/view.php', [
                    'id'      => $this->ticketid,
                    'action'  => 'reply',
                    'sesskey' => sesskey(),
                ]),
                [
                    'ticketid'            => $this->ticketid,
                    // The hidden replytype the form starts on. It is what a
                    // submit carries when the toggle never runs — so a viewer
                    // who may only add notes has to start on the note, or the
                    // composer posts a public reply the service will refuse.
                    'type'                => $this->canreply
                        ? reply::TYPE_PUBLIC_REPLY
                        : reply::TYPE_INTERNAL_NOTE,
                    'attachments_enabled' => $this->attachenabled,
                    'draftitemid'         => $this->get_draft_itemid(),
                    'maxbytes'            => $this->maxbytes,
                    'maxfiles'            => $this->maxfiles,
                    'can_add_note'        => $this->canadnote,
                ]
            );
        }

        return $this->replyform;
    }

    /**
     * The draft area this composer's attachments live in until the reply is sent.
     *
     * Allocated once per render and shared by the form's hidden field and the
     * JavaScript config, so both name the same area — the whole point being that
     * the file picker uploads into exactly the draft the submit will read.
     *
     * Zero when attachments are off: no area is allocated for a composer that
     * cannot accept files.
     *
     * A reply that fails validation is re-rendered on the same request that
     * carried the POST, and the files the user already uploaded are sitting in
     * the draft that POST names. Allocating a fresh area there would strand
     * them: the form's hidden field is repopulated from the submitted value, so
     * the screen and its JavaScript would be pointing at two different areas.
     * Taking the submitted id back is what the file manager element does too —
     * it only mints a new one when its value is empty
     * (lib/form/filemanager.php). The id is safe to accept: every use of it
     * resolves against this user's own context, here and in the endpoints.
     *
     * @return int
     */
    protected function get_draft_itemid(): int {
        global $CFG;

        if ($this->draftitemid === null) {
            require_once($CFG->libdir . '/filelib.php');

            if (!$this->attachenabled) {
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
     * The composer renders its own attach button and its own chips instead of a
     * file manager, and opens Moodle's file picker against the draft area above
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
     * (repository/filepicker.js), so a shared id would upload one ticket's file
     * into another ticket's draft.
     *
     * @return array|null Config for the composer_attachments module, or null when
     *                    this screen has no composer or takes no attachments.
     */
    protected function attachments_config(): ?array {
        global $CFG;

        if (!$this->attachenabled || !$this->has_composer()) {
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
        // set of files a reply accepts is unchanged by this rework.
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
            'ticketid'    => $this->ticketid,
            'maxfiles'    => $this->maxfiles,
            'ajaxurl'     => (new \moodle_url('/local/moodesk/ajax.php'))->out(false),
            'sesskey'     => sesskey(),
            'filepicker'  => $fpoptions,
        ];
    }

    /**
     * Render the detail.
     *
     * @param \moodle_page $page    Usually the global $PAGE; carries the JS requirements.
     * @param string       $variant self::VARIANT_PAGE or self::VARIANT_DRAWER.
     * @return string HTML.
     */
    public function render(\moodle_page $page, string $variant = self::VARIANT_PAGE): string {
        global $OUTPUT;

        $page->requires->js_call_amd('local_moodesk/reply_toggle', 'init');
        $page->requires->js_call_amd('local_moodesk/sidebar_actions', 'init');
        $page->requires->js_call_amd('local_moodesk/composer_autogrow', 'init');

        // Conversation and Details as two tabs, where the layout has stopped
        // being two columns. Both mounts, and the module reads which one it is
        // in — they collapse at different widths, because the shell's sidebar
        // is taking window on the page and the panel is a fixed width in the
        // drawer. It stands down entirely above those widths, and where there
        // is no rail there is no tab strip to wire.
        //
        // Queued on every render, which is what the drawer needs: it replaces
        // the detail rather than updating it, so the tabs have to be wired
        // again against the markup that just arrived. init() is idempotent and
        // releases the details that went with the old markup.
        $page->requires->js_call_amd('local_moodesk/detail_tabs', 'init');

        // Both mounts load it; only the drawer mount acts on it. The module
        // stands down when the composer is not inside a panel, which is what
        // keeps view.php on the plain POST it has always used.
        $page->requires->js_call_amd('local_moodesk/reply_submit', 'init');

        // Both mounts get the same composer, so both get the same attachment
        // behaviour. There is no second experience to keep in step.
        //
        // No arguments: the module reads the configuration off the region it is
        // wiring, and leaves regions it has already wired alone — so loading a
        // ticket into the drawer does not disturb the New ticket form that
        // shares the screen.
        if ($this->attachments_config() !== null) {
            $page->requires->js_call_amd('local_moodesk/composer_attachments', 'init');
        }

        return $OUTPUT->render_from_template(
            'local_moodesk/ticket_detail',
            $this->export_for_template($variant)
        );
    }

    /**
     * Build the full template context.
     *
     * Key names are the ones the detail templates have always used, so the
     * the merge panel partials keep reading
     * the same context they were written against.
     *
     * @param string $variant self::VARIANT_PAGE or self::VARIANT_DRAWER.
     * @return array
     */
    public function export_for_template(string $variant = self::VARIANT_PAGE): array {
        $isdrawer = ($variant === self::VARIANT_DRAWER);

        $ctx  = $this->base_context($isdrawer);
        $ctx += $this->thread_context();
        $ctx += $this->properties_context();
        $ctx += $this->sla_context();

        // The rail is a management surface: it renders only for :manage. Any
        // one of its cards can still be absent on its own gate.
        $ctx['hasrail'] = $this->canmanage;

        $ctx += $this->composer_context();
        $ctx += $this->plugin_sections_context($isdrawer);

        return $ctx;
    }

    /**
     * Ask plugins for their sections.
     *
     * @param bool $isdrawer True for the drawer mount.
     * @return \local_moodesk\hook\ticket_detail_sections
     */
    public function sections_hook(bool $isdrawer): \local_moodesk\hook\ticket_detail_sections {
        $hook = new \local_moodesk\hook\ticket_detail_sections(
            $this->ticket,
            $this->userid,
            $this->canmanage,
            $isdrawer,
            $this->has_composer(),
            $this->canreply,
            $this->canadnote,
            $this->isagent,
        );
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return $hook;
    }

    /**
     * The sections plugins add to the detail (hook ticket_detail_sections).
     *
     * Rendered here, in the order added, so the templates only print HTML.
     * Every slot comes back as a list and a has-flag; with no section the
     * flags are false and the templates print nothing.
     *
     * @param bool $isdrawer True for the drawer mount.
     * @return array
     */
    protected function plugin_sections_context(bool $isdrawer): array {
        global $OUTPUT;

        $hook = $this->sections_hook($isdrawer);

        $ctx = [];
        $slots = [
            \local_moodesk\hook\ticket_detail_sections::SLOT_SIDEBAR,
            \local_moodesk\hook\ticket_detail_sections::SLOT_COMPOSER,
            \local_moodesk\hook\ticket_detail_sections::SLOT_BANNER,
            \local_moodesk\hook\ticket_detail_sections::SLOT_HEADER,
            \local_moodesk\hook\ticket_detail_sections::SLOT_PROPERTIES,
            \local_moodesk\hook\ticket_detail_sections::SLOT_FIELDS,
            \local_moodesk\hook\ticket_detail_sections::SLOT_ACTIONS,
            \local_moodesk\hook\ticket_detail_sections::SLOT_BADGES,
        ];
        foreach ($slots as $slot) {
            $html = [];
            foreach ($hook->get_sections($slot) as $section) {
                $html[] = ['html' => $OUTPUT->render_from_template($section['template'], $section['context'])];
            }
            $ctx['pluginsections' . $slot] = $html;
            $ctx['haspluginsections' . $slot] = !empty($html);
        }
        $ctx['pluginreadonlyproperties'] = $hook->get_readonly_properties();
        return $ctx;
    }

    /**
     * The composer at the foot of the screen.
     *
     * One moodleform, toggled between public reply and internal note by
     * reply_toggle.js. Rendered (not displayed) so it composes into the
     * template like any other fragment.
     *
     * @return array
     */
    protected function composer_context(): array {
        if (!$this->has_composer()) {
            return ['hascomposer' => false];
        }

        // The toggle only makes sense when the user can do both things; with a
        // single mode the composer states which one it is.
        $hastoggle = $this->canreply && $this->canadnote;

        // Null when uploads are off site-wide, which is also when no attach
        // control renders — the ids below then go nowhere and are never read.
        $attachconfig = $this->attachments_config();

        return [
            'hascomposer'     => true,
            'hastoggle'       => $hastoggle,
            'composerheading' => $hastoggle
                ? ''
                : ($this->canadnote
                    ? get_string('reply_add_note', 'local_moodesk')
                    : get_string('reply_add_public', 'local_moodesk')),
            // With uploads off there is no attach control and no draft area —
            // the affordance is absent, not disabled.
            'hasattach'       => $this->attachenabled,
            // Unique per render. The ticket list hosts the New ticket drawer,
            // which carries an attachment region of its own, so both have to be
            // addressable on their own — the module is handed its region's id
            // rather than taking the first match in the document.
            'attachrootid'    => $attachconfig['rootid'] ?? '',
            'attachpanelid'   => ($attachconfig['rootid'] ?? '') . '-panel',
            // The module reads this off the element rather than taking it as a
            // js_call_amd argument: the picker options alone are past the 1024
            // characters Moodle accepts there.
            'attachconfig'    => $attachconfig === null ? '' : json_encode($attachconfig),
            'maxfiles'        => $this->maxfiles,
            'replyform'       => $this->get_reply_form()->render(),
        ];
    }

    /**
     * Header, identity and chrome.
     *
     * @param bool $isdrawer Whether this is the overlay mount.
     * @return array
     */
    protected function base_context(bool $isdrawer): array {
        global $DB;

        $statuslabels   = ticket::status_labels();
        $prioritylabels = ticket::priority_labels();
        $currentstatus  = (int) $this->ticket->status;
        $currentpriority = (int) $this->ticket->priority;

        // Requester, shown in the header meta line. Resolved through the same
        // fullname() path the assignee and the ticket list already use, so
        // Moodle's name-display rules apply unchanged.
        $requestername = '';
        if (!empty($this->ticket->userid)) {
            $requester = $DB->get_record(
                'user',
                ['id' => $this->ticket->userid],
                user_fields::for_fullname(),
                IGNORE_MISSING
            );
            $requestername = $requester ? fullname($requester) : '';
        }

        $assigneename = '';
        if (!empty($this->ticket->assigneeid)) {
            $agent = $DB->get_record(
                'user',
                ['id' => $this->ticket->assigneeid],
                user_fields::for_fullname(),
                IGNORE_MISSING
            );
            $assigneename = $agent ? fullname($agent) : '';
        }

        $viewurl = (new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]))->out(false);

        return [
            'ticketid'       => $this->ticketid,
            'subject'        => format_string($this->ticket->subject),
            'statuslabel'    => $statuslabels[$currentstatus] ?? $currentstatus,
            'status'         => $currentstatus,
            'prioritylabel'  => $prioritylabels[$currentpriority] ?? $currentpriority,
            'priority'       => $currentpriority,
            'categoryname'   => format_string($this->ticket->categoryname ?? ''),
            'departmentname' => isset($this->ticket->departmentname)
                ? format_string($this->ticket->departmentname)
                : '',
            'hasdepartment'  => !empty($this->ticket->departmentname),
            'assigneename'   => $assigneename,
            'requestername'  => $requestername,
            'hasrequester'   => $requestername !== '',
            'timecreated'    => userdate($this->ticket->timecreated),
            'isagent'        => $this->isagent,
            'canmanage'      => $this->canmanage,
            'canreply'       => $this->canreply && !$this->isterminal,
            'canadnote'      => $this->canadnote,
            'isterminal'     => $this->isterminal,
            'isdrawer'       => $isdrawer,
            'ispage'         => !$isdrawer,
            'viewurl'        => $viewurl,
            'listurl'        => (new \moodle_url('/local/moodesk/index.php'))->out(false),
            'ajaxurl'        => (new \moodle_url('/local/moodesk/ajax.php'))->out(false),
            'sesskey'        => sesskey(),
            'replyactionurl' => (new \moodle_url('/local/moodesk/view.php', [
                'id'      => $this->ticketid,
                'sesskey' => sesskey(),
            ]))->out(false),
            'attachments_enabled' => $this->attachenabled,
        ];
    }

    /**
     * The conversation, including replies reached across merge links.
     *
     * @return array
     */
    protected function thread_context(): array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        // Reads across merge links: a ticket that received merges shows the
        // source conversations interleaved with its own, without any row being
        // copied (design doc §4.3). On a ticket with no merges this is the
        // plain thread.
        $rawthread = reply_service::create()->get_thread_with_linked($this->ticketid, $this->userid);
        $thread    = [];

        // Every reply's attachments in one query, keyed by reply, before the
        // loop. Asking the file store once per message made the detail cost
        // one read per reply — 13 + N on the bench, N being the thread length —
        // whether or not anything was attached (launch readiness plan §10).
        $filesbyreply = $this->attachenabled
            ? $this->attachments_by_reply(array_map(static fn($r): int => (int) $r->id, $rawthread))
            : [];

        foreach ($rawthread as $r) {
            $attachments = [];
            if ($this->attachenabled) {
                foreach ($filesbyreply[(int) $r->id] ?? [] as $file) {
                    $url = \moodle_url::make_pluginfile_url(
                        $this->context->id,
                        'local_moodesk',
                        'reply_attachment',
                        $r->id,
                        '/',
                        $file->get_filename()
                    );
                    $attachments[] = [
                        'filename' => $file->get_filename(),
                        'url'      => $url->out(false),
                        'size'     => display_size($file->get_filesize()),
                    ];
                }
            }

            // Replies reached across a merge link keep their own ticket, so the
            // entry is labelled with where it came from and links back to it.
            $islinked = !empty($r->islinked);

            $thread[] = [
                'id'             => $r->id,
                'authorname'     => $r->authorname,
                'authorid'       => $r->authorid,
                'islinked'       => $islinked,
                'linkedfrom'     => $islinked ? (int) $r->linkedfrom : null,
                'linkedurl'      => $islinked
                    ? (new \moodle_url('/local/moodesk/view.php', ['id' => (int) $r->linkedfrom]))->out(false)
                    : null,
                'isown'          => (int) $r->userid === $this->userid,
                'ispublic'       => (int) $r->type === reply::TYPE_PUBLIC_REPLY,
                'isnote'         => (int) $r->type === reply::TYPE_INTERNAL_NOTE,
                'issystem'       => (int) $r->type === reply::TYPE_SYSTEM_EVENT,
                'message'        => thread_message::format((string) $r->message, (int) $r->messageformat, $this->context),
                'timecreated'    => userdate($r->timecreated),
                'attachments'    => $attachments,
                'hasattachments' => !empty($attachments),
            ];
        }

        return ['thread' => $thread];
    }

    /**
     * The reply_attachment files of a set of replies, in one query.
     *
     * Not get_area_files() with itemid = false: that is the whole area — every
     * attachment of every ticket on the site — and this page needs the files of
     * the replies it is about to show, which may include replies reached across
     * a merge link. One query on the reply ids, then stored_file instances the
     * way the file store builds them; files sorted by name within each reply,
     * as the per-reply call used to return them.
     *
     * @param int[] $replyids
     * @return array<int, \stored_file[]> Keyed by reply id; replies without files are absent.
     */
    private function attachments_by_reply(array $replyids): array {
        global $DB;

        if (empty($replyids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($replyids, SQL_PARAMS_NAMED);
        $params['contextid'] = $this->context->id;

        $records = $DB->get_records_select(
            'files',
            "contextid = :contextid
               AND component = 'local_moodesk'
               AND filearea = 'reply_attachment'
               AND filename <> '.'
               AND itemid {$insql}",
            $params,
            'itemid, filename'
        );

        $fs    = get_file_storage();
        $files = [];
        foreach ($records as $record) {
            $files[(int) $record->itemid][] = $fs->get_file_instance($record);
        }

        return $files;
    }

    /**
     * The Properties card: status, priority, category, department, team, assignee.
     *
     * Every list keeps the rules it had on the sidebar — valid transitions only,
     * department-filtered agents — because the auto-save
     * endpoint re-checks all of them and a select offering an option the server
     * would refuse is a bug, not a shortcut.
     *
     * @return array
     */
    protected function properties_context(): array {
        // A ticket merged away is read-only: the server refuses all six writes
        // (hook before_ticket_write), so the rail shows where the
        // ticket stands instead of controls that can only end in an error. The
        // values come from base_context(); nothing below needs computing.
        if ($this->ismerged) {
            return ['propseditable' => false];
        }

        $statuslabels    = ticket::status_labels();
        $prioritylabels  = ticket::priority_labels();
        $currentstatus   = (int) $this->ticket->status;
        $currentpriority = (int) $this->ticket->priority;
        $currentdeptid   = $this->ticket->departmentid !== null ? (int) $this->ticket->departmentid : null;

        // Status: only the transitions the domain allows out of the current
        // state, plus the current state itself so the select can show where the
        // ticket stands without offering an illegal move.
        $statustransitions = [];
        foreach ((ticket::VALID_TRANSITIONS[$currentstatus] ?? []) as $s) {
            $statustransitions[] = ['value' => $s, 'label' => $statuslabels[$s] ?? $s];
        }

        $priorityoptions = [];
        foreach ($prioritylabels as $val => $label) {
            $priorityoptions[] = [
                'value'    => $val,
                'label'    => $label,
                'selected' => ($val === $currentpriority),
            ];
        }

        $categoryoptions   = [];
        $currentcategoryid = $this->ticket->categoryid !== null ? (int) $this->ticket->categoryid : null;
        if ($this->canmanage) {
            foreach (category_service::create()->get_active_menu() as $catid => $catname) {
                $categoryoptions[] = [
                    'value'    => (int) $catid,
                    'label'    => format_string($catname),
                    'selected' => ((int) $catid === $currentcategoryid),
                ];
            }
        }

        $departmentoptions = [];
        if ($this->canmanage) {
            $departmentoptions[] = [
                'value'    => 0,
                'label'    => get_string('controls_department_none', 'local_moodesk'),
                'selected' => ($currentdeptid === null),
            ];
            foreach (department_service::create()->get_all_active() as $dept) {
                $departmentoptions[] = [
                    'value'    => (int) $dept->id,
                    'label'    => format_string($dept->name),
                    'selected' => ((int) $dept->id === $currentdeptid),
                ];
            }
        }

        // Assignable agents — restricted to department members when the ticket
        // has one, with a fallback so a department without members can still be
        // assigned out of.
        $agentselectoptions = [];
        $nodeptmembers      = false;
        if ($this->canmanage) {
            $ticketservice = ticket_service::create();
            $agents        = $ticketservice->get_assignable_agents($this->userid, $currentdeptid);

            if ($currentdeptid !== null && empty($agents)) {
                $nodeptmembers = true;
                $agents        = $ticketservice->get_assignable_agents($this->userid, null);
            }

            foreach ($agents as $a) {
                $agentselectoptions[] = [
                    'value'    => $a->id,
                    'label'    => fullname($a),
                    'selected' => ((int) ($this->ticket->assigneeid ?? 0) === (int) $a->id),
                ];
            }
        }

        return [
            'propseditable'        => $this->canmanage,
            'statustransitions'    => $statustransitions,
            'hasstatustransitions' => !empty($statustransitions),
            'priorityoptions'      => $priorityoptions,
            'categoryoptions'      => $categoryoptions,
            'hascategoryoptions'   => !empty($categoryoptions),
            'departmentoptions'    => $departmentoptions,
            'hasdepartmentoptions' => !empty($departmentoptions),
            'agentselectoptions'   => $agentselectoptions,
            'hasagents'            => !empty($agentselectoptions),
            'nodeptmembers'        => $nodeptmembers,
        ];
    }

    /**
     * Live SLA figures — agents only, and only while the ticket is still open.
     *
     * @return array
     */
    protected function sla_context(): array {
        $ctx = ['sla_panel' => null, 'hassla_panel' => false];

        if (!$this->canmanage || $this->isterminal) {
            return $ctx;
        }

        $slacontext = (new sla_service())->get_sla_context($this->ticket);
        if (!empty($slacontext['has_sla'])) {
            $ctx['hassla_panel'] = true;
            $ctx['sla_panel']    = $slacontext;
        }

        return $ctx;
    }
}
