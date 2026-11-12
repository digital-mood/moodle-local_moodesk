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
 * Privacy API provider.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_moodesk\hook\user_erasing;
use local_moodesk\service\saved_view_service;

/**
 * Privacy provider for local_moodesk.
 *
 * Personal data stored by this plugin:
 *
 *  local_moodesk_ticket
 *    userid           — the requester who owns the ticket
 *    createdby        — who created the ticket (may differ for agent-created tickets)
 *    assigneeid       — the assigned agent
 *    usermodified     — last modifier
 *    subject          — ticket subject line (may contain PII)
 *
 *  local_moodesk_reply
 *    userid           — reply author
 *    usermodified     — last modifier
 *    message          — reply body (may contain PII)
 *
 *  local_moodesk_ticket_history
 *    userid           — actor who made the change
 *    oldvalue/newvalue — JSON blobs (may reference userids)
 *
 *  local_moodesk_incident
 *    acknowledgedby   — administrator who acknowledged the operational incident
 *    resolvedby       — administrator who resolved it
 *
 *  local_moodesk_kb_article
 *    createdby        — who wrote the article
 *    title            — the title they gave it; the body is the organisation's
 *                       content and is neither exported nor deleted
 *
 *  usermodified — five columns naming whoever last wrote the row, on ticket,
 *  reply, department, category and kb_article.
 *
 *  Files (Moodle file API)
 *    ticket_attachment  itemid = ticket id
 *    reply_attachment   itemid = reply id
 *    kb_article         itemid = article id — images embedded in a body. The
 *                       organisation's content, like the body: kept on erasure.
 *
 *  **Every attachment written today is a reply_attachment.** All four routes
 *  in — the web composer at ticket creation, a web reply, a ticket opened by
 *  email and an emailed reply — save against a reply id; submit.php does it for
 *  the opening message precisely so the area stays uniform. ticket_attachment is
 *  the pre-Phase-6 area, still exported and still deleted because sites upgraded
 *  from before that change hold files there, but nothing writes to it any more.
 *  An export that read only ticket_attachment therefore returned nothing at all
 *  for anyone who joined after Phase 6, which is what R2 fixed.
 *
 *  **Whose file it is comes from the reply, not from the area.** A file's owner
 *  is the author of the reply it hangs off — `reply.userid` — so an agent's
 *  attachment is exported under the agent and never appears in the requester's
 *  data merely because it sits on the requester's ticket. The file area is a
 *  location, not an ownership claim. Legacy ticket_attachment files are the one
 *  exception, and only because they have no reply to ask: that area was
 *  writable at ticket creation alone, so the ticket's requester is its uploader.
 *
 * A plugin that keeps data about tickets answers for it in its own provider, and
 * hears of an erasure through the hook user_erasing while the ticket and reply
 * ids still lead back to the person.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Keys holding a user id inside a ticket_history value.
     *
     * `assigneeid` is the only one. Every other action stores ids of statuses,
     * priorities, categories, departments, teams, replies, tickets or merge
     * audits — see the shape documented against each constant on
     * \local_moodesk\domain\ticket_history.
     *
     * @var string[]
     */
    private const HISTORY_USERID_PATHS = [
        'assigneeid',
    ];

    /**
     * What erasure does with `usermodified`, decided one table at a time.
     *
     * Sharing a column name is not a reason to share a treatment. Three questions
     * were put to each table: does the row have a reason to outlive the person,
     * would deleting it destroy something that belongs to somebody else, and is
     * there a lawful reason to keep naming them. The answers landed in the same
     * place every time — keep the row, clear the reference — but for different
     * reasons, and those reasons are the record of the decision. Each entry below
     * says why *that* row survives.
     *
     * The two treatments that were rejected, and where they were close:
     *
     * - **Deleting the row** is right when the row exists only to name the
     *   person, which is why an erased user's own `dept_member` rows are deleted
     *   outright in anonymise_user(). It is wrong here: a `usermodified` row is
     *   somebody *else's* — the ticket somebody else raised — and deleting it
     *   would erase a second person's data to satisfy the first one's request.
     * - **Keeping the reference** would need a legal basis for continuing to
     *   name an erased user. None of these has one: `usermodified` is provenance,
     *   not an audit trail. Where the plugin does keep a genuine audit trail —
     *   `ticket_history`, `incident` — the row is kept and the identity still
     *   goes, which is the same answer reached by a different route.
     *
     * Naming the list once also keeps export, erasure and
     * tests/privacy/metadata_coverage_test.php from disagreeing about it, and
     * makes one more table one edit rather than four.
     *
     * @var array<string, string> table => why the row is kept and the reference cleared
     */
    private const USERMODIFIED_TABLES = [
        // A ticket belongs to its requester, not to whoever last edited it. The
        // thread has to stay readable for them and for the agents still working
        // it, so the row stays and the editor's name goes.
        'local_moodesk_ticket' =>
            'The ticket belongs to its requester; deleting it to erase an editor would take their data too.',
        // The reply is somebody's words. Deleting it because a different person
        // once edited the row would remove an author's content on a stranger's
        // request, and leave a thread with a hole in it.
        'local_moodesk_reply' =>
            'The reply is its author\'s content, and the thread it sits in has to stay coherent.',
        // Structural. Tickets, categories and agents all point at a department;
        // deleting it would orphan every one of them.
        'local_moodesk_department' =>
            'Structural — tickets, categories and memberships all reference it.',
        // Structural, and referenced by tickets directly.
        'local_moodesk_category' =>
            'Structural — tickets reference it, and its own subcategories do too.',
        // A knowledge base article is the organisation's content, read by
        // people who had nothing to do with writing it. Deleting it to erase
        // its last editor would take an answer away from every future
        // requester. The author's name goes the same way — see anonymise_user().
        'local_moodesk_kb_article' =>
            'The article is the organisation\'s content, read by people other than its editor.',
    ];

    /*
     * -------------------------------------------------------------------------
     * Metadata
     * -------------------------------------------------------------------------
     */

    /**
     * Describe every table and subsystem this plugin stores personal data in.
     *
     * @param collection $collection Metadata collection to add the items to.
     * @return collection The collection, with this plugin's items appended.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_moodesk_ticket',
            [
                'userid'      => 'privacy:metadata:local_moodesk_ticket:userid',
                'createdby'   => 'privacy:metadata:local_moodesk_ticket:createdby',
                'assigneeid'  => 'privacy:metadata:local_moodesk_ticket:assigneeid',
                'usermodified' => 'privacy:metadata:local_moodesk_ticket:usermodified',
                'subject'     => 'privacy:metadata:local_moodesk_ticket:subject',
            ],
            'privacy:metadata:local_moodesk_ticket'
        );

        $collection->add_database_table(
            'local_moodesk_reply',
            [
                'userid'       => 'privacy:metadata:local_moodesk_reply:userid',
                'usermodified' => 'privacy:metadata:local_moodesk_reply:usermodified',
                'message'      => 'privacy:metadata:local_moodesk_reply:message',
            ],
            'privacy:metadata:local_moodesk_reply'
        );

        $collection->add_database_table(
            'local_moodesk_ticket_history',
            [
                'userid'   => 'privacy:metadata:local_moodesk_ticket_history:userid',
                'action'   => 'privacy:metadata:local_moodesk_ticket_history:action',
                'oldvalue' => 'privacy:metadata:local_moodesk_ticket_history:oldvalue',
                'newvalue' => 'privacy:metadata:local_moodesk_ticket_history:newvalue',
            ],
            'privacy:metadata:local_moodesk_ticket_history'
        );

        $collection->add_database_table(
            'local_moodesk_dept_member',
            [
                'userid'       => 'privacy:metadata:local_moodesk_dept_member:userid',
                'departmentid' => 'privacy:metadata:local_moodesk_dept_member:departmentid',
            ],
            'privacy:metadata:local_moodesk_dept_member'
        );

        // Operational incidents hold no PII in their own payload — the context
        // allowlist in the incident domain class forbids it — but the two actor
        // columns are userids, and an undeclared table holding userids is a gap
        // the day the first row lands, not the day someone notices.
        $collection->add_database_table(
            'local_moodesk_incident',
            [
                'acknowledgedby' => 'privacy:metadata:local_moodesk_incident:acknowledgedby',
                'resolvedby'     => 'privacy:metadata:local_moodesk_incident:resolvedby',
            ],
            'privacy:metadata:local_moodesk_incident'
        );

        $collection->add_database_table(
            'local_moodesk_department',
            [
                'usermodified' => 'privacy:metadata:local_moodesk_department:usermodified',
            ],
            'privacy:metadata:local_moodesk_department'
        );

        $collection->add_database_table(
            'local_moodesk_category',
            [
                'usermodified' => 'privacy:metadata:local_moodesk_category:usermodified',
            ],
            'privacy:metadata:local_moodesk_category'
        );

        // Knowledge base articles (KB-01). The body is the organisation's
        // content; what belongs to a person is having written or edited it.
        $collection->add_database_table(
            'local_moodesk_kb_article',
            [
                'createdby'    => 'privacy:metadata:local_moodesk_kb_article:createdby',
                'usermodified' => 'privacy:metadata:local_moodesk_kb_article:usermodified',
                'title'        => 'privacy:metadata:local_moodesk_kb_article:title',
                'timecreated'  => 'privacy:metadata:local_moodesk_kb_article:timecreated',
                'timemodified' => 'privacy:metadata:local_moodesk_kb_article:timemodified',
            ],
            'privacy:metadata:local_moodesk_kb_article'
        );

        // User preferences. The sidebar state is one fixed key; saved views are
        // one key per view a user creates, under a shared prefix, and carry a
        // name the user typed.
        $collection->add_user_preference(
            'local_moodesk_sidebar_collapsed',
            'privacy:metadata:preference:local_moodesk_sidebar_collapsed'
        );

        $collection->add_user_preference(
            saved_view_service::PREFIX . '*',
            'privacy:metadata:preference:savedview'
        );

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');

        // The knowledge base events (kb_article_viewed, kb_searched, kb_article_linked)
        // reach the standard log with the user's id, so what they record is linked to a
        // person. The log stores export and erase it. kb_searched carries no search text.
        $collection->add_plugintype_link('logstore', [], 'privacy:metadata:logstore');

        // The Pro tables of 2.x, while an upgraded site keeps them.
        legacy_pro_data::add_metadata($collection);

        return $collection;
    }

    /*
     * -------------------------------------------------------------------------
     * Context list
     * -------------------------------------------------------------------------
     */

    /**
     * List the contexts holding personal data for the given user.
     *
     * All data for this plugin lives under context_system, because Phase 1
     * capability checks are system-level.
     *
     * @param int $userid The user to look up.
     * @return contextlist The contexts containing that user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $cl = new contextlist();

        // User is a requester on at least one ticket.
        if ($DB->record_exists('local_moodesk_ticket', ['userid' => $userid])) {
            $cl->add_system_context();
            return $cl;
        }

        // User has posted a reply.
        if ($DB->record_exists('local_moodesk_reply', ['userid' => $userid])) {
            $cl->add_system_context();
            return $cl;
        }

        // User appears in the audit log.
        if ($DB->record_exists('local_moodesk_ticket_history', ['userid' => $userid])) {
            $cl->add_system_context();
            return $cl;
        }

        // User is an assignee on a ticket.
        if ($DB->record_exists('local_moodesk_ticket', ['assigneeid' => $userid])) {
            $cl->add_system_context();
            return $cl;
        }

        // User is an agent member of a department.
        if ($DB->record_exists('local_moodesk_dept_member', ['userid' => $userid])) {
            $cl->add_system_context();
            return $cl;
        }

        // User acknowledged or resolved an operational incident.
        $incidentsql = 'SELECT 1
                          FROM {local_moodesk_incident}
                         WHERE acknowledgedby = :ack
                            OR resolvedby = :res';
        if ($DB->record_exists_sql($incidentsql, ['ack' => $userid, 'res' => $userid])) {
            $cl->add_system_context();
            return $cl;
        }

        // User is the last modifier of a configuration or operational row.
        // Someone who only ever edited a department is still named by it, and
        // before R2 that person's data was not found here at all.
        foreach (array_keys(self::USERMODIFIED_TABLES) as $table) {
            if ($DB->record_exists($table, ['usermodified' => $userid])) {
                $cl->add_system_context();
                return $cl;
            }
        }

        // User wrote a knowledge base article. Editing one is covered by the
        // usermodified loop above.
        if ($DB->record_exists('local_moodesk_kb_article', ['createdby' => $userid])) {
            $cl->add_system_context();
            return $cl;
        }

        // A row in a Pro table kept from 2.x.
        if (legacy_pro_data::holds_data($userid)) {
            $cl->add_system_context();
        }

        return $cl;
    }

    /**
     * Add the users who hold personal data in the given context.
     *
     * @param userlist $userlist The userlist to populate, carrying the context.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!($context instanceof \context_system)) {
            return;
        }

        global $DB;

        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid   FROM {local_moodesk_ticket}', []);
        // The first argument must name the column the subquery actually returns
        // (userlist wraps it as "SELECT <field> FROM (<sql>) target"), so the
        // createdby and assigneeid lookups pass their own column name, not 'userid'.
        $userlist->add_from_sql('createdby', 'SELECT DISTINCT createdby FROM {local_moodesk_ticket}', []);
        $sql = 'SELECT DISTINCT assigneeid FROM {local_moodesk_ticket} WHERE assigneeid IS NOT NULL';
        $userlist->add_from_sql('assigneeid', $sql, []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid   FROM {local_moodesk_reply}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid   FROM {local_moodesk_ticket_history}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid   FROM {local_moodesk_dept_member}', []);
        // Same rule as createdby/assigneeid above: name the column the subquery
        // returns, not 'userid'.
        $sql = 'SELECT DISTINCT acknowledgedby FROM {local_moodesk_incident} WHERE acknowledgedby IS NOT NULL';
        $userlist->add_from_sql('acknowledgedby', $sql, []);
        $sql = 'SELECT DISTINCT resolvedby FROM {local_moodesk_incident} WHERE resolvedby IS NOT NULL';
        $userlist->add_from_sql('resolvedby', $sql, []);

        // Authors of knowledge base articles; editors come through the
        // usermodified loop below.
        $userlist->add_from_sql(
            'createdby',
            'SELECT DISTINCT createdby FROM {local_moodesk_kb_article} WHERE createdby <> 0',
            []
        );

        // Last modifiers of configuration and operational rows (audit R2).
        foreach (array_keys(self::USERMODIFIED_TABLES) as $table) {
            $userlist->add_from_sql(
                'usermodified',
                "SELECT DISTINCT usermodified FROM {" . $table . "} WHERE usermodified <> 0",
                []
            );
        }

        legacy_pro_data::add_users($userlist);
    }

    /*
     * -------------------------------------------------------------------------
     * Data export
     * -------------------------------------------------------------------------
     */

    /**
     * Export all personal data the approved contexts hold for one user.
     *
     * @param approved_contextlist $contextlist Approved contexts plus the user.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            return;
        }

        $userid  = $contextlist->get_user()->id;
        $context = \context_system::instance();

        // Export tickets where user is the requester.
        $tickets = $DB->get_records('local_moodesk_ticket', ['userid' => $userid], 'timecreated ASC');
        foreach ($tickets as $ticket) {
            $subcontext = [
                get_string('pluginname', 'local_moodesk'),
                get_string('privacy_tickets', 'local_moodesk'),
                $ticket->id,
            ];

            writer::with_context($context)->export_data($subcontext, (object) [
                'id'         => $ticket->id,
                'subject'    => $ticket->subject,
                'status'     => $ticket->status,
                'priority'   => $ticket->priority,
                'timecreated' => \core_privacy\local\request\transform::datetime($ticket->timecreated),
            ]);

            // Export replies on this ticket authored by the user.
            $replies = $DB->get_records('local_moodesk_reply', ['ticketid' => $ticket->id, 'userid' => $userid]);
            foreach ($replies as $reply) {
                $replysubcontext = array_merge(
                    $subcontext,
                    [get_string('privacy_replies', 'local_moodesk'), $reply->id]
                );

                writer::with_context($context)->export_data(
                    $replysubcontext,
                    (object) [
                        'message'     => $reply->message,
                        'type'        => $reply->type,
                        'timecreated' => \core_privacy\local\request\transform::datetime($reply->timecreated),
                    ]
                );

                // The files attached to this reply. Their absence was the larger
                // half of R2: the agent branch below has always exported this
                // area, the requester branch never did, and email ingestion puts
                // *every* attachment here — including those on the message that
                // opened the ticket. A requester who works by email therefore
                // had no attachment exported at all.
                writer::with_context($context)->export_area_files(
                    $replysubcontext,
                    'local_moodesk',
                    'reply_attachment',
                    $reply->id
                );
            }

            // The legacy area. Nothing has written to it since Phase 6 — even
            // the web composer saves against the first reply — but a site
            // upgraded from before that holds files here, and they were the
            // requester's own upload at creation time. Keyed by ticket because
            // there is no reply to ask; see the note on the class.
            writer::with_context($context)->export_area_files(
                $subcontext,
                'local_moodesk',
                'ticket_attachment',
                $ticket->id
            );
        }

        // Export replies on other tickets (e.g. agent replies to other users' tickets).
        $sql = "SELECT r.*
                  FROM {local_moodesk_reply} r
                  JOIN {local_moodesk_ticket} t ON t.id = r.ticketid
                 WHERE r.userid = :uid
                   AND t.userid != :uid2";
        $agentreplies = $DB->get_records_sql($sql, ['uid' => $userid, 'uid2' => $userid]);
        foreach ($agentreplies as $reply) {
            $subcontext = [
                get_string('pluginname', 'local_moodesk'),
                get_string('privacy_agent_replies', 'local_moodesk'),
                $reply->id,
            ];
            writer::with_context($context)->export_data($subcontext, (object) [
                'ticketid'    => $reply->ticketid,
                'message'     => $reply->message,
                'type'        => $reply->type,
                'timecreated' => \core_privacy\local\request\transform::datetime($reply->timecreated),
            ]);
            writer::with_context($context)->export_area_files(
                $subcontext,
                'local_moodesk',
                'reply_attachment',
                $reply->id
            );
        }

        // Export audit history entries for this user.
        $history = $DB->get_records('local_moodesk_ticket_history', ['userid' => $userid], 'timecreated ASC');
        foreach ($history as $entry) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_history', 'local_moodesk'),
                    $entry->id,
                ],
                (object) [
                    'ticketid'    => $entry->ticketid,
                    'action'      => $entry->action,
                    'oldvalue'    => $entry->oldvalue,
                    'newvalue'    => $entry->newvalue,
                    'timecreated' => \core_privacy\local\request\transform::datetime($entry->timecreated),
                ]
            );
        }

        // Export department memberships (agent role data).
        $deptmemberships = $DB->get_records('local_moodesk_dept_member', ['userid' => $userid], 'timecreated ASC');
        foreach ($deptmemberships as $row) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_dept_memberships', 'local_moodesk'),
                    $row->id,
                ],
                (object) [
                    'departmentid' => $row->departmentid,
                    'timecreated'  => \core_privacy\local\request\transform::datetime($row->timecreated),
                ]
            );
        }

        // Export the operational incidents this user acted on. The incident
        // itself describes the plugin failing, not the person — what belongs to
        // them is the act of acknowledging or resolving it, so that is what the
        // export says.
        $incidentsql = 'SELECT *
                          FROM {local_moodesk_incident}
                         WHERE acknowledgedby = :ack
                            OR resolvedby = :res
                      ORDER BY timefirstseen ASC';
        $incidents = $DB->get_records_sql($incidentsql, ['ack' => $userid, 'res' => $userid]);
        foreach ($incidents as $row) {
            $acted = [];
            if ((int) $row->acknowledgedby === $userid && $row->timeacknowledged !== null) {
                $acted['timeacknowledged'] =
                    \core_privacy\local\request\transform::datetime((int) $row->timeacknowledged);
            }
            if ((int) $row->resolvedby === $userid && $row->timeresolved !== null) {
                $acted['timeresolved'] =
                    \core_privacy\local\request\transform::datetime((int) $row->timeresolved);
            }

            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_incidents', 'local_moodesk'),
                    $row->id,
                ],
                (object) array_merge([
                    'source'   => $row->source,
                    'code'     => $row->code,
                    'severity' => $row->severity,
                ], $acted)
            );
        }

        self::export_kb_articles($userid, $context);

        legacy_pro_data::export((int) $userid, $context);
    }

    /**
     * Export the knowledge base articles this user wrote or last edited.
     *
     * The body is not exported: it is the organisation's content, and an
     * article edited by five people would otherwise leave with each of them.
     * What is the person's — and what the export says — is that they wrote
     * it, or that they were the last to change it, and when.
     *
     * @param int      $userid  The user being exported.
     * @param \context $context The system context to write under.
     * @return void
     */
    private static function export_kb_articles(int $userid, \context $context): void {
        global $DB;

        $sql = 'SELECT *
                  FROM {local_moodesk_kb_article}
                 WHERE createdby = :author
                    OR usermodified = :editor
              ORDER BY timecreated ASC';
        $rows = $DB->get_records_sql($sql, ['author' => $userid, 'editor' => $userid]);

        foreach ($rows as $row) {
            $data = [
                'title'   => $row->title,
                'lang'    => $row->lang,
                // Which article this row is a translation of (KB-06): the
                // group's id, which is the id of the row it was first
                // written in — a fact about the article, not about a person.
                'groupid' => (int) $row->groupid,
                'status'  => (int) $row->status,
            ];
            if ((int) $row->createdby === $userid) {
                $data['author']      = true;
                $data['timecreated'] = \core_privacy\local\request\transform::datetime((int) $row->timecreated);
            }
            if ((int) $row->usermodified === $userid) {
                $data['lasteditor']   = true;
                $data['timemodified'] = \core_privacy\local\request\transform::datetime((int) $row->timemodified);
            }

            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_kb_articles', 'local_moodesk'),
                    $row->id,
                ],
                (object) $data
            );
        }
    }

    /*
     * -------------------------------------------------------------------------
     * User preferences
     * -------------------------------------------------------------------------
     */

    /**
     * Export the preferences this plugin stores for one user.
     *
     * Two shapes. The sidebar state is a single fixed key. Saved views are one
     * key per view under a shared prefix, each holding a name the user typed and
     * the filter parameters behind it — so they are found by walking the user's
     * preferences rather than by asking for a name we cannot know in advance.
     *
     * Note the prefix is `local_mhd_savedview_`, not `local_moodesk_`: the table
     * rename never reached user preferences, and renaming them now would orphan
     * every saved view on every existing site.
     *
     * @param int $userid The user to export for.
     * @return void
     */
    public static function export_user_preferences(int $userid): void {
        $collapsed = get_user_preferences('local_moodesk_sidebar_collapsed', null, $userid);
        if ($collapsed !== null) {
            writer::export_user_preference(
                'local_moodesk',
                'local_moodesk_sidebar_collapsed',
                \core_privacy\local\request\transform::yesno($collapsed),
                get_string('privacy:metadata:preference:local_moodesk_sidebar_collapsed', 'local_moodesk')
            );
        }

        foreach ((array) get_user_preferences(null, null, $userid) as $name => $value) {
            if (strpos((string) $name, saved_view_service::PREFIX) !== 0 || empty($value)) {
                continue;
            }

            $decoded = json_decode((string) $value, true);
            $description = is_array($decoded) && isset($decoded['name'])
                ? (string) $decoded['name']
                : (string) $value;

            writer::export_user_preference(
                'local_moodesk',
                $name,
                $description,
                get_string('privacy:metadata:preference:savedview', 'local_moodesk')
            );
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Data deletion
     * -------------------------------------------------------------------------
     */

    /**
     * Delete all personal data for all users in the given context.
     *
     * For system context this means the entire plugin dataset.
     *
     * @param \context $context The context to purge; ignored unless system.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if (!($context instanceof \context_system)) {
            return;
        }
        self::delete_all_plugin_data();
    }

    /**
     * Delete all personal data the approved contexts hold for one user.
     *
     * @param approved_contextlist $contextlist Approved contexts plus the user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!in_array(SYSCONTEXTID, $contextlist->get_contextids())) {
            return;
        }
        self::anonymise_user($contextlist->get_user()->id);
    }

    /**
     * Delete personal data for the approved users in the given context.
     *
     * @param approved_userlist $userlist Approved users plus the context.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!($userlist->get_context() instanceof \context_system)) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::anonymise_user($userid);
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Private helpers
     * -------------------------------------------------------------------------
     */

    /**
     * Anonymise all personal data for a single user.
     *
     * Strategy: replace PII with anonymised placeholders rather than deleting
     * rows, so that ticket threads and audit trails remain internally consistent.
     * Ticket content (subject, message) authored by the user is redacted.
     * FK references (assigneeid, userid on tickets) are zeroed.
     *
     * @param int $userid
     */
    private static function anonymise_user(int $userid): void {
        global $DB;

        $anon = get_string('privacy_anon_user', 'local_moodesk');

        // Capture ticket IDs and reply IDs BEFORE we zero out the FKs — we
        // need these to scrub what lives on rows linked by ticketid/replyid.
        $ticketids = $DB->get_fieldset_select('local_moodesk_ticket', 'id', 'userid = ?', [$userid]);
        $replyids  = $DB->get_fieldset_select('local_moodesk_reply', 'id', 'userid = ?', [$userid]);

        // The Pro tables kept from 2.x find a sender through the reply and ticket
        // ids zeroed below, so they go first.
        legacy_pro_data::erase_user((int) $userid, array_map('intval', $ticketids));

        // A plugin's rows lead back to the person through the ids zeroed below.
        \core\di::get(\core\hook\manager::class)->dispatch(new user_erasing($userid, $ticketids, $replyids));

        $context = \context_system::instance();
        $fs      = get_file_storage();

        // Delete attachment files BEFORE the FKs go away.
        foreach ($ticketids as $ticketid) {
            $fs->delete_area_files($context->id, 'local_moodesk', 'ticket_attachment', $ticketid);
        }
        foreach ($replyids as $replyid) {
            $fs->delete_area_files($context->id, 'local_moodesk', 'reply_attachment', $replyid);
        }

        // Department memberships: GDPR right-to-erasure — drop the membership
        // row entirely (no audit value once the user is gone).
        $DB->delete_records('local_moodesk_dept_member', ['userid' => $userid]);

        // Redact ticket subject on owned tickets, then zero the FKs.
        $DB->set_field('local_moodesk_ticket', 'subject', $anon, ['userid' => $userid]);
        $DB->set_field('local_moodesk_ticket', 'userid', 0, ['userid' => $userid]);
        $DB->set_field('local_moodesk_ticket', 'createdby', 0, ['createdby'  => $userid]);
        $DB->set_field('local_moodesk_ticket', 'assigneeid', null, ['assigneeid' => $userid]);

        // Redact reply messages.
        $DB->set_field('local_moodesk_reply', 'message', $anon, ['userid' => $userid]);
        $DB->set_field('local_moodesk_reply', 'userid', 0, ['userid' => $userid]);

        // Zero out audit history actor references (content is structural, not PII).
        $DB->set_field('local_moodesk_ticket_history', 'userid', 0, ['userid' => $userid]);

        // Operational incidents are anonymised, never deleted. The row records
        // that the plugin failed — a fact about the software, not about the
        // person who happened to click acknowledge — so erasing it would
        // destroy operational history to remove one userid.
        $DB->set_field('local_moodesk_incident', 'acknowledgedby', 0, ['acknowledgedby' => $userid]);
        $DB->set_field('local_moodesk_incident', 'resolvedby', 0, ['resolvedby' => $userid]);

        // The usermodified column, everywhere it exists (audit finding R2).
        //
        // The provider's own docblock has named this personal data since the
        // beginning while no code ever touched it, so a user could be erased
        // and still be named as the last editor. The row stays and the reference
        // goes — but that was decided table by table, and the reason for each is
        // recorded next to it in USERMODIFIED_TABLES.
        foreach (array_keys(self::USERMODIFIED_TABLES) as $table) {
            $DB->set_field($table, 'usermodified', 0, ['usermodified' => $userid]);
        }

        // The author of a knowledge base article, on the same reasoning as
        // its editor: the article is the organisation's answer to a question
        // and stays; the name goes.
        $DB->set_field('local_moodesk_kb_article', 'createdby', 0, ['createdby' => $userid]);

        // User ids frozen inside the history's JSON. Scoped by ticket these rows
        // are invisible: a value keeps the assignee it was written with even
        // after the ticket has been reassigned to a third person.
        snapshot_redactor::redact_column('local_moodesk_ticket_history', 'oldvalue', $userid, self::HISTORY_USERID_PATHS);
        snapshot_redactor::redact_column('local_moodesk_ticket_history', 'newvalue', $userid, self::HISTORY_USERID_PATHS);

        // Preferences. Saved views are the user's own content — a name they
        // typed and the filters behind it — and the sidebar state is theirs
        // too. Neither is operational history worth preserving anonymously.
        self::delete_preferences_for_user($userid);
    }

    /**
     * Delete the preferences this plugin stores for one user.
     *
     * Part of the user_preference_provider contract in spirit: the interface
     * only mandates export, but a provider that exports a preference and never
     * erases it has answered half the request.
     *
     * @param int $userid The user to clear preferences for.
     * @return void
     */
    private static function delete_preferences_for_user(int $userid): void {
        unset_user_preference('local_moodesk_sidebar_collapsed', $userid);

        foreach ((array) get_user_preferences(null, null, $userid) as $name => $value) {
            if (strpos((string) $name, saved_view_service::PREFIX) === 0) {
                unset_user_preference($name, $userid);
            }
        }
    }

    /**
     * Delete all plugin data (used when the entire system context is wiped).
     */
    private static function delete_all_plugin_data(): void {
        global $DB;

        legacy_pro_data::delete_all();

        // Order respects FK dependencies (children before parents).
        $DB->delete_records('local_moodesk_ticket_history');
        $DB->delete_records('local_moodesk_reply');
        $DB->delete_records('local_moodesk_ticket');
        $DB->delete_records('local_moodesk_dept_member');
        // Wiping the system context takes the operational history with it: the
        // incidents describe a dataset that is no longer there.
        $DB->delete_records('local_moodesk_incident');

        // Configuration survives a context wipe — a department or a category
        // describes how the site is set up, not what any person did — but the
        // `usermodified` on each is declared personal data, so the wipe has to
        // take the name even though it leaves the row.
        $survivors = [
            'local_moodesk_department',
            'local_moodesk_category',
            // An article is the organisation's content, like a category is
            // its structure: it outlives the people who wrote it, nameless.
            'local_moodesk_kb_article',
        ];
        foreach ($survivors as $table) {
            $DB->set_field_select($table, 'usermodified', 0, 'usermodified <> 0', []);
        }
        $DB->set_field_select('local_moodesk_kb_article', 'createdby', 0, 'createdby <> 0', []);

        $context = \context_system::instance();
        $fs      = get_file_storage();
        $fs->delete_area_files($context->id, 'local_moodesk', 'ticket_attachment');
        $fs->delete_area_files($context->id, 'local_moodesk', 'reply_attachment');
    }
}
