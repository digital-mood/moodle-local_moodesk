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
 * Reply application service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;
use local_moodesk\domain\ticket_history;
use local_moodesk\hook\before_ticket_write;
use local_moodesk\policy\ticket_access_policy;
use local_moodesk\repository\reply_repository;
use local_moodesk\repository\ticket_repository;
use local_moodesk\repository\ticket_history_repository;
use local_moodesk\event\ticket_replied;
use local_moodesk\util\user_fields;
use local_moodesk\validator\ticket_action_validator;

/**
 * Reply application service.
 *
 * CONTRACT:
 *  - All reply business logic lives here. No page, renderer, or form touches
 *    reply_repository directly.
 *  - Every public method that mutates state must:
 *      1. Ask ticket_access_policy who may do it, before any write. Reach is
 *         part of that question, so the ticket record is loaded first: an
 *         actor who cannot see a ticket may not write to it, whatever
 *         capability they hold. No method here restates the rule.
 *      2. Execute mutations inside a delegated transaction.
 *      3. Write a history entry.
 *      4. Emit an event after the transaction commits.
 *  - Internal notes (TYPE_INTERNAL_NOTE) are NEVER returned to requesters.
 *    get_thread() enforces this; no other code path must bypass it.
 *  - $DB is never accessed directly here. All data access goes through
 *    the injected repositories.
 *
 * PHASE 1 CAPABILITY NOTE:
 *  All capability checks use context_system::instance().
 *
 * REQUESTER REPLY RULE:
 *  Requesters may post public replies on their own tickets as long as the
 *  ticket is not in a terminal state (STATUS_CLOSED). This is enforced in
 *  add_public_reply(). A requester reply on a PENDING ticket implicitly
 *  signals a response — the caller (or an observer) may choose to transition
 *  status back to OPEN, but that transition is NOT performed here.
 */
class reply_service {
    /** @var reply_repository */
    private reply_repository $replyrepo;

    /** @var ticket_repository */
    private ticket_repository $ticketrepo;

    /** @var ticket_history_repository */
    private ticket_history_repository $historyrepo;

    /** @var \moodle_database */
    private \moodle_database $db;

    /** @var ticket_access_policy|null Lazily built; see access_policy(). */
    private ?ticket_access_policy $accesspolicy = null;

    /**
     * Build the service with its injected dependencies.
     *
     * @param reply_repository          $replyrepo
     * @param ticket_repository         $ticketrepo
     * @param ticket_history_repository $historyrepo
     * @param \moodle_database|null     $db
     */
    public function __construct(
        reply_repository $replyrepo,
        ticket_repository $ticketrepo,
        ticket_history_repository $historyrepo,
        ?\moodle_database $db = null
    ) {
        global $DB;
        $this->replyrepo   = $replyrepo;
        $this->ticketrepo  = $ticketrepo;
        $this->historyrepo = $historyrepo;
        $this->db           = $db ?? $DB;
    }

    /*
     * -------------------------------------------------------------------------
     * Factory
     * -------------------------------------------------------------------------
     */

    /**
     * Create a fully-wired service instance using global $DB.
     *
     * @return self
     */
    public static function create(): self {
        return new self(
            new reply_repository(),
            new ticket_repository(),
            new ticket_history_repository()
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Thread retrieval
     * -------------------------------------------------------------------------
     */

    /**
     * Return the thread for a ticket, filtered by the actor's role.
     *
     * Agents (viewall) see all reply types including internal notes.
     * Requesters (viewown) see only public replies and system events on their
     * own tickets.
     * Anyone else gets a capability exception.
     *
     * Each returned stdClass has all local_moodesk_reply columns plus:
     *   authorname  string   Formatted full name of the reply author.
     *   authorid    int      Alias for userid (convenience for templates).
     *
     * @param int $ticketid
     * @param int $actorid
     * @return \stdClass[]
     * @throws \required_capability_exception
     * @throws \moodle_exception If ticket does not exist.
     */
    public function get_thread(int $ticketid, int $actorid): array {
        $context = \context_system::instance();

        $ticket = $this->get_existing_ticket($ticketid);

        if (has_capability('local/moodesk:viewall', $context, $actorid)) {
            $thread = $this->replyrepo->get_thread($ticketid, null);
        } else if (
            has_capability('local/moodesk:viewown', $context, $actorid)
            && (int) $ticket->userid === $actorid
        ) {
            $thread = $this->replyrepo->get_thread($ticketid, reply::requester_visible_types());
        } else {
            throw new \required_capability_exception($context, 'local/moodesk:viewall', 'nopermissions', '');
        }

        $this->decorate_authors($thread);

        return $thread;
    }

    /**
     * Return the thread for a ticket including everything merged into it.
     *
     * A merge links tickets, it never copies them (design doc §4.3), so the
     * target's conversation only exists as a union: its own replies plus those
     * of every source still merged into it, interleaved chronologically. Rows
     * are not duplicated — each reply keeps the ticket it was written against,
     * and the extra fields below tell the caller which ones came across a link
     * so the UI can label them.
     *
     * Not gated on the Enterprise feature: merging is Enterprise, but a site
     * that lets its licence lapse must not have content vanish from tickets
     * where a merge already happened.
     *
     * Visibility across the link is decided per source, not once for the whole
     * thread:
     *  - Agents read every source, all reply types, as they do anywhere else.
     *  - A requester reads only sources they raised themselves, and only the
     *    reply types requesters ever see. The common merge is one person
     *    filing the same issue twice, and they should keep their own words;
     *    what they must never gain is a view into someone else's ticket
     *    because an agent merged it into theirs.
     *
     * Each returned stdClass carries the get_thread() decoration plus:
     *   islinked       bool         True when the reply came from a source.
     *   linkedfrom     int|null     Source ticket id, or null when not linked.
     *   linkedsubject  string|null  Source subject, for the label.
     *
     * @param int $ticketid
     * @param int $actorid
     * @return \stdClass[]
     * @throws \required_capability_exception
     * @throws \moodle_exception If ticket does not exist.
     */
    public function get_thread_with_linked(int $ticketid, int $actorid): array {
        $context = \context_system::instance();

        $ticket  = $this->get_existing_ticket($ticketid);
        $isagent = has_capability('local/moodesk:viewall', $context, $actorid);

        if (
            !$isagent
            && !(has_capability('local/moodesk:viewown', $context, $actorid)
                && (int) $ticket->userid === $actorid)
        ) {
            throw new \required_capability_exception($context, 'local/moodesk:viewall', 'nopermissions', '');
        }

        // Plugins name the other tickets this conversation reads (hook thread_sources); the replies
        // of what is left are read with the same visibility rule below.
        $hook = new \local_moodesk\hook\thread_sources($ticketid, $actorid, $isagent, []);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        $subjects = $hook->get_sources();

        $thread = $this->replyrepo->get_thread_for_tickets(
            array_merge([$ticketid], array_keys($subjects)),
            $isagent ? null : reply::requester_visible_types()
        );

        $this->decorate_authors($thread);

        foreach ($thread as $r) {
            $from             = (int) $r->ticketid;
            $r->islinked      = $from !== $ticketid;
            $r->linkedfrom    = $r->islinked ? $from : null;
            $r->linkedsubject = $r->islinked ? ($subjects[$from] ?? '') : null;
        }

        return $thread;
    }

    /**
     * Fire kb_article_linked for each existing article a saved reply links to.
     *
     * On every edition: one event per distinct article, and a link to a row
     * that does not exist is ignored.
     *
     * @param string $body     The reply body as stored.
     * @param int    $replyid
     * @param int    $ticketid
     * @param bool   $internal True for an internal note.
     * @param int    $actorid
     */
    private function trigger_kb_links(string $body, int $replyid, int $ticketid, bool $internal, int $actorid): void {
        foreach (kb_service::extract_article_ids($body) as $articleid) {
            if (!$this->db->record_exists('local_moodesk_kb_article', ['id' => $articleid])) {
                continue;
            }
            \local_moodesk\event\kb_article_linked::create([
                'context'  => \context_system::instance(),
                'objectid' => $articleid,
                'userid'   => $actorid,
                'other'    => ['replyid' => $replyid, 'ticketid' => $ticketid, 'internal' => $internal],
            ])->trigger();
        }
    }

    /**
     * Attach the author's display name to every reply in a thread.
     *
     * Author records are batch-loaded: a thread spanning a merge can carry
     * replies from several tickets, and one query per author would turn a
     * long conversation into a query storm.
     *
     * @param \stdClass[] $thread Modified in place.
     * @return void
     */
    private function decorate_authors(array $thread): void {
        $authorids = array_unique(array_column($thread, 'userid'));
        $authors   = empty($authorids)
            ? []
            : $this->db->get_records_list('user', 'id', $authorids, '', user_fields::for_fullname());

        foreach ($thread as $r) {
            $author        = $authors[(int) $r->userid] ?? null;
            $r->authorname = $author ? fullname($author) : get_string('deleteduser', 'local_moodesk');
            $r->authorid   = (int) $r->userid;
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Write operations
     * -------------------------------------------------------------------------
     */

    /**
     * Add a public reply to a ticket.
     *
     * Who can call this:
     *  - Any user holding :reply capability (agents).
     *  - The ticket owner holding :viewown, provided the ticket is not closed.
     *    Requesters cannot reply to tickets they do not own.
     *
     * Side-effects:
     *  - Sets timefirstresponse on the ticket if this is the first public reply
     *    from someone other than the requester (i.e. first agent response).
     *  - Writes an audit history entry.
     *  - Emits ticket_replied event.
     *
     * Expected $data properties:
     *   message        string  Reply body. Required.
     *   messageformat  int     Moodle FORMAT_* constant. Default: FORMAT_HTML.
     *
     * @param int       $ticketid
     * @param \stdClass $data
     * @param int       $actorid
     * @return int  The new reply ID.
     * @throws \required_capability_exception
     * @throws \moodle_exception On closed ticket, missing message, or non-existent ticket.
     */
    public function add_public_reply(int $ticketid, \stdClass $data, int $actorid): int {
        $context = \context_system::instance();
        $ticket  = $this->get_existing_ticket($ticketid);

        // Who may answer this ticket is the policy's question, not this
        // method's. The rule used to live here as ":reply, or :viewown on your
        // own ticket" and never asked whether the actor could reach the ticket
        // at all — which held only for as long as every caller checked reach
        // first (audit finding V6).
        $this->access_policy()->require_can_reply($actorid, $ticket);

        before_ticket_write::check($ticket, before_ticket_write::OP_PUBLIC_REPLY, $actorid);

        if (ticket::is_terminal((int) $ticket->status)) {
            throw new \moodle_exception('ticketclosed', 'local_moodesk');
        }

        // Before the emptiness check, not after: trim() on an array is a
        // TypeError, and casting one to string is how the literal "Array" got
        // stored. The contract above says message is a string; this enforces it
        // for every caller, ahead of any write.
        ticket_action_validator::require_string($data->message ?? '', 'message');

        if (empty(trim($data->message ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'message');
        }

        $now = time();

        $record                = new \stdClass();
        $record->ticketid      = $ticketid;
        $record->userid        = $actorid;
        $record->message       = $data->message;
        $record->messageformat = (int) ($data->messageformat ?? FORMAT_HTML);
        $record->type          = reply::TYPE_PUBLIC_REPLY;
        $record->timecreated   = $now;
        $record->timemodified  = $now;
        $record->usermodified  = $actorid;

        // Determine whether this is the first agent response (before the transaction).
        $isagentreply         = ($actorid !== (int) $ticket->userid);
        $setfirstresponse     = false;
        if ($isagentreply && empty($ticket->timefirstresponse)) {
            $hasexisting = $this->replyrepo->has_agent_public_reply($ticketid, (int) $ticket->userid);
            if (!$hasexisting) {
                $setfirstresponse = true;
            }
        }

        $replyid     = 0;
        $transaction = $this->db->start_delegated_transaction();
        try {
            $replyid = $this->replyrepo->insert($record);

            if ($setfirstresponse) {
                $update               = new \stdClass();
                $update->id           = $ticketid;
                $update->timefirstresponse = $now;
                $update->timemodified = $now;
                $update->usermodified = $actorid;
                $this->ticketrepo->update($update);
            }

            $this->write_history($ticketid, $actorid, ticket_history::ACTION_REPLY_ADDED, null, [
                'replyid' => $replyid,
                'type'    => reply::TYPE_PUBLIC_REPLY,
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        // Knowledge base articles an agent shared in this reply, read from the
        // body that was just persisted: what was actually sent, not what the
        // Insert button was pressed for.
        if ($isagentreply) {
            $this->trigger_kb_links((string) $record->message, $replyid, $ticketid, false, $actorid);
        }

        $event = ticket_replied::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => $context,
            'other'    => [
                'replyid' => $replyid,
                'type'    => reply::TYPE_PUBLIC_REPLY,
            ],
        ]);
        $event->trigger();

        return $replyid;
    }

    /**
     * Add an internal note to a ticket.
     *
     * Internal notes are visible to agents only. The requester never sees them.
     * Only users holding :addnote may create internal notes.
     *
     * Expected $data properties:
     *   message        string  Note body. Required.
     *   messageformat  int     Moodle FORMAT_* constant. Default: FORMAT_HTML.
     *
     * @param int       $ticketid
     * @param \stdClass $data
     * @param int       $actorid
     * @return int  The new reply ID.
     * @throws \required_capability_exception
     * @throws \moodle_exception On missing message or non-existent ticket.
     */
    public function add_internal_note(int $ticketid, \stdClass $data, int $actorid): int {
        // Loaded before the check, not after: reach is part of the question the
        // policy answers, and it cannot answer it without the record.
        $ticket = $this->get_existing_ticket($ticketid);

        $this->access_policy()->require_can_add_note($actorid, $ticket);

        ticket_action_validator::require_string($data->message ?? '', 'message');

        if (empty(trim($data->message ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'message');
        }

        $now = time();

        $record                = new \stdClass();
        $record->ticketid      = $ticketid;
        $record->userid        = $actorid;
        $record->message       = $data->message;
        $record->messageformat = (int) ($data->messageformat ?? FORMAT_HTML);
        $record->type          = reply::TYPE_INTERNAL_NOTE;
        $record->timecreated   = $now;
        $record->timemodified  = $now;
        $record->usermodified  = $actorid;

        $replyid     = 0;
        $transaction = $this->db->start_delegated_transaction();
        try {
            $replyid = $this->replyrepo->insert($record);

            $this->write_history($ticketid, $actorid, ticket_history::ACTION_REPLY_ADDED, null, [
                'replyid' => $replyid,
                'type'    => reply::TYPE_INTERNAL_NOTE,
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        // Articles linked in the note, the same way as in a public reply (KB-07).
        $this->trigger_kb_links((string) $record->message, $replyid, $ticketid, true, $actorid);

        $event = ticket_replied::create([
            'objectid' => $ticketid,
            'userid'   => $actorid,
            'context'  => \context_system::instance(),
            'other'    => [
                'replyid' => $replyid,
                'type'    => reply::TYPE_INTERNAL_NOTE,
            ],
        ]);
        $event->trigger();

        return $replyid;
    }

    /**
     * Add a public reply on behalf of an email sender resolved from the From header.
     *
     * Called exclusively by email_ingestion_service when an inbound email is
     * matched to an existing ticket thread. The actor is a cron-resolved user
     * (matched by email address) rather than $USER, so authorisation is asked
     * of ticket_access_policy for that user id explicitly. It used to be
     * bypassed altogether — the same way create_ticket_from_email() bypasses
     * :submit — which is what let any resolvable sender write into any thread
     * they could name (audit finding V6).
     *
     * Behaviour is identical to add_public_reply(): history entry, event, and
     * timefirstresponse update all apply.
     *
     * Expected $data properties:
     *   ticketid       int     Ticket to reply to. Required.
     *   message        string  Reply body. Required.
     *   messageformat  int     Moodle FORMAT_* constant. Default: FORMAT_PLAIN.
     *   type           int     reply::TYPE_* constant. Default: TYPE_PUBLIC_REPLY.
     *
     * @param \stdClass $data
     * @param int       $userid  The Moodle user ID resolved from the sender email.
     * @return int  New reply ID.
     * @throws \moodle_exception On closed ticket, missing message, or non-existent ticket.
     */
    public function add_reply_from_email(\stdClass $data, int $userid): int {
        $ticketid = (int) ($data->ticketid ?? 0);
        if (!$ticketid) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'ticketid');
        }

        $ticket = $this->get_existing_ticket($ticketid);

        // Authorisation before lifecycle, and before anything that could tell
        // the sender what state the ticket is in. The email pipeline asks the
        // same policy and refuses terminally before it ever gets here; this is
        // the backstop that stops a future caller from reintroducing V6 by
        // reaching this method directly.
        $this->access_policy()->require_can_reply($userid, $ticket);

        before_ticket_write::check($ticket, before_ticket_write::OP_EMAIL_REPLY, $userid);

        if (ticket::is_terminal((int) $ticket->status)) {
            throw new \moodle_exception('ticketclosed', 'local_moodesk');
        }

        if (empty(trim($data->message ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'message');
        }

        $now  = time();
        $type = (int) ($data->type ?? reply::TYPE_PUBLIC_REPLY);

        $record                = new \stdClass();
        $record->ticketid      = $ticketid;
        $record->userid        = $userid;
        $record->message       = $data->message;
        $record->messageformat = (int) ($data->messageformat ?? FORMAT_PLAIN);
        $record->type          = $type;
        $record->timecreated   = $now;
        $record->timemodified  = $now;
        $record->usermodified  = $userid;

        $isagentreply     = ($userid !== (int) $ticket->userid);
        $setfirstresponse = false;
        if ($isagentreply && $type === reply::TYPE_PUBLIC_REPLY && empty($ticket->timefirstresponse)) {
            if (!$this->replyrepo->has_agent_public_reply($ticketid, (int) $ticket->userid)) {
                $setfirstresponse = true;
            }
        }

        $replyid     = 0;
        $transaction = $this->db->start_delegated_transaction();
        try {
            $replyid = $this->replyrepo->insert($record);

            if ($setfirstresponse) {
                $update                    = new \stdClass();
                $update->id                = $ticketid;
                $update->timefirstresponse = $now;
                $update->timemodified      = $now;
                $update->usermodified      = $userid;
                $this->ticketrepo->update($update);
            }

            $this->write_history($ticketid, $userid, ticket_history::ACTION_REPLY_ADDED, null, [
                'replyid' => $replyid,
                'type'    => $type,
                'source'  => 'email',
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $context = \context_system::instance();
        $event   = ticket_replied::create([
            'objectid' => $ticketid,
            'userid'   => $userid,
            'context'  => $context,
            'other'    => ['replyid' => $replyid, 'type' => $type],
        ]);
        $event->trigger();

        return $replyid;
    }

    /*
     * -------------------------------------------------------------------------
     * Private helpers
     * -------------------------------------------------------------------------
     */

    /**
     * The visibility and write rule for tickets.
     *
     * @return ticket_access_policy
     */
    private function access_policy(): ticket_access_policy {
        return $this->accesspolicy ??= new ticket_access_policy();
    }

    /**
     * Fetch a ticket or throw if it does not exist.
     *
     * @param int $ticketid
     * @return \stdClass
     * @throws \moodle_exception
     */
    private function get_existing_ticket(int $ticketid): \stdClass {
        $ticket = $this->ticketrepo->get_by_id($ticketid);
        if ($ticket === null) {
            throw new \moodle_exception('ticketnotfound', 'local_moodesk');
        }
        return $ticket;
    }

    /**
     * Write an audit history entry for a reply action.
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
