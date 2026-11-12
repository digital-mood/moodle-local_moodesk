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
 * Ticket history repository: data access for the audit log table.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

/**
 * Data access layer for local_moodesk_ticket_history.
 *
 * CONTRACT:
 *  - This table is INSERT-only. There is no update() or delete() method.
 *    If you find yourself wanting to delete history records, that is a
 *    product design error — surface it for review, do not implement it here.
 *  - The Privacy API (classes/privacy/provider.php) is the only mechanism
 *    that may remove history records, via the approved GDPR erasure path.
 */
class ticket_history_repository {
    /** @var \moodle_database */
    private \moodle_database $db;

    /** @var string */
    private const TABLE = 'local_moodesk_ticket_history';

    /**
     * Build the repository, falling back to the global database handle.
     *
     * @param \moodle_database|null $db Injected for testing; falls back to global $DB.
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /**
     * Insert a new history entry.
     *
     * The caller must populate: ticketid, userid, action, timecreated.
     * oldvalue and newvalue are optional (null for creation events).
     *
     * This method intentionally does no validation — the caller (ticket_service)
     * is responsible for passing well-formed data using constants from
     * \local_moodesk\domain\ticket_history.
     *
     * @param \stdClass $data
     * @return int The new history entry ID.
     */
    public function insert(\stdClass $data): int {
        return (int) $this->db->insert_record(self::TABLE, $data);
    }

    /**
     * Fetch the full history for a ticket, ordered chronologically.
     *
     * @param int $ticketid
     * @return \stdClass[]
     */
    public function get_for_ticket(int $ticketid): array {
        return array_values(
            $this->db->get_records(
                self::TABLE,
                ['ticketid' => $ticketid],
                'timecreated ASC'
            )
        );
    }

    /**
     * Fetch the history of several tickets as one chronological sequence.
     *
     * A merged target's history is its own plus that of every source linked to
     * it (design doc §4.3): nothing is copied between tickets, so reading
     * across the link is the only way the consolidated story exists at all.
     * Each row keeps its own ticketid, which is what lets a caller show where
     * an entry came from.
     *
     * @param int[] $ticketids
     * @return \stdClass[] Oldest first; ties broken by id.
     */
    public function get_for_tickets(array $ticketids): array {
        $ticketids = array_values(array_unique(array_map('intval', $ticketids)));

        if (empty($ticketids)) {
            return [];
        }

        [$insql, $params] = $this->db->get_in_or_equal($ticketids, SQL_PARAMS_NAMED, 'htid');

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE ticketid {$insql}
              ORDER BY timecreated ASC, id ASC";

        return array_values($this->db->get_records_sql($sql, $params));
    }

    /**
     * Count history entries for a ticket.
     *
     * The merge audit records how many entries the target inherited; loading
     * them through get_for_ticket() just to count them would pull every
     * oldvalue/newvalue blob along with it.
     *
     * @param int $ticketid
     * @return int
     */
    public function count_for_ticket(int $ticketid): int {
        return $this->db->count_records(self::TABLE, ['ticketid' => $ticketid]);
    }
}
