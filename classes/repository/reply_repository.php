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
 * Reply repository: all database access for the reply table.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\reply;

/**
 * Data access layer for local_moodesk_reply.
 *
 * CONTRACT:
 *  - This class is the only place in the codebase allowed to query or write
 *    the local_moodesk_reply table directly. No service, renderer, or page touches
 *    $DB for reply data.
 *  - No capability checks. No events. No business logic.
 *    All of that belongs in reply_service.
 *  - Methods return raw stdClass objects or arrays of stdClass as returned
 *    by Moodle's DML layer.
 */
class reply_repository {
    /** @var \moodle_database */
    private \moodle_database $db;

    /** @var string */
    private const TABLE = 'local_moodesk_reply';

    /**
     * Build the repository, falling back to the global database handle.
     *
     * @param \moodle_database|null $db Injected for testing; falls back to global $DB.
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /*
     * -------------------------------------------------------------------------
     * Read operations
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch the thread for a ticket, filtered by allowed reply types.
     *
     * Pass null for $types to fetch all reply types (agent view).
     * Pass reply::requester_visible_types() to restrict to what a requester
     * is allowed to see.
     *
     * Results are always ordered chronologically ascending (oldest first).
     *
     * @param int        $ticketid
     * @param int[]|null $types    Type constants to include; null = all types.
     * @return \stdClass[]
     */
    public function get_thread(int $ticketid, ?array $types = null): array {
        return $this->get_thread_for_tickets([$ticketid], $types);
    }

    /**
     * Fetch one interleaved thread spanning several tickets.
     *
     * This is what makes a merge readable: the target's thread is the union of
     * its own replies and those of every source merged into it, in one
     * chronological sequence rather than two lists stapled together. Rows are
     * never copied between tickets, so each reply keeps the ticketid it was
     * written against and the caller can label where it came from.
     *
     * Ordered by timecreated then id: replies written inside the same second —
     * a merge writes two — would otherwise come back in whatever order the
     * database felt like.
     *
     * @param int[]      $ticketids Tickets whose replies make up the thread.
     * @param int[]|null $types     Type constants to include; null = all types.
     * @return \stdClass[]
     */
    public function get_thread_for_tickets(array $ticketids, ?array $types = null): array {
        $ticketids = array_values(array_unique(array_map('intval', $ticketids)));

        if (empty($ticketids) || $types === []) {
            return [];
        }

        [$ticketsql, $params] = $this->db->get_in_or_equal($ticketids, SQL_PARAMS_NAMED, 'rtid');
        $typesql              = '';

        if ($types !== null) {
            [$insql, $typeparams] = $this->db->get_in_or_equal($types, SQL_PARAMS_NAMED, 'rtype');
            $typesql              = " AND type {$insql}";
            $params               = array_merge($params, $typeparams);
        }

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE ticketid {$ticketsql}{$typesql}
              ORDER BY timecreated ASC, id ASC";

        return array_values($this->db->get_records_sql($sql, $params));
    }

    /**
     * Check whether an agent has already posted at least one public reply on
     * a ticket. Used by reply_service to determine whether timefirstresponse
     * should be set on the ticket.
     *
     * A "first agent response" is any PUBLIC_REPLY where the author's userid
     * differs from the ticket owner. The caller (reply_service) must supply the
     * requester userid so this repository method stays free of ticket lookups.
     *
     * @param int $ticketid
     * @param int $requesterid  The ticket's userid field (not the acting agent).
     * @return bool
     */
    public function has_agent_public_reply(int $ticketid, int $requesterid): bool {
        $sql = "SELECT id
                  FROM {" . self::TABLE . "}
                 WHERE ticketid  = :ticketid
                   AND type      = :type
                   AND userid   != :requesterid";

        return (bool) $this->db->get_field_sql($sql, [
            'ticketid'    => $ticketid,
            'type'        => reply::TYPE_PUBLIC_REPLY,
            'requesterid' => $requesterid,
        ]);
    }

    /*
     * -------------------------------------------------------------------------
     * Write operations
     * -------------------------------------------------------------------------
     */

    /**
     * Count the replies on a ticket, grouped by type.
     *
     * Counting in SQL rather than through get_thread(): the merge audit only
     * needs the numbers, and a source ticket can carry a thousand replies whose
     * bodies have no business being loaded into memory to be tallied.
     *
     * @param int $ticketid
     * @return int[] Reply type => count. Types with no rows are absent.
     */
    public function count_by_type(int $ticketid): array {
        $sql = "SELECT type, COUNT(1) AS total
                  FROM {" . self::TABLE . "}
                 WHERE ticketid = :ticketid
              GROUP BY type";

        $counts = [];
        foreach ($this->db->get_records_sql($sql, ['ticketid' => $ticketid]) as $row) {
            $counts[(int) $row->type] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * Count files attached to a ticket's replies.
     *
     * Joins {files} against this ticket's replies so the total costs one query
     * instead of one file-storage lookup per reply. Moodle stores two rows per
     * file area including the directory record, hence the filename filter.
     *
     * @param int $ticketid
     * @param \context $context Context the files live in.
     * @return int
     */
    public function count_reply_attachments(int $ticketid, \context $context): int {
        $sql = "SELECT COUNT(1)
                  FROM {files} f
                  JOIN {" . self::TABLE . "} r ON r.id = f.itemid
                 WHERE r.ticketid   = :ticketid
                   AND f.contextid  = :contextid
                   AND f.component  = 'local_moodesk'
                   AND f.filearea   = 'reply_attachment'
                   AND f.filename  <> '.'";

        return (int) $this->db->count_records_sql($sql, [
            'ticketid'  => $ticketid,
            'contextid' => $context->id,
        ]);
    }

    /**
     * Insert a new reply record and return the new ID.
     *
     * The caller is responsible for populating all required fields.
     * This method writes what it receives and returns the new ID.
     *
     * @param \stdClass $data
     * @return int The new reply ID.
     */
    public function insert(\stdClass $data): int {
        return (int) $this->db->insert_record(self::TABLE, $data);
    }
}
