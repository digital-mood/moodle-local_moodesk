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
 * Persistence for operational incidents.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\incident;

/**
 * All DB access for local_moodesk_incident.
 *
 * The interesting method is {@see record_occurrence()}: everything else is
 * ordinary reads and status writes. The database handle is injectable so the
 * service's "a failing repository must not break the caller" guarantee can be
 * tested against a handle that throws.
 */
class incident_repository {
    /** @var \moodle_database */
    private \moodle_database $db;

    /**
     * Build the repository, falling back to the global database handle.
     *
     * @param \moodle_database|null $db
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /**
     * How many times the upsert may go round before giving up.
     *
     * Three, because each pass eliminates one of the two ways the row can move
     * under us and both are one-shot: a lost insert race means the row now
     * exists (so the next pass bumps it), and a row deleted mid-upsert means it
     * does not (so the next pass inserts it). A third pass covers the two
     * landing back to back. Anything beyond that is a table being rewritten
     * faster than a counter can be maintained, and looping forever over it
     * would turn a lost occurrence into a hung request.
     */
    private const UPSERT_ATTEMPTS = 3;

    /**
     * Record one occurrence of a class of failure.
     *
     * Upserts on the unique fingerprint: a first occurrence inserts, every
     * later one bumps the counter and the last-seen stamp. Reappearance on a
     * resolved row reopens that same row rather than minting a second one —
     * "I closed this and it came back" is the same incident, and splitting it
     * would lose the original timefirstseen.
     *
     * Both halves of the upsert race, in opposite directions, which is why
     * this is a bounded loop rather than a lookup followed by a write.
     *
     * The insert races *upward*. Two requests can both miss the SELECT and both
     * try to INSERT; one wins and the other violates the unique index. That
     * loser is not an error — it is proof the row now exists — so the next pass
     * finds it and bumps it. Catching the write exception is cheaper and more
     * honest here than locking the table for a counter.
     *
     * The update races *downward*, against retention. `purge_incidents` can
     * delete the row between the lookup and the UPDATE, and Moodle's
     * `update_record()` issues `UPDATE ... WHERE id = ?` without ever looking at
     * how many rows it matched — a write against a row that no longer exists
     * succeeds silently. Returning the id at that point would report an
     * occurrence that was never stored, in the one subsystem whose entire
     * purpose is that failures stop disappearing quietly. So {@see bump()}
     * confirms the row survived, and a vanished row sends this round again to
     * insert a fresh one.
     *
     * @param \stdClass $incident Fully-formed row: fingerprint, source, code,
     *                            severity, message, context, and the time to
     *                            stamp as `now`.
     * @return int The row id, always one that exists when this returns.
     */
    public function record_occurrence(\stdClass $incident): int {
        for ($attempt = 1; $attempt <= self::UPSERT_ATTEMPTS; $attempt++) {
            $existing = $this->get_by_fingerprint($incident->fingerprint);

            if ($existing) {
                $id = $this->bump($existing, $incident);
                if ($id !== null) {
                    return $id;
                }
                // Deleted between the lookup and the update. Go round: with the
                // row gone, the next pass inserts rather than updates.
                continue;
            }

            $row                = new \stdClass();
            $row->fingerprint   = $incident->fingerprint;
            $row->source        = $incident->source;
            $row->code          = $incident->code;
            $row->severity      = $incident->severity;
            $row->message       = $incident->message;
            $row->context       = $incident->context;
            $row->occurrences   = 1;
            $row->status        = incident::STATUS_OPEN;
            $row->timefirstseen = $incident->now;
            $row->timelastseen  = $incident->now;

            try {
                return (int) $this->db->insert_record('local_moodesk_incident', $row);
            } catch (\dml_write_exception $e) {
                // Lost the insert race: the winner's row is what we wanted, so
                // go round and bump it. If nothing is there, the write failed
                // for a different reason and retrying would fail identically —
                // the caller deserves the original exception.
                if (!$this->get_by_fingerprint($incident->fingerprint)) {
                    throw $e;
                }
            }
        }

        throw new \moodle_exception(
            'incident_upsert_exhausted',
            'local_moodesk',
            '',
            self::UPSERT_ATTEMPTS
        );
    }

    /**
     * Apply one more occurrence to a row that already exists.
     *
     * Severity is raised but never lowered while the incident is alive: a
     * source that failed critically once and warns since is still the thing
     * that failed critically, and an operator should not have to reconstruct
     * that from the counter. The message is refreshed so the newest detail is
     * the one on display.
     *
     * Returns null rather than an id when the row is gone by the time the
     * UPDATE lands. See {@see record_occurrence()} for why that is not a
     * theoretical case and why the caller has to be told about it.
     *
     * Protected, not private, so a test can subclass this repository and open
     * the delete window deliberately — the race is otherwise unreachable from
     * a test, and an unreachable race is one nobody can prove was fixed.
     *
     * @param \stdClass $existing Row as stored.
     * @param \stdClass $incoming The occurrence being recorded.
     * @return int|null The row id, or null when the row vanished mid-update.
     */
    protected function bump(\stdClass $existing, \stdClass $incoming): ?int {
        $update              = new \stdClass();
        $update->id          = $existing->id;
        $update->occurrences = (int) $existing->occurrences + 1;
        $update->timelastseen = $incoming->now;
        $update->message     = $incoming->message;
        $update->context     = $incoming->context;

        if (incident::severity_rank($incoming->severity) > incident::severity_rank($existing->severity)) {
            $update->severity = $incoming->severity;
        }

        // A failure that came back is open again, whatever an administrator
        // concluded last time. Acknowledged is left alone: it means "I am on
        // it", and it keeps meaning that while the failure continues.
        if ($existing->status === incident::STATUS_RESOLVED) {
            $update->status       = incident::STATUS_OPEN;
            $update->resolvedby   = null;
            $update->timeresolved = null;
        }

        $this->db->update_record('local_moodesk_incident', $update);

        // Moodle cannot tell us whether that UPDATE matched anything, so ask.
        // Without this the method reports success for a row retention deleted a
        // moment ago, and the occurrence is lost with no trace anywhere.
        if (!$this->db->record_exists('local_moodesk_incident', ['id' => $existing->id])) {
            return null;
        }

        return (int) $existing->id;
    }

    /**
     * Whether a database transaction is currently open on this connection.
     *
     * The service needs this because Moodle's DML layer has one connection and
     * one transaction stack: anything written while a transaction is open is
     * inside it, and goes with it if it rolls back.
     *
     * @return bool
     */
    public function in_transaction(): bool {
        return $this->db->is_transaction_started();
    }

    /**
     * Fetch one incident by its fingerprint.
     *
     * @param string $fingerprint
     * @return \stdClass|null
     */
    public function get_by_fingerprint(string $fingerprint): ?\stdClass {
        $record = $this->db->get_record('local_moodesk_incident', ['fingerprint' => $fingerprint]);
        return $record ?: null;
    }

    /**
     * Fetch one incident by id.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public function get_by_id(int $id): ?\stdClass {
        $record = $this->db->get_record('local_moodesk_incident', ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Incidents that count as current problems: seen since $since and not
     * resolved. Ordered most severe first, then most recent.
     *
     * Resolved rows are excluded even when still inside the window — an
     * administrator saying "handled" is exactly the signal that it should stop
     * showing up as a live problem. If it happens again the row reopens.
     *
     * @param int         $since  Unix timestamp; the start of the active window.
     * @param string|null $source Restrict to one source, or null for all.
     * @return \stdClass[] Rows keyed by id.
     */
    public function get_active(int $since, ?string $source = null): array {
        $params     = ['since' => $since, 'resolved' => incident::STATUS_RESOLVED];
        $sourcewhere = '';

        if ($source !== null) {
            $sourcewhere      = ' AND source = :source';
            $params['source'] = $source;
        }

        $sql = "SELECT *
                  FROM {local_moodesk_incident}
                 WHERE timelastseen >= :since
                       AND status <> :resolved
                       $sourcewhere
              ORDER BY timelastseen DESC, id DESC";

        return $this->db->get_records_sql($sql, $params);
    }

    /**
     * Count active incidents per source, for the health summary.
     *
     * @param int $since Unix timestamp; the start of the active window.
     * @return array<string, int> source => count, only sources with rows, ordered by source.
     */
    public function count_active_by_source(int $since): array {
        // GROUP BY alone leaves the order to the engine: MariaDB and PostgreSQL
        // happen to sort the groups, MySQL 8 does not.
        $sql = "SELECT source, COUNT(id) AS total
                  FROM {local_moodesk_incident}
                 WHERE timelastseen >= :since
                       AND status <> :resolved
              GROUP BY source
              ORDER BY source";

        $rows   = $this->db->get_records_sql($sql, [
            'since'    => $since,
            'resolved' => incident::STATUS_RESOLVED,
        ]);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->source] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Record that an incident was alerted about, at the severity it then held.
     *
     * The severity is stored alongside the timestamp because the cooldown is
     * not the whole rule: an incident that escalates past the severity it was
     * alerted at gets to alert again immediately.
     *
     * @param int    $id       Incident id.
     * @param string $severity Severity alerted at.
     * @param int    $now      Unix timestamp to stamp.
     */
    public function mark_alerted(int $id, string $severity, int $now): void {
        $update                  = new \stdClass();
        $update->id              = $id;
        $update->timelastalerted = $now;
        $update->severityalerted = $severity;

        $this->db->update_record('local_moodesk_incident', $update);
    }

    /**
     * Set an incident's administrative status.
     *
     * @param int      $id       Incident id.
     * @param string   $status   One of the incident::STATUS_* constants.
     * @param int|null $userid   Actor, or null to leave the actor columns alone.
     * @param int      $now      Unix timestamp to stamp.
     * @return bool False when the incident does not exist.
     */
    public function set_status(int $id, string $status, ?int $userid, int $now): bool {
        if (!$this->db->record_exists('local_moodesk_incident', ['id' => $id])) {
            return false;
        }

        $update         = new \stdClass();
        $update->id     = $id;
        $update->status = $status;

        if ($status === incident::STATUS_ACKNOWLEDGED) {
            $update->acknowledgedby   = $userid;
            $update->timeacknowledged = $now;
        } else if ($status === incident::STATUS_RESOLVED) {
            $update->resolvedby   = $userid;
            $update->timeresolved = $now;
        }

        $this->db->update_record('local_moodesk_incident', $update);

        return true;
    }

    /**
     * Delete every incident whose last occurrence predates the cutoff.
     *
     * Deliberately unfiltered by status. `open` is a statement that nobody
     * closed the row, not that the failure is still happening — the health
     * page derives "still happening" from `timelastseen`, and so does this.
     * Sparing `open` rows would mean the one status an operator never has to
     * touch is the one that pins data in the table forever.
     *
     * Counting first costs a second query and buys an honest return value:
     * `delete_records_select` reports nothing useful, and the task's log line
     * is the only evidence an administrator gets that retention ran at all.
     *
     * @param int $cutoff Unix timestamp; rows last seen strictly before it go.
     * @return int How many rows were deleted.
     */
    public function purge_older_than(int $cutoff): int {
        $select = 'timelastseen < :cutoff';
        $params = ['cutoff' => $cutoff];

        $affected = $this->db->count_records_select('local_moodesk_incident', $select, $params);

        if ($affected === 0) {
            return 0;
        }

        $this->db->delete_records_select('local_moodesk_incident', $select, $params);

        return $affected;
    }
}
