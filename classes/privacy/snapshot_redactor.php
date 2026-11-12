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
 * Takes one user's id out of stored JSON snapshots.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\privacy;

/**
 * Shared by this plugin's provider, for the ticket history, and by any plugin
 * that keeps a snapshot of a ticket.
 */
final class snapshot_redactor {
    /**
     * What an erased user's id becomes inside a stored JSON snapshot.
     *
     * A snapshot freezes a user id into JSON at the moment an event happened: the
     * assignee on `ticket_history.oldvalue` / `newvalue`, on an assignment change.
     * Redacting the row's own columns never reaches it, because the id is inside
     * the text.
     *
     * **Why -1 and not the obvious answers.** The snapshot schema already gives
     * two values a meaning, and reusing either would turn a redaction into a
     * statement that something happened:
     *
     * - `null` **is** the schema's word for unassigned — `{"assigneeid": N|null}`,
     *   documented on \local_moodesk\domain\ticket_history. Writing it would
     *   rewrite "assigned to this person" into "unassigned", inventing an event
     *   that never occurred.
     * - `0` is the unassign sentinel the bulk form sends; ticket_service reads
     *   `$assigneeid > 0 ? $assigneeid : null` and collapses it to the same
     *   thing.
     * - **Dropping the key** is worse than either, because the idiomatic reader
     *   spells the lookup `$decoded['assigneeid'] ?? null` and lands back on
     *   unassigned without anything looking wrong.
     * - **A string marker** breaks the documented integer type, and `(int)` of
     *   any such marker is 0 — the unassign sentinel again.
     *
     * **-1 means "a user who was erased", and it is not `null` and not `0`.**
     * `null` says nobody was ever there, `0` says unassign, and -1 says somebody
     * was here and their name has been taken out. Those are three different
     * facts and the snapshot has to be able to tell them apart.
     *
     * -1 is the only value that collides with nothing the schema already means:
     * no Moodle user has a negative id, so it can never resolve to a person; it
     * is neither `null` nor `0`, so no reader testing for those can read a
     * redaction as an unassignment; and it stays an integer, so the stored shape
     * and every existing reader survive it. Being distinguishable is the point —
     * a reader can tell a redaction from an absence and say so.
     *
     * The one adjacency worth naming: a consumer that collapses any non-positive
     * value to "unassigned" would show such an event as unassigned rather than
     * as redacted. Nothing reads these snapshots that way today — the only
     * production reader of a history value is the SLA backfill in db/upgrade.php,
     * which reads the `status` key alone — and the collapse lives on the write
     * path from the bulk form, not on any read of a snapshot.
     */
    public const REDACTED_USER_ID = -1;

    /**
     * Redact one user out of every JSON value in one column.
     *
     * The rows are narrowed by a LIKE on the *key names*, not on the encoded
     * value: `json_encode` always writes a key as `"assigneeid":`, whereas the
     * spacing around the value it is compared against is a serialisation detail
     * this must not depend on. The match is only a filter — every candidate is
     * decoded and checked properly before anything is written, and a row whose
     * value turns out to belong to somebody else is left alone.
     *
     * Streamed rather than loaded: a delivery log grows by one row per event per
     * subscription, and an erasure request must not depend on it being small.
     *
     * `$clearonredact` names a second column emptied on any row this touches, for
     * text that quotes the same person and has no key to walk.
     *
     * @param string      $table          Table holding the column.
     * @param string      $column         The JSON column.
     * @param int         $userid         The user being erased.
     * @param string[]    $paths          Dot paths to the keys that hold a user id.
     * @param string|null $clearonredact  A column to null out on every redacted row.
     * @return void
     */
    public static function redact_column(
        string $table,
        string $column,
        int $userid,
        array $paths,
        ?string $clearonredact = null
    ): void {
        global $DB;

        $leaves = [];
        foreach ($paths as $path) {
            $keys     = explode('.', $path);
            $leaves[] = (string) end($keys);
        }

        $wheres = [];
        $params = [];
        foreach (array_values(array_unique($leaves)) as $index => $key) {
            $name          = 'key' . $index;
            $wheres[]      = $DB->sql_like($column, ':' . $name, false);
            $params[$name] = '%"' . $key . '"%';
        }

        $rs = $DB->get_recordset_select(
            $table,
            '(' . implode(' OR ', $wheres) . ')',
            $params,
            '',
            'id, ' . $column
        );

        foreach ($rs as $row) {
            $redacted = self::redact_json((string) $row->{$column}, $userid, $paths);
            if ($redacted === null) {
                continue;
            }

            $DB->set_field($table, $column, $redacted, ['id' => (int) $row->id]);

            if ($clearonredact !== null) {
                $DB->set_field($table, $clearonredact, null, ['id' => (int) $row->id]);
            }
        }

        $rs->close();
    }

    /**
     * One JSON snapshot with one user's id taken out of the named keys.
     *
     * @param string   $json   The stored JSON.
     * @param int      $userid The user being erased.
     * @param string[] $paths  Dot paths to the keys that hold a user id.
     * @return string|null The rewritten JSON, or null when there was nothing to change.
     */
    private static function redact_json(string $json, int $userid, array $paths): ?string {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            // Not a snapshot: either already blanked, or never JSON to begin
            // with. Leaving it is the only safe move — rewriting text nobody can parse would
            // destroy whatever it is without redacting anything.
            return null;
        }

        $changed = false;

        foreach ($paths as $path) {
            $cursor = &$decoded;
            $found  = true;

            foreach (explode('.', $path) as $key) {
                if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                    $found = false;
                    break;
                }
                $cursor = &$cursor[$key];
            }

            // Testing is_numeric keeps a null — the schema's word for
            // unassigned — and any nested structure out of the comparison, so
            // only a value that really is this user's id is replaced.
            if ($found && is_numeric($cursor) && (int) $cursor === $userid) {
                $cursor  = self::REDACTED_USER_ID;
                $changed = true;
            }

            unset($cursor);
        }

        return $changed ? json_encode($decoded) : null;
    }
}
