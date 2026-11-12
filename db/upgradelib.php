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
 * Upgrade steps that are worth testing on their own, called from db/upgrade.php.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * KB-06: make (groupid, lang) unique on local_moodesk_kb_article.
 *
 * Three moves, in this order, each idempotent:
 *
 *  1. Backfill `groupid = id` on any row still holding the column default 0.
 *     Nothing written through the repository or the generator has one — both
 *     set the group on insert — but the index below must not fail on a site
 *     where something else did.
 *  2. Refuse to continue if any (groupid, lang) pair is held by more than one
 *     row. It cannot happen from KB-01..05 code (every row was its own group)
 *     and an upgrade must not pick a loser: the exception names the rows so
 *     an administrator can decide, and the upgrade re-runs once they have.
 *  3. Add the unique index and drop the plain groupid index it supersedes
 *     (the unique one leads on groupid, so every lookup by group still has
 *     an index to walk).
 *
 * @param \moodle_database $db
 * @return void
 * @throws \moodle_exception When a duplicated (groupid, lang) pair exists.
 */
function local_moodesk_upgrade_kb_unique_group_lang(\moodle_database $db): void {
    $dbman = $db->get_manager();
    $table = new xmldb_table('local_moodesk_kb_article');

    // The table itself is created by the KB-01 block; a fresh install runs
    // install.xml and never reaches this function.
    if (!$dbman->table_exists($table)) {
        return;
    }

    $db->execute('UPDATE {local_moodesk_kb_article} SET groupid = id WHERE groupid = 0');

    $duplicates = $db->get_records_sql(
        'SELECT ' . $db->sql_concat('groupid', "'-'", 'lang') . ' AS pair, groupid, lang, COUNT(1) AS total
           FROM {local_moodesk_kb_article}
       GROUP BY groupid, lang
         HAVING COUNT(1) > 1
       ORDER BY groupid, lang'
    );
    if ($duplicates) {
        $details = [];
        foreach ($duplicates as $pair) {
            $ids = $db->get_fieldset_select(
                'local_moodesk_kb_article',
                'id',
                'groupid = :groupid AND lang = :lang',
                ['groupid' => $pair->groupid, 'lang' => $pair->lang],
                'id ASC'
            );
            $details[] = 'groupid ' . $pair->groupid . ' / lang ' . $pair->lang . ': article ids ' . implode(', ', $ids);
        }
        throw new moodle_exception('kb_upgrade_duplicate_translations', 'local_moodesk', '', implode('; ', $details));
    }

    $unique = new xmldb_index('ux_groupid_lang', XMLDB_INDEX_UNIQUE, ['groupid', 'lang']);
    if (!$dbman->index_exists($table, $unique)) {
        $dbman->add_index($table, $unique);
    }

    $plain = new xmldb_index('ix_groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid']);
    if ($dbman->index_exists($table, $plain)) {
        $dbman->drop_index($table, $plain);
    }
}

/**
 * F1 (2.30.0): move legacy `ticket_attachment` files to the ticket's opening reply.
 *
 * Between 1.4.0 and 2.14.2 the email ingestion stored the attachments of a
 * ticket raised by email in the `ticket_attachment` area (itemid = ticket id).
 * Nothing has ever rendered that area — the ticket page reads attachments per
 * reply — so those files were kept, served on their direct URL, exported and
 * erased by the privacy provider, and shown to nobody. 2.14.3 fixed the
 * write; this step fixes the data, moving each file to `reply_attachment`
 * on the reply that holds the opening email's body (decision D16).
 *
 * Which reply, per ticket, in this order and only this order:
 *
 *  1. **Primary anchor.** The `email_message` row the ingestion wrote when it
 *     created the ticket: `role = ROLE_TICKET_ORIGIN`, `ticketid` = the
 *     ticket, `replyid` set. That row names the reply the ingestion created
 *     for the email body — the exact reply 2.14.3 would have used. One
 *     distinct reply id → that is the destination. More than one, or a reply
 *     that is missing or belongs to another ticket → ambiguous.
 *  2. **Fallback**, only when no origin row carries a reply id: the ticket's
 *     earliest reply, and only when it is unmistakably the requester's
 *     opening message — a public reply by the ticket's requester, with no
 *     other reply sharing its timestamp. Anything else is ambiguous.
 *  3. **Ambiguous → untouched.** A ticket with no reply, an origin row that
 *     contradicts another, a destination that already holds a different file
 *     under the same name, a missing ticket: nothing is moved, nothing is
 *     chosen for it, nothing is deleted, and the upgrade goes on. The files
 *     stay where they were, still served and still covered by the privacy
 *     provider, and the counts tell the administrator how many.
 *
 * Idempotent. A file already present at the destination with the same
 * content is the trace of an interrupted earlier run: the source copy is
 * dropped and the file counted as already migrated. A re-run on a migrated
 * site finds no source files and does nothing.
 *
 * Content and metadata are preserved by construction: the destination row is
 * created from the stored file (same content hash, author, licence, mime
 * type, timestamps, sort order), and only then is the source row deleted,
 * inside one transaction per ticket. The file content itself is never
 * copied — Moodle's file store is content-addressed.
 *
 * @param \moodle_database $db
 * @return array{migrated:int, already:int, ambiguous:int, ambiguoustickets:int[]}
 *         Counts in files; the ids of the tickets left untouched.
 */
function local_moodesk_upgrade_legacy_ticket_attachments(\moodle_database $db): array {
    $fs        = get_file_storage();
    $contextid = \context_system::instance()->id;

    $result = [
        'migrated'         => 0,
        'already'          => 0,
        'ambiguous'        => 0,
        'ambiguoustickets' => [],
    ];

    $ticketids = $db->get_fieldset_sql(
        "SELECT DISTINCT itemid
           FROM {files}
          WHERE contextid = :contextid
            AND component = 'local_moodesk'
            AND filearea = 'ticket_attachment'
            AND filename <> '.'
       ORDER BY itemid",
        ['contextid' => $contextid]
    );

    foreach ($ticketids as $ticketid) {
        $ticketid = (int) $ticketid;
        $files    = $fs->get_area_files($contextid, 'local_moodesk', 'ticket_attachment', $ticketid, 'id', false);
        if (empty($files)) {
            continue;
        }

        $replyid = local_moodesk_upgrade_resolve_opening_reply($db, $ticketid);
        if ($replyid === null) {
            $result['ambiguous'] += count($files);
            $result['ambiguoustickets'][] = $ticketid;
            continue;
        }

        // Classify every file before touching any: a single collision leaves
        // the whole ticket alone rather than half-moved.
        $plan = [];
        foreach ($files as $file) {
            $existing = $fs->get_file(
                $contextid,
                'local_moodesk',
                'reply_attachment',
                $replyid,
                $file->get_filepath(),
                $file->get_filename()
            );
            if ($existing === false) {
                $plan[] = ['move', $file];
            } else if ($existing->get_contenthash() === $file->get_contenthash()) {
                $plan[] = ['already', $file];
            } else {
                $plan = null;
                break;
            }
        }
        if ($plan === null) {
            $result['ambiguous'] += count($files);
            $result['ambiguoustickets'][] = $ticketid;
            continue;
        }

        $transaction = $db->start_delegated_transaction();
        foreach ($plan as [$action, $file]) {
            if ($action === 'move') {
                $fs->create_file_from_storedfile(
                    ['filearea' => 'reply_attachment', 'itemid' => $replyid],
                    $file
                );
                $result['migrated']++;
            } else {
                $result['already']++;
            }
            $file->delete();
        }
        // The directory entry of an emptied area is housekeeping, not data.
        $fs->delete_area_files($contextid, 'local_moodesk', 'ticket_attachment', $ticketid);
        $transaction->allow_commit();
    }

    return $result;
}

/**
 * The reply that holds a legacy ticket's opening message, or null when it
 * cannot be determined without guessing. See
 * {@see local_moodesk_upgrade_legacy_ticket_attachments()} for the rules.
 *
 * @param \moodle_database $db
 * @param int              $ticketid
 * @return int|null
 */
function local_moodesk_upgrade_resolve_opening_reply(\moodle_database $db, int $ticketid): ?int {
    $ticket = $db->get_record('local_moodesk_ticket', ['id' => $ticketid], 'id, userid');
    if (!$ticket) {
        return null;
    }

    // 1. Primary anchor: the ingestion's own record of which reply it made.
    $anchored = $db->get_fieldset_select(
        'local_moodesk_email_message',
        'DISTINCT replyid',
        // Role 0 is the message that opened the ticket.
        'ticketid = :ticketid AND role = :role AND replyid IS NOT NULL',
        ['ticketid' => $ticketid, 'role' => 0]
    );
    if (count($anchored) > 1) {
        return null;
    }
    if (count($anchored) === 1) {
        $replyid = (int) reset($anchored);
        $reply   = $db->get_record('local_moodesk_reply', ['id' => $replyid], 'id, ticketid');
        if (!$reply || (int) $reply->ticketid !== $ticketid) {
            return null;
        }
        return $replyid;
    }

    // 2. Fallback: the earliest reply, when it is unmistakably the requester's
    // opening message. Two of the requester's public replies at the same
    // instant cannot be told apart.
    $replies = array_values($db->get_records(
        'local_moodesk_reply',
        ['ticketid' => $ticketid],
        'timecreated ASC, id ASC',
        'id, userid, type, timecreated',
        0,
        2
    ));
    if (empty($replies)) {
        return null;
    }
    $isopening = static fn(\stdClass $reply): bool =>
        (int) $reply->type === \local_moodesk\domain\reply::TYPE_PUBLIC_REPLY
        && (int) $reply->userid === (int) $ticket->userid;

    $first = $replies[0];
    if (!$isopening($first)) {
        return null;
    }
    if (
        isset($replies[1])
        && (int) $replies[1]->timecreated === (int) $first->timecreated
        && $isopening($replies[1])
    ) {
        return null;
    }

    return (int) $first->id;
}

/**
 * Copy a message provider's settings and preferences to another component.
 *
 * For a provider this plugin stops declaring and another plugin starts
 * declaring under the same name. Once this plugin's upgrade finishes, the core
 * (message_update_providers(), from upgrade_component_updated()) deletes the
 * provider together with the administrator's settings for it and every user's
 * preference, and it does so before the receiving plugin's own upgrade runs.
 * So the values are copied here, under the receiving component's names, and
 * the core is left to delete the old ones.
 *
 * What is copied, each only when the new name does not exist yet:
 *
 *  - From config_plugins (plugin 'message'): every processor's
 *    `<processor>_provider_local_moodesk_<name>_locked`,
 *    `message_provider_local_moodesk_<name>_enabled` and
 *    `local_moodesk_<name>_disable`, the switch that turns the whole provider
 *    off (message_send() sends nothing while it is set). The receiving
 *    plugin's provider sync then keeps them: message_set_default_message_preference()
 *    writes neither a `_locked` that exists nor the `_enabled` when every
 *    processor already has its `_locked`, and never writes `_disable`.
 *    The core does not delete the old `_disable` either: the upgrade step
 *    does.
 *  - From user_preferences: `message_provider_local_moodesk_<name>_enabled`,
 *    and the `_loggedin` / `_loggedoff` that a site older than Moodle 4.0 may
 *    still hold, matched by exact name (in LIKE, `_` is a wildcard).
 *
 * Idempotent: a second run finds every new name present and copies nothing.
 *
 * @param string $name        The provider name, the same in both components.
 * @param string $tocomponent The component that declares it from now on.
 * @return int How many values were copied.
 */
function local_moodesk_move_message_provider(string $name, string $tocomponent): int {
    global $DB;

    $from   = 'local_moodesk';
    $copied = 0;

    // Administrator settings.
    $confignames = [
        "message_provider_{$from}_{$name}_enabled" => "message_provider_{$tocomponent}_{$name}_enabled",
        "{$from}_{$name}_disable" => "{$tocomponent}_{$name}_disable",
    ];
    foreach ($DB->get_fieldset_select('message_processors', 'name', '1 = 1') as $processor) {
        $confignames["{$processor}_provider_{$from}_{$name}_locked"] = "{$processor}_provider_{$tocomponent}_{$name}_locked";
    }
    foreach ($confignames as $old => $new) {
        $value = get_config('message', $old);
        if ($value === false || get_config('message', $new) !== false) {
            continue;
        }
        set_config($new, $value, 'message');
        $copied++;
    }

    // Every user's preferences, one name at a time so only ids are held.
    foreach (['enabled', 'loggedin', 'loggedoff'] as $suffix) {
        $old = "message_provider_{$from}_{$name}_{$suffix}";
        $new = "message_provider_{$tocomponent}_{$name}_{$suffix}";

        $hasnew = array_flip($DB->get_fieldset_select('user_preferences', 'userid', 'name = ?', [$new]));

        $rows = $DB->get_recordset('user_preferences', ['name' => $old], 'id', 'id, userid, value');
        foreach ($rows as $row) {
            if (isset($hasnew[$row->userid])) {
                continue;
            }
            $DB->insert_record('user_preferences', (object) [
                'userid' => $row->userid,
                'name'   => $new,
                'value'  => $row->value,
            ]);
            $hasnew[$row->userid] = true;
            $copied++;
        }
        $rows->close();
    }

    return $copied;
}
