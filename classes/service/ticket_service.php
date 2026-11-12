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
 * Ticket application service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\ticket;
use local_moodesk\domain\bulk_result;
use local_moodesk\domain\reply;
use local_moodesk\domain\ticket_history;
use local_moodesk\domain\department;
use local_moodesk\hook\before_ticket_write;
use local_moodesk\policy\ticket_access_policy;
use local_moodesk\repository\ticket_repository;
use local_moodesk\repository\reply_repository;
use local_moodesk\repository\ticket_history_repository;
use local_moodesk\repository\department_repository;
use local_moodesk\repository\category_repository;
use local_moodesk\service\edition_service;
use local_moodesk\event\ticket_created;
use local_moodesk\event\ticket_field_changed;
use local_moodesk\event\ticket_status_changed;
use local_moodesk\event\ticket_assigned;
use local_moodesk\util\user_fields;

/**
 * Ticket application service.
 *
 * CONTRACT:
 *  - All ticket business logic lives here.
 *  - Every public method that mutates state must:
 *      1. Check capabilities first, before any data access.
 *      2. Execute mutations inside a delegated transaction.
 *      3. Write a history entry.
 *      4. Emit an event after the transaction commits.
 *  - No $DB access directly. All data access goes through repositories.
 *
 * PHASE 3 CHANGES:
 *  - create_ticket() accepts optional departmentid.
 *  - get_all_tickets() accepts a $view parameter for tabbed agent workspace.
 *  - get_assignable_agents() accepts optional $departmentid to restrict to
 *    agents who are members of that department.
 *  - set_department() — new method to change ticket's department.
 */
class ticket_service {
    /** Number of tickets per page in list views. */
    const PAGE_SIZE = 30;

    /** Declared width of local_moodesk_ticket.subject (see db/install.xml). */
    const SUBJECT_COLUMN_WIDTH = 255;

    /** Bulk status change; {@see apply_bulk()}. */
    const BULK_ACTION_STATUS = 'status';

    /** Bulk assignment or unassignment; {@see apply_bulk()}. */
    const BULK_ACTION_ASSIGN = 'assign';

    /**
     * Most tickets one bulk request may carry.
     *
     * The batch runs inside one web request and every ticket may send an email
     * over its own SMTP session — ~0.4–0.7 s each against a real relay
     * (docs/lab/bulk-bench-2026-10-01.md) — so this is a timeout bound, not a
     * page size. Thirty keeps the worst case near 20 s, under PHP's default
     * 30 s. It is deliberately not PAGE_SIZE: a longer page must not raise
     * the cap with it (ticket_bulk_test pins PAGE_SIZE <= this).
     */
    const BULK_MAX_TICKETS = 30;

    /** From this many tickets the list asks before applying a bulk action. */
    const BULK_CONFIRM_FROM = 10;

    /** @var ticket_repository */
    private ticket_repository $ticketrepo;

    /** @var reply_repository */
    private reply_repository $replyrepo;

    /** @var ticket_history_repository */
    private ticket_history_repository $historyrepo;

    /** @var department_repository */
    private department_repository $deptrepo;

    /** @var category_repository */
    private category_repository $catrepo;

    /** @var ticket_access_policy|null Lazily built; stateless beyond the system context. */
    private ?ticket_access_policy $accesspolicy = null;

    /** @var \moodle_database */
    private \moodle_database $db;

    /**
     * Build the service with its injected dependencies.
     *
     * @param ticket_repository         $ticketrepo
     * @param reply_repository          $replyrepo
     * @param ticket_history_repository $historyrepo
     * @param department_repository     $deptrepo
     * @param category_repository       $catrepo
     * @param \moodle_database|null     $db
     */
    public function __construct(
        ticket_repository $ticketrepo,
        reply_repository $replyrepo,
        ticket_history_repository $historyrepo,
        department_repository $deptrepo,
        category_repository $catrepo,
        ?\moodle_database $db = null
    ) {
        global $DB;
        $this->ticketrepo  = $ticketrepo;
        $this->replyrepo   = $replyrepo;
        $this->historyrepo = $historyrepo;
        $this->deptrepo    = $deptrepo;
        $this->catrepo     = $catrepo;
        $this->db           = $db ?? $DB;
    }

    /*
     * -------------------------------------------------------------------------
     * Factory
     * -------------------------------------------------------------------------
     */

    /**
     * Create a fully-wired service instance for production use.
     *
     * @return self
     */
    public static function create(): self {
        return new self(
            new ticket_repository(),
            new reply_repository(),
            new ticket_history_repository(),
            new department_repository(),
            new category_repository()
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Ticket creation
     * -------------------------------------------------------------------------
     */

    /**
     * Create a new ticket from an inbound email without a capability check.
     *
     * Called exclusively by email_ingestion_service. The caller is the cron
     * task (system context), so the standard :submit capability check is
     * intentionally bypassed here. All other behaviour — transactions, history,
     * events — is identical to create_ticket().
     *
     * Required $data properties:
     *   userid        int      Requester (matched Moodle user or guest fallback).
     *   subject       string   Ticket subject derived from email Subject header.
     *   message       string   Email body (HTML or plain-text).
     *   messageformat int      Moodle FORMAT_* constant.
     *   categoryid    int|null Optional. Defaults to configured default.
     *   departmentid  int|null Optional email_default_departmentid setting.
     *   priority      int      Optional. Defaults to PRIORITY_NORMAL.
     *
     * @param \stdClass $data
     * @return array{ticketid: int, replyid: int}
     * @throws \moodle_exception
     */
    public function create_ticket_from_email(\stdClass $data): array {
        if (empty($data->userid)) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'userid');
        }
        if (empty(trim($data->subject ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'subject');
        }
        if (empty(trim($data->message ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'message');
        }

        $actorid      = (int) $data->userid;
        $categoryid   = $this->resolve_category_id($data->categoryid ?? null);
        $departmentid = $this->resolve_department_id($data->departmentid ?? null);
        $courseid     = null; // Email tickets are always site-level.

        $now = time();

        $ticketrecord                      = new \stdClass();
        $ticketrecord->courseid            = null;
        $ticketrecord->contextid           = \context_system::instance()->id;
        $ticketrecord->coursename_snapshot = null;
        $ticketrecord->userid              = $actorid;
        $ticketrecord->createdby           = $actorid;
        $ticketrecord->assigneeid          = null;
        $ticketrecord->categoryid          = $categoryid;
        $ticketrecord->departmentid        = $departmentid;
        // Fitted to the column, multibyte-safe. Nothing upstream caps a subject
        // at the width the schema declares — an emailed one has no limit at
        // all — and this insert shares a transaction with the first reply, so
        // an over-length subject did not lose a subject, it rolled the whole
        // message back. On the email path that meant the pipeline re-ran the
        // same message on the next poll, forever.
        $ticketrecord->subject             = \core_text::substr(
            clean_param(trim($data->subject), PARAM_TEXT),
            0,
            self::SUBJECT_COLUMN_WIDTH
        );
        $ticketrecord->status              = ticket::STATUS_OPEN;
        $ticketrecord->priority            = $this->validated_priority($data->priority ?? ticket::PRIORITY_NORMAL);
        $ticketrecord->source              = ticket::SOURCE_EMAIL;
        $ticketrecord->timecreated         = $now;
        $ticketrecord->timemodified        = $now;
        $ticketrecord->timefirstresponse   = null;
        $ticketrecord->timeresolved        = null;
        $ticketrecord->timeclosed          = null;
        $ticketrecord->sla_breached        = 0;
        $ticketrecord->usermodified        = $actorid;

        $replyrecord                = new \stdClass();
        $replyrecord->userid        = $actorid;
        $replyrecord->message       = $data->message;
        $replyrecord->messageformat = (int) ($data->messageformat ?? FORMAT_HTML);
        $replyrecord->type          = reply::TYPE_PUBLIC_REPLY;
        $replyrecord->timecreated   = $now;
        $replyrecord->timemodified  = $now;
        $replyrecord->usermodified  = $actorid;

        $ticketid     = null;
        $firstreplyid = null;

        $transaction = $this->db->start_delegated_transaction();
        try {
            $ticketid              = $this->ticketrepo->insert($ticketrecord);
            $replyrecord->ticketid = $ticketid;
            $firstreplyid          = $this->replyrepo->insert($replyrecord);
            $this->write_history($ticketid, $actorid, ticket_history::ACTION_CREATED, null, [
                'status'       => ticket::STATUS_OPEN,
                'priority'     => $ticketrecord->priority,
                'categoryid'   => $categoryid,
                'departmentid' => $departmentid,
                'source'       => ticket::SOURCE_EMAIL,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $context = \context_system::instance();
        $event   = ticket_created::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => $context,
            'other'    => [
                'categoryid'   => $categoryid,
                'departmentid' => $departmentid,
                'courseid'     => null,
                'priority'     => $ticketrecord->priority,
                'source'       => ticket::SOURCE_EMAIL,
            ],
        ]);
        $event->trigger();

        return ['ticketid' => $ticketid, 'replyid' => $firstreplyid];
    }

    /**
     * Create a new ticket and its initial message atomically.
     *
     * Expected $data properties:
     *   userid        int      Requester. Required.
     *   subject       string   Ticket subject. Required.
     *   message       string   Initial message body. Required.
     *   messageformat int      Moodle FORMAT_* constant. Default: FORMAT_HTML.
     *   categoryid    int|null Optional. Defaults to configured default.
     *   departmentid  int|null Optional. Phase 3: routes ticket to a department.
     *   courseid      int|null Optional.
     *   priority      int      Optional. Defaults to PRIORITY_NORMAL.
     *
     * @param \stdClass $data
     * @param int       $actorid
     * @return array{ticketid: int, replyid: int}  New ticket and initial reply IDs.
     * @throws \required_capability_exception
     * @throws \moodle_exception
     */
    public function create_ticket(\stdClass $data, int $actorid): array {
        $context = \context_system::instance();
        require_capability('local/moodesk:submit', $context, $actorid);

        if (empty($data->userid)) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'userid');
        }
        if (empty(trim($data->subject ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'subject');
        }
        if (empty(trim($data->message ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'message');
        }

        $categoryid   = $this->resolve_category_id($data->categoryid ?? null);
        $departmentid = $this->resolve_department_id($data->departmentid ?? null);
        $courseid     = $this->resolve_course_id(isset($data->courseid) ? (int) $data->courseid : null, $actorid);
        $contextid    = $this->resolve_context_id($courseid);
        $coursesnap   = $this->resolve_course_name_snapshot($courseid);

        $now = time();

        $ticketrecord                      = new \stdClass();
        $ticketrecord->courseid            = $courseid;
        $ticketrecord->contextid           = $contextid;
        $ticketrecord->coursename_snapshot = $coursesnap;
        $ticketrecord->userid              = (int) $data->userid;
        $ticketrecord->createdby           = $actorid;
        $ticketrecord->assigneeid          = null;
        $ticketrecord->categoryid          = $categoryid;
        $ticketrecord->departmentid        = $departmentid;
        // Fitted to the column, multibyte-safe. Nothing upstream caps a subject
        // at the width the schema declares — an emailed one has no limit at
        // all — and this insert shares a transaction with the first reply, so
        // an over-length subject did not lose a subject, it rolled the whole
        // message back. On the email path that meant the pipeline re-ran the
        // same message on the next poll, forever.
        $ticketrecord->subject             = \core_text::substr(
            clean_param(trim($data->subject), PARAM_TEXT),
            0,
            self::SUBJECT_COLUMN_WIDTH
        );
        $ticketrecord->status              = ticket::STATUS_OPEN;
        $ticketrecord->priority            = $this->validated_priority($data->priority ?? ticket::PRIORITY_NORMAL);
        $ticketrecord->source              = isset($data->source) ? (int) $data->source : ticket::SOURCE_WEB;
        $ticketrecord->timecreated         = $now;
        $ticketrecord->timemodified        = $now;
        $ticketrecord->timefirstresponse   = null;
        $ticketrecord->timeresolved        = null;
        $ticketrecord->timeclosed          = null;
        $ticketrecord->sla_breached        = 0;
        $ticketrecord->usermodified        = $actorid;

        $replyrecord                = new \stdClass();
        $replyrecord->userid        = (int) $data->userid;
        $replyrecord->message       = $data->message;
        $replyrecord->messageformat = (int) ($data->messageformat ?? FORMAT_HTML);
        $replyrecord->type          = reply::TYPE_PUBLIC_REPLY;
        $replyrecord->timecreated   = $now;
        $replyrecord->timemodified  = $now;
        $replyrecord->usermodified  = $actorid;

        $firstreplyid = null;

        $transaction = $this->db->start_delegated_transaction();
        try {
            $ticketid              = $this->ticketrepo->insert($ticketrecord);
            $replyrecord->ticketid = $ticketid;
            $firstreplyid          = $this->replyrepo->insert($replyrecord);
            $this->write_history($ticketid, $actorid, ticket_history::ACTION_CREATED, null, [
                'status'       => ticket::STATUS_OPEN,
                'priority'     => $ticketrecord->priority,
                'categoryid'   => $categoryid,
                'departmentid' => $departmentid,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $event = ticket_created::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => $context,
            'other'    => [
                'categoryid'   => $categoryid,
                'departmentid' => $departmentid,
                'courseid'     => $courseid,
                'priority'     => $ticketrecord->priority,
            ],
        ]);
        $event->trigger();

        return ['ticketid' => $ticketid, 'replyid' => $firstreplyid];
    }

    /*
     * -------------------------------------------------------------------------
     * Ticket retrieval
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch a single ticket with access control.
     *
     * @param int $ticketid
     * @param int $actorid
     * @return \stdClass
     * @throws \moodle_exception
     * @throws \required_capability_exception
     */
    public function get_ticket(int $ticketid, int $actorid): \stdClass {
        $ticket = $this->ticketrepo->get_by_id($ticketid);

        if ($ticket === null) {
            throw new \moodle_exception('ticketnotfound', 'local_moodesk');
        }

        // One rule, one place. This method used to be that place, and four other
        // paths restated it instead of asking it; the rule now lives in the
        // policy and everything that depends on visibility calls the policy.
        $this->access_policy()->require_can_view($actorid, $ticket);

        return $ticket;
    }

    /**
     * The visibility and management rule for tickets.
     *
     * @return ticket_access_policy
     */
    private function access_policy(): ticket_access_policy {
        return $this->accesspolicy ??= new ticket_access_policy();
    }

    /**
     * Fetch paginated tickets for a requester (own tickets only).
     *
     * @param int   $actorid
     * @param array $filters
     * @param int   $page
     * @return array{tickets: \stdClass[], total: int}
     */
    public function get_tickets_for_requester(int $actorid, array $filters = [], int $page = 0): array {
        return $this->get_tickets_for_requester_range($actorid, $filters, $page * self::PAGE_SIZE, self::PAGE_SIZE);
    }

    /**
     * Fetch a requester's own tickets by row range rather than by UI page.
     *
     * The REST API takes `limit` and `offset` from its caller, so it needs the
     * slice those two name — not the PAGE_SIZE page that happens to contain
     * it. Same query, same order (newest first), same total; only the window
     * is the caller's (audit O3).
     *
     * @param int   $actorid
     * @param array $filters
     * @param int   $limitfrom 0-based row offset.
     * @param int   $limitnum  Rows wanted.
     * @return array{tickets: \stdClass[], total: int}
     */
    public function get_tickets_for_requester_range(int $actorid, array $filters, int $limitfrom, int $limitnum): array {
        require_capability('local/moodesk:viewown', \context_system::instance(), $actorid);

        return [
            'tickets' => $this->ticketrepo->get_for_requester($actorid, $filters, $limitfrom, $limitnum),
            'total'   => $this->ticketrepo->count_for_requester($actorid, $filters),
        ];
    }

    /**
     * Fetch paginated tickets for an agent with view-based scoping.
     *
     * Phase 3 introduces four agent workspace views:
     *   department::VIEW_ALL        — All accessible (dept membership + own assigned).
     *   department::VIEW_MINE       — Tickets assigned to this agent.
     *   department::VIEW_UNASSIGNED — Unassigned tickets the agent can see.
     *   department::VIEW_DEPARTMENT — Tickets in agent's departments.
     *
     * Tickets with null departmentid are globally visible to all agents,
     * preserving Phase 1/2 behaviour when no departments are configured.
     *
     * @param int    $actorid
     * @param array  $filters
     * @param int    $page
     * @param string $view     One of department::VIEW_* constants.
     * @return array{tickets: \stdClass[], total: int}
     */
    public function get_all_tickets(int $actorid, array $filters = [], int $page = 0, string $view = department::VIEW_ALL): array {
        return $this->get_all_tickets_range($actorid, $filters, $page * self::PAGE_SIZE, self::PAGE_SIZE, $view);
    }

    /**
     * Fetch an agent's tickets by row range rather than by UI page.
     *
     * The row-range twin of {@see get_all_tickets()}: same views, same
     * capability, same order and total; the window is the caller's. This is
     * what the REST API's `limit` / `offset` map onto (audit O3).
     *
     * @param int    $actorid
     * @param array  $filters
     * @param int    $limitfrom 0-based row offset.
     * @param int    $limitnum  Rows wanted.
     * @param string $view      One of department::VIEW_*.
     * @return array{tickets: \stdClass[], total: int}
     */
    public function get_all_tickets_range(
        int $actorid,
        array $filters,
        int $limitfrom,
        int $limitnum,
        string $view = department::VIEW_ALL
    ): array {
        require_capability('local/moodesk:viewall', \context_system::instance(), $actorid);

        // Validate view identifier; fall back to VIEW_ALL on unknown values.
        if (!in_array($view, department::valid_views(), true)) {
            $view = department::VIEW_ALL;
        }

        switch ($view) {
            case department::VIEW_MINE:
                // Add the assignee filter on top of any existing filters.
                $minefilters = array_merge($filters, ['assigneeid' => $actorid]);
                return [
                    'tickets' => $this->ticketrepo->get_all($minefilters, $limitfrom, $limitnum),
                    'total'   => $this->ticketrepo->count_all($minefilters),
                ];

            case department::VIEW_UNASSIGNED:
                return [
                    'tickets' => $this->ticketrepo->get_unassigned_accessible($actorid, $filters, $limitfrom, $limitnum),
                    'total'   => $this->ticketrepo->count_unassigned_accessible($actorid, $filters),
                ];

            case department::VIEW_DEPARTMENT:
                return [
                    'tickets' => $this->ticketrepo->get_department_tickets_for_agent(
                        $actorid,
                        $filters,
                        $limitfrom,
                        $limitnum
                    ),
                    'total'   => $this->ticketrepo->count_department_tickets_for_agent($actorid, $filters),
                ];

            default: // VIEW_ALL
                // This method already requires local/moodesk:viewall, so VIEW_ALL
                // is genuinely global — every ticket regardless of department
                // membership. Using the accessibility-scoped query here hid
                // tickets in departments the viewer is not a member of (e.g. an
                // unassigned ticket in a memberless department), which then
                // disagreed with the dashboard's global manager metrics.
                return [
                    'tickets' => $this->ticketrepo->get_all($filters, $limitfrom, $limitnum),
                    'total'   => $this->ticketrepo->count_all($filters),
                ];
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Ticket mutation
     * -------------------------------------------------------------------------
     */

    /**
     * Transition ticket status according to the state machine.
     *
     * @param int $ticketid
     * @param int $newstatus
     * @param int $actorid
     */
    public function transition_status(int $ticketid, int $newstatus, int $actorid): void {
        require_capability('local/moodesk:manage', \context_system::instance(), $actorid);

        $ticketobj = $this->get_existing_ticket($ticketid);
        before_ticket_write::check($ticketobj, before_ticket_write::OP_STATUS, $actorid);
        $oldstatus = (int) $ticketobj->status;

        if ($oldstatus === $newstatus) {
            return;
        }

        if (!ticket::is_valid_transition($oldstatus, $newstatus)) {
            throw new \moodle_exception(
                'invalidstatustransition',
                'local_moodesk',
                '',
                ['from' => $oldstatus, 'to' => $newstatus]
            );
        }

        $now    = time();
        $update = new \stdClass();
        $update->id           = $ticketid;
        $update->status       = $newstatus;
        $update->timemodified = $now;
        $update->usermodified = $actorid;

        if ($newstatus === ticket::STATUS_RESOLVED) {
            $update->timeresolved = $now;
        }
        if ($newstatus === ticket::STATUS_CLOSED) {
            $update->timeclosed = $now;
        }

        // The resolution SLA clock stops while the ticket waits on the requester.
        // Entering pending opens an interval; leaving it for anything at all —
        // resolved and closed included — folds the elapsed time into the total,
        // so the time a requester took to answer never counts against the team.
        foreach (ticket::sla_pause_fields($ticketobj, $newstatus, $now) as $field => $value) {
            $update->$field = $value;
        }

        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->ticketrepo->update($update);
            $this->write_history(
                $ticketid,
                $actorid,
                ticket_history::ACTION_STATUS_CHANGED,
                ['status' => $oldstatus],
                ['status' => $newstatus]
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $event = ticket_status_changed::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => \context_system::instance(),
            'other'    => ['oldstatus' => $oldstatus, 'newstatus' => $newstatus],
        ]);
        $event->trigger();
    }

    /**
     * Set ticket priority.
     *
     * @param int $ticketid
     * @param int $priority
     * @param int $actorid
     */
    public function set_priority(int $ticketid, int $priority, int $actorid): void {
        require_capability('local/moodesk:manage', \context_system::instance(), $actorid);

        $priority    = $this->validated_priority($priority);
        $ticketobj   = $this->get_existing_ticket($ticketid);
        before_ticket_write::check($ticketobj, before_ticket_write::OP_PRIORITY, $actorid);
        $oldpriority = (int) $ticketobj->priority;

        if ($oldpriority === $priority) {
            return;
        }

        $now    = time();
        $update = new \stdClass();
        $update->id           = $ticketid;
        $update->priority     = $priority;
        $update->timemodified = $now;
        $update->usermodified = $actorid;

        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->ticketrepo->update($update);
            $this->write_history(
                $ticketid,
                $actorid,
                ticket_history::ACTION_PRIORITY_CHANGED,
                ['priority' => $oldpriority],
                ['priority' => $priority]
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        ticket_field_changed::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => \context_system::instance(),
            'other'    => ['field' => 'priority', 'oldvalue' => $oldpriority, 'newvalue' => $priority],
        ])->trigger();
    }

    /**
     * Assign or unassign a ticket.
     *
     * Pass null as $assigneeid to unassign.
     * When assigning a NEW ticket, implicitly transitions it to OPEN.
     *
     * @param int      $ticketid
     * @param int|null $assigneeid
     * @param int      $actorid
     */
    public function assign(int $ticketid, ?int $assigneeid, int $actorid): void {
        require_capability('local/moodesk:manage', \context_system::instance(), $actorid);
        $this->apply_assignment($ticketid, $assigneeid, $actorid);
    }

    /**
     * Apply one action to many tickets, one ticket at a time.
     *
     * A bulk action is N independent operations, not one operation over N rows.
     * Each ticket goes through the same single-ticket path an agent would take
     * by hand — same capability check, same state machine, same history entry,
     * same event — so a bulk status change is indistinguishable downstream from
     * thirty manual ones. That is deliberate: automation, webhooks and
     * notifications must not learn a second shape of the same fact.
     *
     * It follows that there is no enclosing transaction. A ticket the state
     * machine refuses is a correct refusal, not a reason to undo the twenty-nine
     * that moved; each single-ticket call manages its own transaction and the
     * failures are collected rather than thrown. Only two things escape: an
     * unknown action, which is a programming error, and a missing capability,
     * which is an authorisation failure and must not be dressed up as thirty
     * individual ticket failures.
     *
     * Authorisation lives where it already lived. transition_status() and
     * assign() each require :manage, so every ticket is checked on its own
     * behalf; the page that calls this checks the same capability at the
     * boundary before rendering anything. Nothing new is added here.
     *
     * @param int[]  $ticketids Ticket ids; duplicates and non-positive values are dropped.
     * @param string $action    One of the BULK_ACTION_* constants.
     * @param array  $params    Action parameters: ['status' => int] or ['assigneeid' => ?int].
     * @param int    $actorid   User performing the action; must hold :manage.
     * @return bulk_result What happened, ticket by ticket.
     * @throws \coding_exception On an unknown action or a missing parameter.
     * @throws \moodle_exception bulk_too_many when the selection exceeds BULK_MAX_TICKETS; nothing moves.
     * @throws \required_capability_exception When the actor may not manage tickets.
     */
    public function apply_bulk(array $ticketids, string $action, array $params, int $actorid): bulk_result {
        $result = new bulk_result();

        if (!in_array($action, [self::BULK_ACTION_STATUS, self::BULK_ACTION_ASSIGN], true)) {
            throw new \coding_exception('Unknown bulk action: ' . $action);
        }

        // Normalise here rather than trusting the caller: a checkbox list can
        // repeat an id, and a hand-built request can carry a zero.
        $ticketids = array_values(array_unique(array_filter(
            array_map('intval', $ticketids),
            static fn(int $id): bool => $id > 0
        )));

        if ($ticketids === []) {
            return $result;
        }

        // Refused whole, before any ticket moves: half a batch applied and the
        // rest cut off by a timeout is worse than none, and the list never sends
        // more than a page, so only a hand-built request gets here.
        if (count($ticketids) > self::BULK_MAX_TICKETS) {
            throw new \moodle_exception('bulk_too_many', 'local_moodesk', '', self::BULK_MAX_TICKETS);
        }

        if ($action === self::BULK_ACTION_STATUS) {
            if (!isset($params['status'])) {
                throw new \coding_exception('Bulk status change requires a status parameter');
            }
            $newstatus = (int) $params['status'];
            if (!in_array($newstatus, ticket::all_statuses(), true)) {
                throw new \coding_exception('Unknown ticket status: ' . $newstatus);
            }
        } else {
            // Zero and null both mean "unassign"; the form sends zero.
            $assigneeid = isset($params['assigneeid']) ? (int) $params['assigneeid'] : 0;
            $assigneeid = $assigneeid > 0 ? $assigneeid : null;
        }

        foreach ($ticketids as $ticketid) {
            try {
                if ($action === self::BULK_ACTION_STATUS) {
                    $this->transition_status($ticketid, $newstatus, $actorid);
                } else {
                    $this->assign($ticketid, $assigneeid, $actorid);
                }
                $result->record_success($ticketid);
            } catch (\required_capability_exception $e) {
                // Not this ticket's failure — the actor may not do this at all.
                throw $e;
            } catch (\moodle_exception $e) {
                // Raised by the domain rules, so its message was written for the
                // agent: an invalid transition, a merged ticket, a missing one.
                $result->record_failure($ticketid, $e->getMessage());
            } catch (\Throwable $e) {
                // Anything else is infrastructure. It is recorded so the ticket
                // is reported as failed, but its text is not shown to anyone.
                $result->record_failure($ticketid);
            }
        }

        return $result;
    }

    /**
     * Requeue (unassign) every open ticket currently assigned to an agent.
     *
     * The agent offboard flow (A3) calls this before revoking an agent, so their
     * open tickets are never orphaned on a now-non-agent. Authorized by
     * :manageagents (the offboard permission) rather than :manage — the actor is
     * managing agents, not triaging tickets. Each ticket is unassigned through the
     * shared assignment path, so history and events are recorded exactly as a
     * manual unassign would. Unassigning skips department-membership validation,
     * so this always succeeds regardless of the tickets' departments.
     *
     * @param int $fromuserid The agent being offboarded.
     * @param int $actorid    Must hold :manageagents.
     * @return int Number of tickets requeued.
     * @throws \required_capability_exception
     */
    public function requeue_agent_tickets(int $fromuserid, int $actorid): int {
        require_capability('local/moodesk:manageagents', \context_system::instance(), $actorid);

        $ticketids = $this->ticketrepo->get_open_by_assignee($fromuserid);
        foreach ($ticketids as $ticketid) {
            $this->apply_assignment((int) $ticketid, null, $actorid);
        }

        return count($ticketids);
    }

    /**
     * Core assignment logic shared by {@see assign()} and the offboard requeue.
     *
     * The caller is responsible for the appropriate capability check. Passing
     * $assigneeid = null unassigns (requeue), which skips the department-membership
     * validation that only applies when assigning a ticket to an agent.
     *
     * @param int      $ticketid
     * @param int|null $assigneeid
     * @param int      $actorid
     */
    private function apply_assignment(int $ticketid, ?int $assigneeid, int $actorid): void {
        // Fetch ticket first so we can validate department membership below.
        $ticketobj = $this->get_existing_ticket($ticketid);
        // Covers both callers. The offboard requeue never reaches a merged ticket
        // anyway — get_open_by_assignee() excludes CLOSED and a merged source is
        // always closed — so guarding here cannot strand an agent's offboard.
        before_ticket_write::check($ticketobj, before_ticket_write::OP_ASSIGN, $actorid);

        if ($assigneeid !== null) {
            if (!$this->is_agent($assigneeid)) {
                throw new \moodle_exception('invalidassignee', 'local_moodesk');
            }
            // If the ticket belongs to a department, the assignee must be a member.
            if (
                $ticketobj->departmentid !== null
                    && !$this->deptrepo->is_member((int) $ticketobj->departmentid, $assigneeid)
            ) {
                throw new \moodle_exception('assigneenotindepartment', 'local_moodesk');
            }
        }
        $oldassigneeid = $ticketobj->assigneeid !== null ? (int) $ticketobj->assigneeid : null;

        if ($oldassigneeid === $assigneeid) {
            return;
        }

        $now    = time();
        $update = new \stdClass();
        $update->id           = $ticketid;
        $update->assigneeid   = $assigneeid;
        $update->timemodified = $now;
        $update->usermodified = $actorid;

        $oldstatus     = (int) $ticketobj->status;
        $statuschanged = false;
        if ($assigneeid !== null && $oldstatus === ticket::STATUS_NEW) {
            $update->status = ticket::STATUS_OPEN;
            $statuschanged  = true;
        }

        $action = $assigneeid !== null
            ? ticket_history::ACTION_ASSIGNED
            : ticket_history::ACTION_UNASSIGNED;

        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->ticketrepo->update($update);
            $this->write_history(
                $ticketid,
                $actorid,
                $action,
                ['assigneeid' => $oldassigneeid],
                ['assigneeid' => $assigneeid]
            );
            if ($statuschanged) {
                $this->write_history(
                    $ticketid,
                    $actorid,
                    ticket_history::ACTION_STATUS_CHANGED,
                    ['status' => $oldstatus],
                    ['status' => ticket::STATUS_OPEN]
                );
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $event = ticket_assigned::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => \context_system::instance(),
            'other'    => [
                'oldassigneeid' => $oldassigneeid,
                'newassigneeid' => $assigneeid,
            ],
        ]);
        $event->trigger();

        if ($statuschanged) {
            $statusevent = ticket_status_changed::create([
                'objectid' => $ticketid,
                'userid'   => $actorid,
                'context'  => \context_system::instance(),
                'other'    => ['oldstatus' => $oldstatus, 'newstatus' => ticket::STATUS_OPEN],
            ]);
            $statusevent->trigger();
        }
    }

    /**
     * Change the category of a ticket.
     *
     * @param int $ticketid
     * @param int $categoryid
     * @param int $actorid
     */
    public function set_category(int $ticketid, int $categoryid, int $actorid): void {
        edition_service::require_feature('categories');
        require_capability('local/moodesk:manage', \context_system::instance(), $actorid);

        if (!$this->catrepo->exists_active($categoryid)) {
            throw new \moodle_exception('invalidcategory', 'local_moodesk');
        }

        $ticketobj   = $this->get_existing_ticket($ticketid);
        before_ticket_write::check($ticketobj, before_ticket_write::OP_CATEGORY, $actorid);
        $oldcategory = $ticketobj->categoryid !== null ? (int) $ticketobj->categoryid : null;

        if ($oldcategory === $categoryid) {
            return;
        }

        $now    = time();
        $update = new \stdClass();
        $update->id           = $ticketid;
        $update->categoryid   = $categoryid;
        $update->timemodified = $now;
        $update->usermodified = $actorid;

        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->ticketrepo->update($update);
            $this->write_history(
                $ticketid,
                $actorid,
                ticket_history::ACTION_CATEGORY_CHANGED,
                ['categoryid' => $oldcategory],
                ['categoryid' => $categoryid]
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        ticket_field_changed::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => \context_system::instance(),
            'other'    => ['field' => 'categoryid', 'oldvalue' => $oldcategory, 'newvalue' => $categoryid],
        ])->trigger();
    }

    /**
     * Change (or clear) the department of a ticket. Phase 3+.
     *
     * Pass null to remove the department assignment (makes ticket globally visible).
     *
     * @param int      $ticketid
     * @param int|null $departmentid
     * @param int      $actorid
     * @throws \required_capability_exception
     * @throws \moodle_exception On invalid department.
     */
    public function set_department(int $ticketid, ?int $departmentid, int $actorid): void {
        edition_service::require_feature('multi_department');
        require_capability('local/moodesk:manage', \context_system::instance(), $actorid);

        // Validate the department if one is supplied.
        if ($departmentid !== null && !$this->deptrepo->exists_active($departmentid)) {
            throw new \moodle_exception('departmentnotfound', 'local_moodesk');
        }

        $ticketobj     = $this->get_existing_ticket($ticketid);
        before_ticket_write::check($ticketobj, before_ticket_write::OP_DEPARTMENT, $actorid);
        $olddepartment = $ticketobj->departmentid !== null ? (int) $ticketobj->departmentid : null;

        if ($olddepartment === $departmentid) {
            return;
        }

        $now    = time();
        $update = new \stdClass();
        $update->id           = $ticketid;
        $update->departmentid = $departmentid;
        $update->timemodified = $now;
        $update->usermodified = $actorid;

        $transaction = $this->db->start_delegated_transaction();
        try {
            $this->ticketrepo->update($update);
            $this->write_history(
                $ticketid,
                $actorid,
                ticket_history::ACTION_DEPARTMENT_CHANGED,
                ['departmentid' => $olddepartment],
                ['departmentid' => $departmentid]
            );
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        ticket_field_changed::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => \context_system::instance(),
            'other'    => ['field' => 'departmentid', 'oldvalue' => $olddepartment, 'newvalue' => $departmentid],
        ])->trigger();
    }

    /*
     * -------------------------------------------------------------------------
     * Team assignment (Phase 11.E)
     * -------------------------------------------------------------------------
     */

    /*
     * -------------------------------------------------------------------------
     * Agent list
     * -------------------------------------------------------------------------
     */

    /**
     * Return users assignable to a ticket.
     *
     * Phase 3: when a departmentid is given, restricts to agents who are
     * members of that department. Falls back to all agents when no department
     * is specified (Phase 1/2 behaviour) or when the department has no members.
     *
     * @param int      $actorid
     * @param int|null $departmentid  Optional: restrict to dept members.
     * @return \stdClass[]  Keyed by userid.
     */
    public function get_assignable_agents(int $actorid, ?int $departmentid = null): array {
        require_capability('local/moodesk:manage', \context_system::instance(), $actorid);

        $context = \context_system::instance();

        $allagents = get_users_by_capability(
            $context,
            'local/moodesk:agent',
            user_fields::for_fullname('u') . ', u.email',
            'u.lastname, u.firstname',
            '',
            '',
            '',
            '',
            false
        );

        if (empty($allagents)) {
            return [];
        }

        // When a department is specified, restrict to members of that dept.
        if ($departmentid !== null) {
            $memberids = $this->deptrepo->get_member_ids($departmentid);
            if (empty($memberids)) {
                // Department exists but has no agents — return empty to signal
                // "no agents in this dept" rather than falling back to all agents.
                return [];
            }
            return array_filter(
                $allagents,
                static fn($u) => in_array((int) $u->id, $memberids, true)
            );
        }

        return $allagents;
    }

    /**
     * Return true if the user holds the :agent capability.
     *
     * @param int $userid
     * @return bool
     */
    public function is_agent(int $userid): bool {
        return has_capability(
            'local/moodesk:agent',
            \context_system::instance(),
            $userid,
            false
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Private helpers
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch a ticket by ID; throw if it does not exist.
     *
     * @param int $ticketid
     * @return \stdClass
     */
    private function get_existing_ticket(int $ticketid): \stdClass {
        $ticket = $this->ticketrepo->get_by_id($ticketid);
        if ($ticket === null) {
            throw new \moodle_exception('ticketnotfound', 'local_moodesk');
        }
        return $ticket;
    }

    /**
     * Resolve categoryid — return supplied value if valid, or fall back to default.
     *
     * @param int|null $categoryid
     * @return int
     */
    private function resolve_category_id(?int $categoryid): int {
        if ($categoryid !== null && $this->catrepo->exists_active($categoryid)) {
            return $categoryid;
        }

        $default = (int) get_config('local_moodesk', 'defaultcategoryid');
        if ($default && $this->catrepo->exists_active($default)) {
            return $default;
        }

        throw new \moodle_exception('nocategoryavailable', 'local_moodesk');
    }

    /**
     * Resolve departmentid — return the supplied value if valid, or null.
     *
     * Unlike categories, departments are optional. An invalid or null
     * departmentid simply resolves to null (globally visible ticket).
     *
     * @param int|null $departmentid
     * @return int|null
     */
    private function resolve_department_id(?int $departmentid): ?int {
        if ($departmentid === null || $departmentid === 0) {
            return null;
        }
        if ($this->deptrepo->exists_active($departmentid)) {
            return $departmentid;
        }
        return null;
    }

    /**
     * Keep a course id only when the actor may actually see that course.
     *
     * The id arrives from a URL parameter or a hidden field, and the ticket
     * carries the course's full name from creation on — on the requester's own
     * ticket page, among others. Without this check `submit.php?courseid=N`
     * read the name of any course on the site, hidden ones included (audit
     * H8). A course the actor cannot access, or one that does not exist, is
     * treated as no course: the ticket is still created, at site level.
     *
     * @param int|null $courseid
     * @param int      $actorid
     * @return int|null
     */
    private function resolve_course_id(?int $courseid, int $actorid): ?int {
        if ($courseid === null || $courseid <= 0) {
            return null;
        }
        $course = $this->db->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return null;
        }
        // Plain can_access_course(): enrolled and visible, or otherwise allowed
        // to see it — the same answer the course page itself gives the actor.
        return can_access_course($course, $actorid) ? $courseid : null;
    }

    /**
     * Compute the Moodle context ID snapshot for a courseid.
     *
     * @param int|null $courseid
     * @return int|null
     */
    private function resolve_context_id(?int $courseid): ?int {
        if ($courseid === null) {
            return \context_system::instance()->id;
        }
        try {
            return \context_course::instance($courseid, IGNORE_MISSING)->id ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Capture course fullname at creation time.
     *
     * @param int|null $courseid
     * @return string|null
     */
    private function resolve_course_name_snapshot(?int $courseid): ?string {
        if ($courseid === null) {
            return null;
        }
        $course = $this->db->get_field('course', 'fullname', ['id' => $courseid]);
        return $course ?: null;
    }

    /**
     * Validate and return a priority constant.
     *
     * @param mixed $priority
     * @return int
     */
    private function validated_priority($priority): int {
        $priority = (int) $priority;
        if (!in_array($priority, ticket::all_priorities(), true)) {
            throw new \moodle_exception('invalidpriority', 'local_moodesk');
        }
        return $priority;
    }

    /**
     * Write a single audit history entry.
     *
     * @param int        $ticketid
     * @param int        $actorid
     * @param string     $action
     * @param array|null $oldvalue
     * @param array|null $newvalue
     */
    private function write_history(
        int $ticketid,
        int $actorid,
        string $action,
        ?array $oldvalue,
        ?array $newvalue
    ): void {
        $entry              = new \stdClass();
        $entry->ticketid    = $ticketid;
        $entry->userid      = $actorid;
        $entry->action      = $action;
        $entry->oldvalue    = $oldvalue !== null ? json_encode($oldvalue) : null;
        $entry->newvalue    = $newvalue !== null ? json_encode($newvalue) : null;
        $entry->timecreated = time();

        $this->historyrepo->insert($entry);
    }
}
