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
 * Page controller for view.php POST actions.
 *
 * Handles all mutating requests on the ticket detail page so that view.php
 * acts only as a bootstrap and render entry point. Any POST path that results
 * in a state change is handled here and ends with a redirect (Moodle's
 * redirect() calls die(), so callers never receive a return value on those
 * paths).
 *
 * Usage in view.php (before any output):
 *   $handler = new ticket_page_handler($ticketid, $USER->id, $ticketservice,
 *                                      $replyservice, $canadnote, $canmanage);
 *   $handler->handle();
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\controller;

use local_moodesk\domain\reply;
use local_moodesk\form\reply_form;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;
use local_moodesk\validator\ticket_action_validator;

/**
 * Handles all POST actions originating from the ticket detail page.
 */
class ticket_page_handler {
    /** @var int */
    private int $ticketid;

    /** @var int */
    private int $actorid;

    /** @var ticket_service */
    private ticket_service $ticketservice;

    /** @var reply_service */
    private reply_service $replyservice;

    /** @var bool */
    private bool $canadnote;

    /** @var bool */
    private bool $canmanage;

    /**
     * Build the service with its injected dependencies.
     *
     * @param int            $ticketid
     * @param int            $actorid
     * @param ticket_service $ticketservice
     * @param reply_service  $replyservice
     * @param bool           $canadnote
     * @param bool           $canmanage
     */
    public function __construct(
        int $ticketid,
        int $actorid,
        ticket_service $ticketservice,
        reply_service $replyservice,
        bool $canadnote,
        bool $canmanage
    ) {
        $this->ticketid      = $ticketid;
        $this->actorid       = $actorid;
        $this->ticketservice = $ticketservice;
        $this->replyservice  = $replyservice;
        $this->canadnote     = $canadnote;
        $this->canmanage     = $canmanage;
    }

    /*
     * -------------------------------------------------------------------------
     * Public entry point
     * -------------------------------------------------------------------------
     */

    /**
     * Inspect the current request and dispatch to the appropriate handler.
     *
     * Paths that mutate state all end with redirect(), which calls die().
     * If the request is not a POST, or the action is unknown, this method
     * returns normally and view.php proceeds to render.
     */
    public function handle(): void {
        $action = optional_param('action', '', PARAM_ALPHANUMEXT);

        if (empty($action)) {
            return;
        }

        // Unified reply/note form — both submit to action=reply.
        // The hidden 'replytype' field carries TYPE_PUBLIC_REPLY (0) or TYPE_INTERNAL_NOTE (1).
        if ($action === 'reply') {
            $this->handle_reply_form();
            return; // Only reached if the form was not submitted yet.
        }

        // All remaining actions require an explicit POST + sesskey.
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !confirm_sesskey()) {
            return;
        }

        switch ($action) {
            case 'status':
                $this->handle_status();
                break;
            case 'priority':
                $this->handle_priority();
                break;
            case 'assign':
                $this->handle_assign();
                break;
            case 'department':
                $this->handle_department();
                break;
            case 'updatecategory':
                $this->handle_category();
                break;
            default:
                // Not one of ours: a plugin may own it (hook ticket_actions).
                $this->handle_plugin_action($action);
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Action bodies — shared with ajax.php
     *
     * These run the action and report what happened. They never redirect and
     * never render, which is what lets the drawer reach them: the private
     * handlers below wrap them for the page (redirect + notification), and
     * ajax.php wraps the same methods for the drawer (JSON). One body, two
     * mounts, for the same reason the detail screen is one component — a rule
     * that exists once cannot disagree with itself.
     * -------------------------------------------------------------------------
     */

    /**
     * Process the unified reply/note form submission (action=reply).
     *
     * The actual type (public reply vs internal note) is carried in the form's
     * hidden 'replytype' field, which the JS toggle sets before submission.
     * This method reads replytype from the submitted data, not from the URL.
     *
     * @return array|null Saved: ['replyid', 'replytype', 'message']. Cancelled:
     *                    ['cancelled' => true]. Null when the form has not been
     *                    submitted, or was submitted and did not validate — the
     *                    caller re-renders it, errors and all.
     */
    public function submit_reply(): ?array {
        global $CFG;

        // The composer is a plain textarea, so `message` is text or the request
        // is not one a browser made. Refuse it here, before the form exists.
        //
        // Neither Moodle entry point refuses it for us: mform cleans an array
        // value for a scalar-typed element with clean_param_array() and hands
        // the array on (MoodleQuickForm::getCleanedValue), and required_param()
        // only debugs before deferring to required_param_array(). Everything
        // downstream then has to cope with a value that should never have got
        // that far — the cast that stored the literal "Array" as a reply body,
        // and the textarea re-render that dies inside htmlspecialchars(). This
        // is the point where clean_param() would have thrown, so it is the
        // point that says no.
        // data_submitted() is Moodle's accessor for the raw POST body: it keeps
        // an array an array, which is exactly what this check has to see.
        $submitted = data_submitted();
        if ($submitted && property_exists($submitted, 'message')) {
            ticket_action_validator::require_string($submitted->message, 'message');
        }

        $attachenabled = (bool) get_config('local_moodesk', 'attachments_enabled');
        $maxbytes      = (int)  get_config('local_moodesk', 'max_attachment_size');
        $maxfiles      = (int)  get_config('local_moodesk', 'max_files_per_attachment') ?: 5;
        $context       = \context_system::instance();

        // Instantiate with PUBLIC_REPLY so Moodle form state matches; actual type
        // is read from $formdata->replytype after get_data() succeeds.
        $processform = new reply_form(
            new \moodle_url('/local/moodesk/view.php', [
                'id'      => $this->ticketid,
                'action'  => 'reply',
                'sesskey' => sesskey(),
            ]),
            [
                'ticketid'            => $this->ticketid,
                'type'                => reply::TYPE_PUBLIC_REPLY,
                'attachments_enabled' => $attachenabled,
                'maxbytes'            => $maxbytes,
                'maxfiles'            => $maxfiles,
                'can_add_note'        => $this->canadnote,
            ]
        );

        if ($processform->is_cancelled()) {
            return ['cancelled' => true];
        }

        $formdata = $processform->get_data();
        if ($formdata === null) {
            return null; // Form not yet submitted, or refused by validation.
        }

        // Resolve actual type from the hidden field set by the JS toggle.
        $submittedtype = (int) ($formdata->replytype ?? reply::TYPE_PUBLIC_REPLY);
        $replytype = ($submittedtype === reply::TYPE_INTERNAL_NOTE && $this->canadnote)
            ? reply::TYPE_INTERNAL_NOTE
            : reply::TYPE_PUBLIC_REPLY;

        // The composer is a plain textarea, so the field is a string rather than
        // the editor's ['text' => ..., 'format' => ...] pair, and the format is
        // ours to state rather than the editor's to report. FORMAT_PLAIN is a
        // contract, not a default: what an agent types is stored verbatim and
        // escaped when it is displayed, so no reply written here can inject
        // markup into the thread.
        //
        // Only replies raised through THIS form are plain. The format is stored
        // per row, so replies already in the database keep rendering with the
        // format they were written in — nothing historical is rewritten.
        // No cast: the form has already refused anything that is not text, and
        // reply_service enforces the same contract. Casting here is what turned
        // a hand-built `message[]=foo` into the literal "Array" in the database.
        $msgdata                = new \stdClass();
        $msgdata->message       = $formdata->message ?? '';
        $msgdata->messageformat = FORMAT_PLAIN;

        if ($replytype === reply::TYPE_INTERNAL_NOTE) {
            $replyid = $this->replyservice->add_internal_note($this->ticketid, $msgdata, $this->actorid);
        } else {
            $replyid = $this->replyservice->add_public_reply($this->ticketid, $msgdata, $this->actorid);
        }

        if ($attachenabled && !empty($formdata->attachments)) {
            file_save_draft_area_files(
                (int) $formdata->attachments,
                $context->id,
                'local_moodesk',
                'reply_attachment',
                $replyid,
                ['subdirs' => 0, 'maxbytes' => $maxbytes ?: $CFG->maxbytes, 'maxfiles' => $maxfiles]
            );
        }

        // The detail screen surfaces this as a toast — drained from the
        // notification queue on the page, read off the JSON in the drawer — so
        // the two cases are worth telling apart: an internal note going out as
        // "Reply sent" reads like a leak.
        $confirmation = $replytype === reply::TYPE_INTERNAL_NOTE
            ? get_string('detail_toast_note_added', 'local_moodesk', $this->ticketid)
            : get_string('detail_toast_reply_sent', 'local_moodesk', $this->ticketid);

        return [
            'replyid'   => $replyid,
            'replytype' => $replytype,
            'message'   => $confirmation,
        ];
    }

    /*
     * -------------------------------------------------------------------------
     * Private action handlers — the page's wrapping of the bodies above
     * -------------------------------------------------------------------------
     */

    /**
     * Save the reply and send the page back to the ticket.
     */
    private function handle_reply_form(): void {
        $result = $this->submit_reply();

        if ($result === null) {
            return; // Nothing submitted, or invalid: view.php re-renders the form.
        }

        if (!empty($result['cancelled'])) {
            redirect(new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]));
        }

        redirect(
            new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]),
            $result['message'],
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    /**
     * Handle status transition POST.
     */
    private function handle_status(): void {
        if (!$this->canmanage) {
            return;
        }
        $newstatus = required_param('newstatus', PARAM_INT);
        ticket_action_validator::require_valid_status($newstatus);
        $this->ticketservice->transition_status($this->ticketid, $newstatus, $this->actorid);
        redirect(new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]));
    }

    /**
     * Handle priority change POST.
     */
    private function handle_priority(): void {
        if (!$this->canmanage) {
            return;
        }
        $newpriority = required_param('newpriority', PARAM_INT);
        ticket_action_validator::require_valid_priority($newpriority);
        $this->ticketservice->set_priority($this->ticketid, $newpriority, $this->actorid);
        redirect(new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]));
    }

    /**
     * Handle assignee change POST.
     */
    private function handle_assign(): void {
        if (!$this->canmanage) {
            return;
        }
        $assigneeid = optional_param('assigneeid', null, PARAM_INT);
        $this->ticketservice->assign($this->ticketid, $assigneeid ?: null, $this->actorid);
        redirect(new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]));
    }

    /**
     * Handle department change POST.
     */
    private function handle_department(): void {
        if (!$this->canmanage) {
            return;
        }
        $newdeptid = optional_param('departmentid', null, PARAM_INT);
        $this->ticketservice->set_department($this->ticketid, $newdeptid ?: null, $this->actorid);
        redirect(new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]));
    }

    /**
     * Handle category change POST.
     */
    private function handle_category(): void {
        if (!$this->canmanage) {
            return;
        }
        $newcategoryid = required_param('categoryid', PARAM_INT);
        ticket_action_validator::require_positive_int($newcategoryid, 'categoryid');
        $this->ticketservice->set_category($this->ticketid, $newcategoryid, $this->actorid);
        redirect(new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]));
    }

    /**
     * Offer an action mooDesk does not know to plugins (hook ticket_actions).
     *
     * The body both entry points share: view.php wraps it in a redirect,
     * ajax.php in JSON. Reach to the ticket was checked by the caller; what the
     * action needs beyond that is the plugin's to check.
     *
     * @param string $action The action name.
     * @return array{message: string, data: array}|null Null when no plugin handled it.
     */
    public function run_plugin_action(string $action): ?array {
        $hook = new \local_moodesk\hook\ticket_actions(
            $action,
            $this->ticketid,
            $this->actorid,
            $this->canadnote,
            $this->canmanage
        );
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        if (!$hook->is_handled()) {
            return null;
        }
        return ['message' => $hook->get_message(), 'data' => $hook->get_data()];
    }

    /**
     * Page wrapper for run_plugin_action(): run it, then redirect and report.
     *
     * When no plugin handles the action, returns and the page renders, which
     * is what an unknown action has always done.
     *
     * @param string $action
     */
    private function handle_plugin_action(string $action): void {
        $ticketurl = new \moodle_url('/local/moodesk/view.php', ['id' => $this->ticketid]);

        try {
            $result = $this->run_plugin_action($action);
        } catch (\moodle_exception $e) {
            redirect($ticketurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        if ($result === null) {
            return;
        }

        redirect($ticketurl, $result['message'], null, \core\output\notification::NOTIFY_SUCCESS);
    }
}
