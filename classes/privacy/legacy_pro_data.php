<?php
// This file is part of Moodle - https://moodle.org/
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
 * Privacy of the Pro tables a site upgraded from 2.x still holds.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The 2.x Pro tables that 3.0.0 no longer declares and does not drop.
 *
 * Nothing reads or writes them any more, and nothing copies them to the add-on
 * (decision 7 of two-plugin-split-plan.md). While a site keeps them they still
 * hold personal data, so the base's provider exports and erases it through this
 * class, the way local_moodeskpro does for its own tables of the same shape.
 * Everything here does nothing on a site that does not have the tables: a fresh
 * install, or a site that has removed them.
 *
 * The rules are the add-on's, applied to the `local_moodesk_` names. Two things
 * differ: the add-on's ticket companion table has no legacy twin, so the wipe
 * leaves the base's tickets to the base, and the export goes under one more
 * folder, *Data from before 3.0.0*.
 */
class legacy_pro_data {
    /** Value of local_moodesk_email_message.role for the message that opened a ticket. */
    private const EMAIL_ROLE_TICKET_ORIGIN = 0;

    /** The legacy tables that hold personal data. */
    private const TABLES = [
        'local_moodesk_rule',
        'local_moodesk_rule_log',
        'local_moodesk_email_message',
        'local_moodesk_webhook',
        'local_moodesk_webhook_log',
        'local_moodesk_webhook_queue',
        'local_moodesk_cfield',
        'local_moodesk_cfield_value',
        'local_moodesk_csat_rating',
        'local_moodesk_team',
        'local_moodesk_team_member',
        'local_moodesk_api_token',
        'local_moodesk_merge_audit',
        'local_moodesk_kb_feedback',
        'local_moodesk_kb_event',
    ];

    /**
     * Keys holding a user id inside a webhook payload, as dot paths.
     *
     * `extra.actor` appears only on ticket.merge_reverted. The other `extra`
     * fields carry ids of tickets, replies, categories, departments and teams —
     * ticket_field_changed never reports an assignee — so they are not listed
     * and must not be redacted: rewriting them would corrupt the event.
     *
     * @var string[]
     */
    private const WEBHOOK_USERID_PATHS = [
        'ticket.requesterid',
        'ticket.assigneeid',
        'extra.actor',
    ];

    /**
     * What erasure does with `usermodified`, table by table.
     *
     * As the add-on decides it for its own tables: keep the row, clear the
     * reference.
     *
     * @var array<string, string> table => why the row is kept and the reference cleared
     */
    private const USERMODIFIED_TABLES = [
        // Site configuration. A webhook subscription keeps firing after the
        // administrator who last edited it leaves; deleting it would silently
        // stop an integration the site still depends on.
        'local_moodesk_webhook' =>
            'Live site configuration — deleting it would stop an integration the site still depends on.',
        // Same shape as webhook, with a sharper failure: an automation rule that
        // vanishes changes how every future ticket is routed.
        'local_moodesk_rule' =>
            'Live automation — deleting it would silently change how future tickets are routed.',
        // Structural. A team is an assignment target for open work.
        'local_moodesk_team' =>
            'Structural — open tickets are assigned to it.',
        // A custom field *definition*. Deleting it would strip a field from
        // every ticket that ever used it, including tickets not yet closed.
        'local_moodesk_cfield' =>
            'A field definition — deleting it would strip that field from every ticket that used it.',
        // A value on somebody else's ticket. The requester's own values are
        // redacted through $ticketids in erase_user(); this column is about
        // whoever last edited the row, usually an agent, and the value stays
        // because it belongs to the requester.
        'local_moodesk_cfield_value' =>
            'The value belongs to the ticket\'s requester; only the editor\'s name is theirs to lose.',
        // Feedback a requester left. Deleting it on an agent's erasure request
        // would remove a second person's words and silently move the site's
        // satisfaction average.
        'local_moodesk_csat_rating' =>
            'Somebody else\'s feedback, and deleting it would move the site\'s satisfaction figures.',
        // The row names two people: the member (`userid`) and whoever added them
        // (`usermodified`). An erased user's own membership row is deleted in
        // erase_user(); a row where they are only the editor stays, because it is
        // another agent's team membership and deleting it would revoke that
        // person's access.
        'local_moodesk_team_member' =>
            'The row is another agent\'s membership; deleting it would revoke their access.',
    ];

    /**
     * Whether this site still has the legacy tables.
     *
     * All or none: 3.0.0 leaves every one of them on an upgraded site and creates
     * none on a fresh install.
     *
     * @return bool
     */
    public static function present(): bool {
        global $DB;

        $dbman = $DB->get_manager();
        foreach (self::TABLES as $table) {
            if (!$dbman->table_exists($table)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Declare the legacy tables.
     *
     * Only where they exist: a fresh install never has them, and metadata names
     * tables that are there.
     *
     * @param collection $collection
     * @return collection
     */
    public static function add_metadata(collection $collection): collection {
        if (!self::present()) {
            return $collection;
        }
        $tables = [
            'local_moodesk_team_member' => ['userid', 'teamid', 'usermodified'],
            'local_moodesk_csat_rating' => ['rating', 'comment', 'ticketid', 'usermodified'],
            'local_moodesk_email_message' => ['sender_email', 'sender_name', 'raw_subject', 'message_id', 'in_reply_to',
                'references_header', 'token_userid', 'resolved_userid', 'reject_reason'],
            'local_moodesk_cfield_value' => ['ticketid', 'value', 'usermodified'],
            'local_moodesk_api_token' => ['userid', 'name', 'scopes'],
            'local_moodesk_merge_audit' => ['actorid', 'reverted_actorid', 'reason', 'source_snapshot'],
            'local_moodesk_webhook_queue' => ['payload'],
            'local_moodesk_webhook_log' => ['payload', 'response_body'],
            'local_moodesk_webhook' => ['usermodified'],
            'local_moodesk_rule' => ['actions', 'usermodified'],
            'local_moodesk_rule_log' => ['note'],
            'local_moodesk_kb_feedback' => ['userid', 'articleid', 'helpful', 'timecreated', 'timemodified'],
            'local_moodesk_kb_event' => ['querynorm'],
            'local_moodesk_team' => ['usermodified'],
            'local_moodesk_cfield' => ['usermodified'],
        ];
        foreach ($tables as $table => $fields) {
            $map = [];
            foreach ($fields as $field) {
                $map[$field] = "privacy:metadata:{$table}:{$field}";
            }
            $collection->add_database_table($table, $map, "privacy:metadata:{$table}");
        }
        return $collection;
    }

    /**
     * Whether any legacy table holds something about the user.
     *
     * @param int $userid The user to look up.
     * @return bool
     */
    public static function holds_data(int $userid): bool {
        global $DB;

        if (!self::present()) {
            return false;
        }

        // User is an agent member of a team, or owns a REST API token, or
        // answered "was this article helpful?" on at least one article.
        foreach (['local_moodesk_team_member', 'local_moodesk_api_token', 'local_moodesk_kb_feedback'] as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                return true;
            }
        }

        // User merged a ticket, or undid someone else's merge.
        $mergesql = 'SELECT 1
                       FROM {local_moodesk_merge_audit}
                      WHERE actorid = :actor
                         OR reverted_actorid = :reverter';
        if ($DB->record_exists_sql($mergesql, ['actor' => $userid, 'reverter' => $userid])) {
            return true;
        }

        // A rating or a custom field value sits on one of the user's tickets.
        foreach (['local_moodesk_csat_rating', 'local_moodesk_cfield_value'] as $table) {
            $sql = 'SELECT 1
                      FROM {' . $table . '} x
                      JOIN {local_moodesk_ticket} t ON t.id = x.ticketid
                     WHERE t.userid = :userid';
            if ($DB->record_exists_sql($sql, ['userid' => $userid])) {
                return true;
            }
        }

        // User is the last modifier of a configuration or operational row.
        foreach (array_keys(self::USERMODIFIED_TABLES) as $table) {
            if ($DB->record_exists($table, ['usermodified' => $userid])) {
                return true;
            }
        }

        // Inbound mail this person sent, or that carried a token issued to
        // them. Two relations, either of which is enough on its own — see
        // email_rows_sent_by().
        return self::has_email_rows_sent_by($userid)
            || $DB->record_exists('local_moodesk_email_message', ['token_userid' => $userid]);
    }

    /**
     * Add the users the legacy tables name.
     *
     * @param userlist $userlist A userlist on the system context.
     */
    public static function add_users(userlist $userlist): void {
        if (!self::present()) {
            return;
        }

        global $DB;

        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_moodesk_team_member}', []);
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_moodesk_api_token}', []);
        // The first argument must name the column the subquery actually returns
        // (userlist wraps it as "SELECT <field> FROM (<sql>) target").
        $userlist->add_from_sql('actorid', 'SELECT DISTINCT actorid FROM {local_moodesk_merge_audit}', []);
        $sql = 'SELECT DISTINCT reverted_actorid FROM {local_moodesk_merge_audit} WHERE reverted_actorid IS NOT NULL';
        $userlist->add_from_sql('reverted_actorid', $sql, []);

        // Readers who answered "was this article helpful?" (KB-07).
        $userlist->add_from_sql('userid', 'SELECT DISTINCT userid FROM {local_moodesk_kb_feedback}', []);

        // Requesters of the tickets a rating or a custom field value sits on.
        foreach (['local_moodesk_csat_rating', 'local_moodesk_cfield_value'] as $table) {
            $sql = 'SELECT DISTINCT t.userid
                      FROM {' . $table . '} x
                      JOIN {local_moodesk_ticket} t ON t.id = x.ticketid';
            $userlist->add_from_sql('userid', $sql, []);
        }

        // Last modifiers of configuration and operational rows (audit R2).
        foreach (array_keys(self::USERMODIFIED_TABLES) as $table) {
            $userlist->add_from_sql(
                'usermodified',
                "SELECT DISTINCT usermodified FROM {" . $table . "} WHERE usermodified <> 0",
                []
            );
        }

        // Everyone an inbound message names, added relation by relation and
        // deduplicated by the userlist (PRIV-01).
        $sql = 'SELECT DISTINCT resolved_userid
                  FROM {local_moodesk_email_message}
                 WHERE resolved_userid IS NOT NULL';
        $userlist->add_from_sql('resolved_userid', $sql, []);

        $sql = 'SELECT DISTINCT token_userid
                  FROM {local_moodesk_email_message}
                 WHERE token_userid IS NOT NULL';
        $userlist->add_from_sql('token_userid', $sql, []);

        // Senders known through the reply the message created, or through the
        // ticket an origin row opened.
        $sql = 'SELECT DISTINCT r.userid
                  FROM {local_moodesk_email_message} em
                  JOIN {local_moodesk_reply} r ON r.id = em.replyid
                 WHERE em.resolved_userid IS NULL';
        $userlist->add_from_sql('userid', $sql, []);

        $sql = 'SELECT DISTINCT t.userid
                  FROM {local_moodesk_email_message} em
                  JOIN {local_moodesk_ticket} t ON t.id = em.ticketid
                 WHERE em.resolved_userid IS NULL
                   AND em.replyid IS NULL
                   AND em.role = :originrole';
        $userlist->add_from_sql('userid', $sql, ['originrole' => self::EMAIL_ROLE_TICKET_ORIGIN]);

        // The address fallback, under the same ambiguity rule erasure applies:
        // an address carried by two live accounts identifies neither, so it
        // adds nobody rather than adding the wrong person. Rows matching no
        // account at all are out of reach here by definition and are handled as
        // retention instead (DATA-01, DATA-02).
        $addresssql = 'SELECT DISTINCT u.id
                         FROM {user} u
                         JOIN {local_moodesk_email_message} em
                           ON ' . $DB->sql_equal('em.sender_email', 'u.email', false) . '
                        WHERE u.deleted = 0
                          AND em.resolved_userid IS NULL
                          AND em.replyid IS NULL
                          AND NOT EXISTS (SELECT 1
                                            FROM {user} dup
                                           WHERE dup.deleted = 0
                                             AND dup.id <> u.id
                                             AND ' . $DB->sql_equal('dup.email', 'u.email', false) . ')';
        $userlist->add_from_sql('id', $addresssql, []);
    }

    /**
     * Export what the legacy tables hold about one user.
     *
     * @param int      $userid
     * @param \context $context The system context.
     */
    public static function export(int $userid, \context $context): void {
        global $DB;

        if (!self::present()) {
            return;
        }

        // Export merges this user performed or undid. The source snapshot is
        // left out: it describes the ticket, not the agent, and exporting it
        // would hand one user a dump of another's ticket state.
        $mergesql = 'SELECT *
                       FROM {local_moodesk_merge_audit}
                      WHERE actorid = :actor
                         OR reverted_actorid = :reverter
                   ORDER BY mergedat ASC';
        $merges = $DB->get_records_sql($mergesql, ['actor' => $userid, 'reverter' => $userid]);
        foreach ($merges as $merge) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_legacy', 'local_moodesk'),
                    get_string('privacy_merges', 'local_moodesk'),
                    $merge->id,
                ],
                (object) [
                    'sourceticketid' => $merge->sourceticketid,
                    'targetticketid' => $merge->targetticketid,
                    'merged'         => (int) $merge->actorid === $userid,
                    'reverted'       => (int) $merge->reverted_actorid === $userid,
                    'reason'         => $merge->reason,
                    'mergedat'       => \core_privacy\local\request\transform::datetime($merge->mergedat),
                    'revertedat'     => $merge->revertedat
                        ? \core_privacy\local\request\transform::datetime($merge->revertedat)
                        : null,
                ]
            );
        }

        // Export team memberships (agent role data).
        $teammemberships = $DB->get_records('local_moodesk_team_member', ['userid' => $userid], 'timecreated ASC');
        foreach ($teammemberships as $row) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_legacy', 'local_moodesk'),
                    get_string('privacy_team_memberships', 'local_moodesk'),
                    $row->id,
                ],
                (object) [
                    'teamid'      => $row->teamid,
                    'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                ]
            );
        }

        // Export CSAT ratings on the user's own tickets (indirect PII via ticketid).
        $csatsql = "SELECT c.*
                      FROM {local_moodesk_csat_rating} c
                      JOIN {local_moodesk_ticket} t ON t.id = c.ticketid
                     WHERE t.userid = :uid
                  ORDER BY c.timecreated ASC";
        $csatrows = $DB->get_records_sql($csatsql, ['uid' => $userid]);
        foreach ($csatrows as $row) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_legacy', 'local_moodesk'),
                    get_string('privacy_csat_ratings', 'local_moodesk'),
                    $row->id,
                ],
                (object) [
                    'ticketid'     => $row->ticketid,
                    'rating'       => $row->rating,
                    'comment'      => $row->comment,
                    'submitted_at' => $row->submitted_at
                        ? \core_privacy\local\request\transform::datetime($row->submitted_at) : null,
                ]
            );
        }

        // Inbound mail, by what this person is to it rather than by whose
        // ticket it landed on. Both relations, in one pass — see
        // export_email_messages().
        self::export_email_messages($userid, $context);

        // Export custom field values on the user's own tickets (indirect PII via ticketid).
        $cfvsql = "SELECT v.*
                     FROM {local_moodesk_cfield_value} v
                     JOIN {local_moodesk_ticket} t ON t.id = v.ticketid
                    WHERE t.userid = :uid
                 ORDER BY v.timecreated ASC";
        $cfvrows = $DB->get_records_sql($cfvsql, ['uid' => $userid]);
        foreach ($cfvrows as $row) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_legacy', 'local_moodesk'),
                    get_string('privacy_custom_field_values', 'local_moodesk'),
                    $row->id,
                ],
                (object) [
                    'ticketid'    => $row->ticketid,
                    'fieldid'     => $row->fieldid,
                    'value'       => $row->value,
                    'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                ]
            );
        }

        // Export REST API token metadata owned by the user. The token secret is
        // never stored by this plugin, so it cannot (and must not) be exported;
        // expiry/last-used live on {external_tokens} and are owned by core.
        $tokens = $DB->get_records('local_moodesk_api_token', ['userid' => $userid], 'timecreated ASC');
        foreach ($tokens as $row) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_legacy', 'local_moodesk'),
                    get_string('privacy_api_tokens', 'local_moodesk'),
                    $row->id,
                ],
                (object) [
                    'name'        => $row->name,
                    'scopes'      => $row->scopes,
                    'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                ]
            );
        }

        self::export_kb_feedback($userid, $context);
    }

    /**
     * Export the answers this user gave to "was this article helpful?".
     *
     * One entry per translation row voted on: the article's title and
     * language (so the reader knows which version they judged), the answer,
     * and when it was first given and last changed. The article itself is not
     * exported — it is the organisation's; only the opinion is the person's.
     *
     * @param int      $userid  The user being exported.
     * @param \context $context The system context to write under.
     * @return void
     */
    private static function export_kb_feedback(int $userid, \context $context): void {
        global $DB;

        $sql = 'SELECT f.id, f.articleid, f.helpful, f.timecreated, f.timemodified, a.title, a.lang
                  FROM {local_moodesk_kb_feedback} f
             LEFT JOIN {local_moodesk_kb_article} a ON a.id = f.articleid
                 WHERE f.userid = :userid
              ORDER BY f.timecreated ASC, f.id ASC';
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);

        foreach ($rows as $row) {
            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_legacy', 'local_moodesk'),
                    get_string('privacy_kb_feedback', 'local_moodesk'),
                    $row->id,
                ],
                (object) [
                    'articleid'    => (int) $row->articleid,
                    'title'        => $row->title ?? '',
                    'lang'         => $row->lang ?? '',
                    'helpful'      => \core_privacy\local\request\transform::yesno((bool) $row->helpful),
                    'timecreated'  => \core_privacy\local\request\transform::datetime((int) $row->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime((int) $row->timemodified),
                ]
            );
        }
    }

    /**
     * Export the inbound mail this user is a subject of.
     *
     * One pass over both relations, so a row this person both sent and holds
     * the token for appears once, carrying the columns of both — not twice,
     * and never with a column belonging to somebody else.
     *
     * The row goes under `privacy_email_messages` when it reached a ticket and
     * under `privacy_email_audit` when it did not, which is the distinction the
     * two strings already drew and the only one a reader needs: correspondence
     * on a thread, versus mail that was turned away.
     *
     * @param int       $userid  The user being exported.
     * @param \context  $context The system context to write under.
     * @return void
     */
    private static function export_email_messages(int $userid, \context $context): void {
        $sent      = self::email_rows_sent_by($userid);
        $astoken   = self::email_rows_by_token_holder($userid);
        $rows      = $sent;

        foreach ($astoken as $id => $row) {
            if (!isset($rows[$id])) {
                $rows[$id] = $row;
            }
        }

        uasort($rows, function ($a, $b) {
            return [$a->timecreated, $a->id] <=> [$b->timecreated, $b->id];
        });

        foreach ($rows as $id => $row) {
            // What is true of the message rather than of a person: which thread
            // it reached, which message it was, when, and — on a refusal — why
            // it was turned away.
            $data = [
                'ticketid'      => $row->ticketid,
                'message_id'    => $row->message_id,
                'reject_reason' => $row->reject_reason,
                'timecreated'   => \core_privacy\local\request\transform::datetime($row->timecreated),
            ];

            if (isset($sent[$id])) {
                $data['sender_email']    = $row->sender_email;
                $data['sender_name']     = $row->sender_name;
                $data['raw_subject']     = $row->raw_subject;
                $data['resolved_userid'] = $row->resolved_userid;
            }

            // Present on its own when a token issued to this user was used from
            // an address that resolved to somebody else. They are entitled to
            // know their token was used and deliberately not to the other
            // person's address or subject line, which is why this is its own
            // relation and not a fact about the sender.
            if (isset($astoken[$id])) {
                $data['token_userid'] = $row->token_userid;
            }

            $area = $row->ticketid !== null ? 'privacy_email_messages' : 'privacy_email_audit';

            writer::with_context($context)->export_data(
                [
                    get_string('pluginname', 'local_moodesk'),
                    get_string('privacy_legacy', 'local_moodesk'),
                    get_string($area, 'local_moodesk'),
                    $row->id,
                ],
                (object) $data
            );
        }
    }

    /**
     * The rows this user sent, whatever ticket they landed on.
     *
     * **Sender is a relation, not ownership of the row.** A single row can name
     * three people at once — the sender, the user a conversation token was
     * issued to, and the requester of the ticket it threads onto — and each is
     * asked separately. This relation answers for `sender_email`,
     * `sender_name`, `resolved_userid` and `raw_subject`. The subject line is in
     * that list deliberately: it is what this person wrote in their message, not
     * something the ticket's requester is entitled to read back out of an
     * agent's correspondence.
     *
     * Four ways to recognise a sender, because the columns that identify one
     * arrived at different times:
     *
     * 1. `resolved_userid` — written since SEC-04A on replies and refusals.
     * 2. The author of the reply the message created. Ingestion writes that
     *    reply as the sender (`reply_service::add_reply_from_email($data,
     *    $userid)`), so its author is the sender by construction.
     * 3. On `ROLE_TICKET_ORIGIN`, the ticket's requester. An origin row carries
     *    no auth context at all and needs none: `create_ticket_from_email()`
     *    opens the ticket AS the matched sender.
     * 4. The declared address, matched case-insensitively — and only on rows
     *    the three rules above cannot reach at all: no resolved id, no reply to
     *    ask, and not an origin row with a ticket to ask instead.
     *
     * **The order is a floor, not a preference.** Rules 1 to 3 read an id the
     * pipeline actually recorded; rule 4 reads a string that two accounts may
     * share. Letting an address override a recorded id would attribute a message
     * to whichever account happens to carry the same address — which is why the
     * fallback is skipped entirely when the address is ambiguous. See
     * {@see self::unambiguous_address_for()}.
     *
     * @param int $userid The user to look for.
     * @return \stdClass[] Matching rows, keyed by id.
     */
    private static function email_rows_sent_by(int $userid): array {
        global $DB;

        [$sql, $params] = self::sender_relation_query($userid);

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Whether this user sent any inbound message at all.
     *
     * The same relation as {@see self::email_rows_sent_by()}, asked as a
     * question — contextlist only needs to know that something exists.
     *
     * @param int $userid The user to look for.
     * @return bool
     */
    private static function has_email_rows_sent_by(int $userid): bool {
        global $DB;

        [$sql, $params] = self::sender_relation_query($userid);

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * The SQL behind the sender relation, shared by both lookups above.
     *
     * @param int $userid The user to look for.
     * @return array [string $sql, array $params]
     */
    private static function sender_relation_query(int $userid): array {
        global $DB;

        $params = [
            'direct'     => $userid,
            'viareply'   => $userid,
            'viaticket'  => $userid,
            'originrole' => self::EMAIL_ROLE_TICKET_ORIGIN,
        ];

        $clauses = [
            'em.resolved_userid = :direct',
            '(em.resolved_userid IS NULL AND r.userid = :viareply)',
            '(em.resolved_userid IS NULL AND r.id IS NULL'
                . ' AND em.role = :originrole AND t.userid = :viaticket)',
        ];

        $address = self::unambiguous_address_for($userid);
        if ($address !== null) {
            // Only where nothing else can answer: no resolved id, no reply to
            // ask, and either not an origin row or one whose ticket is gone.
            $clauses[] = '(em.resolved_userid IS NULL AND em.replyid IS NULL'
                . ' AND (em.role <> :originrole2 OR t.id IS NULL)'
                . ' AND ' . $DB->sql_equal('em.sender_email', ':address', false) . ')';
            $params['originrole2'] = self::EMAIL_ROLE_TICKET_ORIGIN;
            $params['address']     = $address;
        }

        $sql = 'SELECT em.*
                  FROM {local_moodesk_email_message} em
             LEFT JOIN {local_moodesk_reply} r ON r.id = em.replyid
             LEFT JOIN {local_moodesk_ticket} t ON t.id = em.ticketid
                 WHERE ' . implode(' OR ', $clauses) . '
              ORDER BY em.timecreated ASC, em.id ASC';

        return [$sql, $params];
    }

    /**
     * The rows carrying a conversation token issued to this user.
     *
     * Its own relation, and the narrowest one: it answers for `token_userid`
     * and for nothing else. On `ROLE_TOKEN_SENDER_MISMATCH` the token holder is
     * deliberately not the sender — that disagreement is the whole point of the
     * row — so reading this as a claim on the sender's address would hand one
     * person another person's identity on the strength of a leaked token.
     *
     * @param int $userid The user to look for.
     * @return \stdClass[] Matching rows, keyed by id.
     */
    private static function email_rows_by_token_holder(int $userid): array {
        global $DB;

        return $DB->get_records('local_moodesk_email_message', ['token_userid' => $userid]);
    }

    /**
     * This user's address, or null when it identifies more than one account.
     *
     * The address fallback exists for rows that predate `resolved_userid` and
     * for mail that resolved to nobody. It is the only rule here that reads a
     * string rather than a recorded id, so it is also the only one that can
     * attribute a message to the wrong person: Moodle allows two live accounts
     * to share an address when `allowaccountssameemail` is on, and then one
     * user's export would carry the other's mail and one user's erasure would
     * anonymise it.
     *
     * When that is the case this returns null and the fallback is dropped
     * altogether. The cost is a legacy row nobody reaches; the alternative
     * costs a disclosure to the wrong person, and between the two only one is a
     * breach. Deleted accounts do not count — Moodle rewrites their address on
     * deletion, so they cannot collide with a live one.
     *
     * @param int $userid The user whose address to resolve.
     * @return string|null The address, or null when absent or shared.
     */
    private static function unambiguous_address_for(int $userid): ?string {
        global $DB;

        $email = $DB->get_field('user', 'email', ['id' => $userid]);
        if (empty($email)) {
            return null;
        }

        $shared = 'SELECT COUNT(u.id)
                     FROM {user} u
                    WHERE u.deleted = 0
                      AND u.id <> :self
                      AND ' . $DB->sql_equal('u.email', ':email', false);

        if ($DB->count_records_sql($shared, ['self' => $userid, 'email' => $email]) > 0) {
            return null;
        }

        return $email;
    }

    /**
     * Delete every legacy row that holds personal data, for a wipe of the system context.
     */
    public static function delete_all(): void {
        global $DB;

        if (!self::present()) {
            return;
        }

        // Order respects FK dependencies (children before parents).
        $DB->delete_records('local_moodesk_cfield_value');
        // Every reader's feedback goes; the articles it was about stay, in the
        // base. Analytics events hold no personal data and are left to retention.
        $DB->delete_records('local_moodesk_kb_feedback');
        $DB->delete_records('local_moodesk_email_message');
        $DB->delete_records('local_moodesk_csat_rating');
        // Wiping the whole system context is not the same as one person
        // exercising erasure: here the tickets themselves are going, so keeping
        // an audit trail of merges between them would preserve nothing.
        $DB->delete_records('local_moodesk_merge_audit');
        $DB->delete_records('local_moodesk_team_member');

        // Outbound webhook traffic and automation logs (audit finding R2). Both
        // queue and log quote the tickets they were about, so a wipe that left
        // them behind kept ticket subjects and requester ids after every ticket
        // was gone. rule_log.note is free text describing what a rule did to a
        // ticket that no longer exists.
        $DB->delete_records('local_moodesk_webhook_queue');
        $DB->delete_records('local_moodesk_webhook_log');
        $DB->delete_records('local_moodesk_rule_log');

        // Revoke all mooDesk-issued API tokens: native {external_tokens} rows
        // first (true revocation), then the companion metadata.
        $externaltokenids = $DB->get_fieldset_select('local_moodesk_api_token', 'externaltokenid', '', []);
        if (!empty($externaltokenids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($externaltokenids);
            $DB->delete_records_select('external_tokens', "id $insql", $inparams);
        }
        $DB->delete_records('local_moodesk_api_token');

        // Configuration survives a context wipe — a team, a custom field and an
        // automation rule describe how the site is set up, not what any person
        // did — but the `usermodified` on each of them is declared personal
        // data, so the wipe has to take the name even though it leaves the row.
        //
        // What deliberately stays is `rule.actions`: the agent it names is not
        // provenance, it is the instruction the rule executes, and blanking it
        // would leave a live rule assigning tickets to nobody. That is
        // configuration referring to a person, and it is removed by deleting
        // the rule, which is an operator's decision rather than this method's.
        $survivors = [
            'local_moodesk_webhook',
            'local_moodesk_rule',
            'local_moodesk_team',
            'local_moodesk_cfield',
        ];
        foreach ($survivors as $table) {
            $DB->set_field_select($table, 'usermodified', 0, 'usermodified <> 0', []);
        }
    }

    /**
     * Erase what the legacy tables keep about one person.
     *
     * Called by the provider before it zeroes the ticket and reply references,
     * which the sender and ticket lookups below read.
     *
     * @param int   $userid    The person.
     * @param int[] $ticketids The tickets they raised, while that can still be asked.
     */
    public static function erase_user(int $userid, array $ticketids): void {
        global $DB;

        if (!self::present()) {
            return;
        }

        $anon = get_string('privacy_anon_user', 'local_moodesk');

        // Inbound mail this person sent, resolved here for the same reason as
        // the ticket ids. Two of the four ways to recognise a sender read
        // `reply.userid` and `ticket.userid`.
        $sentemailids = array_keys(self::email_rows_sent_by($userid));

        if (!empty($ticketids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($ticketids);

            // Anonymise CSAT comments on the user's tickets — rating and counts
            // remain so aggregate metrics stay valid; only the free-text PII goes.
            $DB->set_field_select('local_moodesk_csat_rating', 'comment', null, "ticketid $insql", $inparams);

            // Email ingestion rows are deliberately NOT scrubbed by ticket
            // (PRIV-01). This statement used to blank the sender columns on
            // every row of every ticket this person raised, which reached an
            // agent's address, display name and resolved id — and the id a
            // third party's token was issued to — on somebody else's erasure
            // request. Their own rows are handled at the end of this method, by
            // relation; the rows below belong to whoever sent them.

            // Redact custom field values on those tickets — the free-text value
            // may hold PII; the row stays so the field structure remains intact.
            $DB->set_field_select('local_moodesk_cfield_value', 'value', $anon, "ticketid $insql", $inparams);

            // Outbound traffic and automation logs about this person's tickets.
            //
            // R2 found these tables undeclared and undeleted by a full context
            // wipe; the same rows survive a single erasure, and redacting the
            // ticket above does not reach them. A webhook payload is a snapshot
            // taken when the event fired, so it goes on quoting the subject as
            // it was written and naming the requester by id long after both are
            // gone from the ticket itself.
            //
            // The queue is deleted, not redacted: a queued row is a delivery
            // about to leave the site, and an erased person's subject must not
            // be sent to an operator's endpoint after they have asked for it to
            // go. The log is kept and emptied instead — whether an endpoint
            // accepted a delivery is operational history in the same sense an
            // incident is, and it stays true once the copy of the ticket is out
            // of it.
            $DB->delete_records_select('local_moodesk_webhook_queue', "ticketid $insql", $inparams);
            $DB->set_field_select('local_moodesk_webhook_log', 'payload', $anon, "ticketid $insql", $inparams);
            $DB->set_field_select('local_moodesk_webhook_log', 'response_body', null, "ticketid $insql", $inparams);

            // The note on a rule log is free text an automation wrote about
            // what it did to this ticket. The row records that a rule fired, which is worth
            // keeping; the sentence describing whose ticket it was is not.
            $DB->set_field_select('local_moodesk_rule_log', 'note', null, "ticketid $insql", $inparams);
        }

        // Team memberships: GDPR right-to-erasure — drop the membership row
        // entirely (no audit value once the user is gone).
        $DB->delete_records('local_moodesk_team_member', ['userid' => $userid]);

        // Merge audit: same treatment as ticket_history in the base — the row survives, the
        // identity does not. Deleting it would leave a ticket closed and marked
        // merged with no record of who decided that or why, which is the one
        // thing an audit trail exists to prevent.
        //
        // source_snapshot is deliberately untouched. It is not a record of the
        // agent, it is the payload revert reads to restore the ticket, and
        // scrubbing it here would silently break the undo for every merge this
        // person ever made. It is cleared by retention instead, once the revert
        // window closes for everyone alike — see purge_expired_merge_snapshots().
        // Collect this user's rows before zeroing the column: afterwards they
        // are indistinguishable from everyone else already anonymised, and the
        // reason belongs to whoever performed the merge — not to a later
        // reverter, whose own row keeps its author's words.
        $ownmerges = $DB->get_fieldset_select('local_moodesk_merge_audit', 'id', 'actorid = ?', [$userid]);

        $DB->set_field('local_moodesk_merge_audit', 'actorid', 0, ['actorid' => $userid]);
        $DB->set_field('local_moodesk_merge_audit', 'reverted_actorid', 0, ['reverted_actorid' => $userid]);

        if (!empty($ownmerges)) {
            [$insql, $inparams] = $DB->get_in_or_equal($ownmerges);
            $DB->set_field_select('local_moodesk_merge_audit', 'reason', $anon, "id $insql", $inparams);
        }

        // Revoke and remove REST API tokens owned by the user: drop the native
        // {external_tokens} rows (true revocation) and the companion metadata.
        $externaltokenids = $DB->get_fieldset_select('local_moodesk_api_token', 'externaltokenid', 'userid = ?', [$userid]);
        if (!empty($externaltokenids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($externaltokenids);
            $DB->delete_records_select('external_tokens', "id $insql", $inparams);
        }
        $DB->delete_records('local_moodesk_api_token', ['userid' => $userid]);

        // The usermodified column, table by table: see USERMODIFIED_TABLES.
        foreach (array_keys(self::USERMODIFIED_TABLES) as $table) {
            $DB->set_field($table, 'usermodified', 0, ['usermodified' => $userid]);
        }

        // Their answers to "was this article helpful?" go with them (KB-07,
        // D3). An opinion is not the organisation's content, and a vote with
        // no voter cannot be replaced, which is the one thing the unique index
        // on (articleid, userid) exists to allow. The aggregate loses the vote;
        // that is the correct reading of "delete my data".
        $DB->delete_records('local_moodesk_kb_feedback', ['userid' => $userid]);

        // Inbound mail, by relation rather than by ticket (PRIV-01). Two
        // passes, independent of each other and of who owns the thread.
        //
        // What this person SENT is theirs wherever it landed — including on
        // somebody else's ticket, which is exactly where an agent answering by
        // email leaves their address, and where nothing reached it before. The
        // subject line goes with it: they wrote it.
        //
        // What was sent under a token issued to THEM gives up that id and
        // nothing else. The address on such a row may be another person's, and
        // on a sender mismatch it certainly is — blanking it here would erase a
        // stranger because their message quoted this user's token.
        //
        // Everything that keeps the row working is untouched: `message_id` so
        // the message stays refused on the next poll, the threading headers,
        // `role`, `reject_reason`, `sender_auth` and `timecreated`.
        if (!empty($sentemailids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($sentemailids);
            $DB->set_field_select('local_moodesk_email_message', 'sender_email', $anon, "id $insql", $inparams);
            $DB->set_field_select('local_moodesk_email_message', 'sender_name', null, "id $insql", $inparams);
            $DB->set_field_select('local_moodesk_email_message', 'raw_subject', $anon, "id $insql", $inparams);
            $DB->set_field_select('local_moodesk_email_message', 'resolved_userid', null, "id $insql", $inparams);
        }
        $DB->set_field('local_moodesk_email_message', 'token_userid', null, ['token_userid' => $userid]);

        // User ids frozen inside JSON snapshots. The blanket pass above reaches
        // the traffic about this person's own tickets; this one reaches the rest
        // — a ticket somebody else raised that this person was assigned to, or
        // a merge they reverted. Scoped by ticket, those rows are invisible: a
        // payload keeps the assignee it was written with even after the ticket
        // has been reassigned to a third person, so the ticket no longer leads
        // back to the name inside it.
        snapshot_redactor::redact_column('local_moodesk_webhook_queue', 'payload', $userid, self::WEBHOOK_USERID_PATHS);
        // The delivery log also drops what the endpoint said back: it is whatever
        // the receiver wrote, it commonly echoes the request, and it has no key
        // to walk. The outcome lives in `http_status` and `success`.
        snapshot_redactor::redact_column(
            'local_moodesk_webhook_log',
            'payload',
            $userid,
            self::WEBHOOK_USERID_PATHS,
            'response_body'
        );
    }
}
