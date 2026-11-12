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
 * Ticket repository: all database access for the ticket table.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\ticket;

/**
 * Data access layer for local_moodesk_ticket.
 *
 * CONTRACT:
 *  - This class is the only place in the codebase allowed to query or write
 *    the local_moodesk_ticket table.
 *  - No capability checks. No events. No business logic.
 *  - Methods return raw stdClass objects. The returned objects include
 *    categoryname and departmentname from LEFT JOINs so callers avoid N+1.
 *
 * FILTER ARRAY CONTRACT:
 *  Methods accepting $filters support the following keys:
 *   'status'       => int|int[]   Filter by one or more status constants.
 *   'priority'     => int|int[]   Filter by one or more priority constants.
 *   'categoryid'   => int         Filter by a single category.
 *   'departmentid' => int|null    Filter by department; 0 = no department (NULL in DB).
 *   'assigneeid'   => int|null    int = specific agent; 0 = unassigned (null in DB).
 *  Unknown keys are silently ignored.
 *
 * DEPARTMENT AND TEAM VIEWS:
 *  The Unassigned, Department and Team queries narrow the list to what an agent
 *  chose to look at. They are not an access boundary and must never be read as
 *  one: who may reach a ticket at all is decided in exactly one place,
 *  {@see \local_moodesk\policy\ticket_access_policy}, and local/moodesk:viewall
 *  is global there by product decision.
 */
class ticket_repository {
    /** @var \moodle_database */
    private \moodle_database $db;

    /** Name of the local_moodesk_ticket table. */
    private const TABLE             = 'local_moodesk_ticket';
    /** Name of the local_moodesk_category table. */
    private const TABLE_CATEGORY    = 'local_moodesk_category';
    /** Name of the local_moodesk_department table. */
    private const TABLE_DEPT        = 'local_moodesk_department';
    /** Name of the local_moodesk_dept_member table. */
    private const TABLE_MEMBER      = 'local_moodesk_dept_member';
    /**
     * Build the repository, falling back to the global database handle.
     *
     * @param \moodle_database|null $db
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /*
     * -------------------------------------------------------------------------
     * Base SELECT fragment (shared by all list queries)
     * -------------------------------------------------------------------------
     */

    /**
     * Common SELECT + FROM + JOIN used by all list queries.
     * Always yields: t.*, cat.name AS categoryname, dept.name AS departmentname.
     *
     * @return string
     */
    private function base_select(): string {
        return "SELECT t.*, cat.name AS categoryname, dept.name AS departmentname
                  FROM {" . self::TABLE . "} t
             LEFT JOIN {" . self::TABLE_CATEGORY . "} cat  ON cat.id  = t.categoryid
             LEFT JOIN {" . self::TABLE_DEPT . "} dept     ON dept.id = t.departmentid";
    }

    /*
     * -------------------------------------------------------------------------
     * Read operations — single record
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch a single ticket by ID, including categoryname and departmentname.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public function get_by_id(int $id): ?\stdClass {
        $sql = $this->base_select() . " WHERE t.id = :id";
        $record = $this->db->get_record_sql($sql, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Return true if a ticket with this ID exists.
     *
     * @param int $id
     * @return bool
     */
    public function exists(int $id): bool {
        return $this->db->record_exists(self::TABLE, ['id' => $id]);
    }

    /*
     * -------------------------------------------------------------------------
     * Read operations — requester view (own tickets only)
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch all tickets belonging to a requester.
     *
     * @param int   $userid
     * @param array $filters
     * @param int   $limitfrom
     * @param int   $limitnum
     * @return \stdClass[]
     */
    public function get_for_requester(
        int $userid,
        array $filters = [],
        int $limitfrom = 0,
        int $limitnum = 50
    ): array {
        [$where, $params] = $this->build_filters_sql($filters);
        $params['uid'] = $userid;

        $sql = $this->base_select()
             . " WHERE t.userid = :uid AND {$where} ORDER BY t.timecreated DESC";

        return array_values($this->db->get_records_sql($sql, $params, $limitfrom, $limitnum));
    }

    /**
     * Count tickets for a requester.
     *
     * @param int   $userid
     * @param array $filters
     * @return int
     */
    public function count_for_requester(int $userid, array $filters = []): int {
        [$where, $params] = $this->build_filters_sql($filters);
        $params['uid'] = $userid;

        $sql = "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t
                 WHERE t.userid = :uid AND {$where}";

        return (int) $this->db->count_records_sql($sql, $params);
    }

    /*
     * -------------------------------------------------------------------------
     * Read operations — full agent/manager view (no scope restriction)
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch all tickets with no user-scope restriction.
     * Used by managers and as the fallback when no department setup exists.
     *
     * @param array $filters
     * @param int   $limitfrom
     * @param int   $limitnum
     * @return \stdClass[]
     */
    public function get_all(
        array $filters = [],
        int $limitfrom = 0,
        int $limitnum = 50
    ): array {
        [$where, $params] = $this->build_filters_sql($filters);

        $sql = $this->base_select()
             . " WHERE {$where} ORDER BY t.timecreated DESC";

        return array_values($this->db->get_records_sql($sql, $params, $limitfrom, $limitnum));
    }

    /**
     * Count all tickets with no user-scope restriction.
     *
     * @param array $filters
     * @return int
     */
    public function count_all(array $filters = []): int {
        [$where, $params] = $this->build_filters_sql($filters);

        $sql = "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t WHERE {$where}";

        return (int) $this->db->count_records_sql($sql, $params);
    }

    /*
     * -------------------------------------------------------------------------
     * Read operations — the Unassigned, Department and Team views.
     *
     * These narrow what an agent asked to look at. They are not an access
     * boundary: who may reach a ticket is decided once, by
     * {@see \local_moodesk\policy\ticket_access_policy}.
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch unassigned tickets accessible to an agent.
     *
     * "Accessible unassigned" = assigneeid IS NULL AND ((a) no dept OR (c) agent is member).
     *
     * @param int   $agentid
     * @param array $filters
     * @param int   $limitfrom
     * @param int   $limitnum
     * @return \stdClass[]
     */
    public function get_unassigned_accessible(
        int $agentid,
        array $filters = [],
        int $limitfrom = 0,
        int $limitnum = 50
    ): array {
        [$filterwhere, $filterparams] = $this->build_filters_sql($filters);

        // Unassigned + (no dept or agent is member of ticket's dept).
        $sql = $this->base_select()
             . " WHERE t.assigneeid IS NULL
                   AND (t.departmentid IS NULL
                        OR EXISTS (
                            SELECT 1 FROM {" . self::TABLE_MEMBER . "} dm2
                             WHERE dm2.departmentid = t.departmentid
                               AND dm2.userid = :unagtid
                        ))
                   AND {$filterwhere}
              ORDER BY t.timecreated DESC";

        $params = array_merge($filterparams, ['unagtid' => $agentid]);

        return array_values($this->db->get_records_sql($sql, $params, $limitfrom, $limitnum));
    }

    /**
     * Count unassigned accessible tickets for an agent.
     *
     * @param int   $agentid
     * @param array $filters
     * @return int
     */
    public function count_unassigned_accessible(int $agentid, array $filters = []): int {
        [$filterwhere, $filterparams] = $this->build_filters_sql($filters);

        $sql = "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t
                 WHERE t.assigneeid IS NULL
                   AND (t.departmentid IS NULL
                        OR EXISTS (
                            SELECT 1 FROM {" . self::TABLE_MEMBER . "} dm2
                             WHERE dm2.departmentid = t.departmentid
                               AND dm2.userid = :unagtid
                        ))
                   AND {$filterwhere}";

        $params = array_merge($filterparams, ['unagtid' => $agentid]);

        return (int) $this->db->count_records_sql($sql, $params);
    }

    /**
     * Fetch tickets in departments the agent belongs to (dept view).
     *
     * Only returns tickets that have an explicit department AND the agent is a
     * member of that department. Global (null-dept) tickets are excluded here —
     * they appear in the "All" view instead.
     *
     * @param int   $agentid
     * @param array $filters
     * @param int   $limitfrom
     * @param int   $limitnum
     * @return \stdClass[]
     */
    public function get_department_tickets_for_agent(
        int $agentid,
        array $filters = [],
        int $limitfrom = 0,
        int $limitnum = 50
    ): array {
        [$filterwhere, $filterparams] = $this->build_filters_sql($filters);

        $sql = $this->base_select()
             . " WHERE t.departmentid IS NOT NULL
                   AND EXISTS (
                       SELECT 1 FROM {" . self::TABLE_MEMBER . "} dm3
                        WHERE dm3.departmentid = t.departmentid
                          AND dm3.userid = :deptagtid
                   )
                   AND {$filterwhere}
              ORDER BY t.timecreated DESC";

        $params = array_merge($filterparams, ['deptagtid' => $agentid]);

        return array_values($this->db->get_records_sql($sql, $params, $limitfrom, $limitnum));
    }

    /**
     * Count department tickets for an agent.
     *
     * @param int   $agentid
     * @param array $filters
     * @return int
     */
    public function count_department_tickets_for_agent(int $agentid, array $filters = []): int {
        [$filterwhere, $filterparams] = $this->build_filters_sql($filters);

        $sql = "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t
                 WHERE t.departmentid IS NOT NULL
                   AND EXISTS (
                       SELECT 1 FROM {" . self::TABLE_MEMBER . "} dm3
                        WHERE dm3.departmentid = t.departmentid
                          AND dm3.userid = :deptagtid
                   )
                   AND {$filterwhere}";

        $params = array_merge($filterparams, ['deptagtid' => $agentid]);

        return (int) $this->db->count_records_sql($sql, $params);
    }

    /*
     * -------------------------------------------------------------------------
     * Read operations — scheduled task support
     * -------------------------------------------------------------------------
     */

    /**
     * Return one page of active tickets for evaluation by the SLA task.
     *
     * Both flagged and unflagged tickets come back: the task now clears the flag
     * as well as sets it, because a ticket that entered a pause can drop out of
     * breach. Terminal tickets are excluded — their flag is a historical record.
     *
     * Paginated by id rather than offset, and ordered by id rather than
     * timecreated, so the caller can walk the whole set: with a fixed LIMIT over
     * an ordered scan, the oldest tickets would monopolise every run and the
     * tail would never be evaluated at all.
     *
     * Selects exactly what sla_service::get_status() reads — departmentid for
     * the per-department threshold overrides, and both pause columns —
     * so the task reaches the same verdict as the live list.
     *
     * @param int $limit    Maximum records to return (page size).
     * @param int $afterid  Return tickets with a greater id; 0 starts from the top.
     * @return \stdClass[]
     */
    public function get_open_for_sla_check(int $limit = 500, int $afterid = 0): array {
        [$insql, $params] = $this->db->get_in_or_equal(
            \local_moodesk\domain\ticket::active_statuses(),
            SQL_PARAMS_NAMED,
            'slaact'
        );

        $sql = "SELECT id, priority, status, departmentid, assigneeid, timecreated,
                       timefirstresponse, timeresolved, sla_breached,
                       sla_paused_seconds, sla_paused_since
                  FROM {" . self::TABLE . "}
                 WHERE status {$insql}
                   AND id > :afterid
              ORDER BY id ASC";

        return array_values(
            $this->db->get_records_sql($sql, $params + ['afterid' => $afterid], 0, $limit)
        );
    }

    /**
     * Return tickets in STATUS_RESOLVED whose timeresolved is older than $threshold.
     *
     * Used by the auto-close scheduled task. Returns only the minimal set of
     * fields needed for the close operation (id, status, timeresolved).
     *
     * @param int $threshold  Unix timestamp — tickets resolved before this time are returned.
     * @param int $limit      Maximum number of records to return (batch size).
     * @return \stdClass[]
     */
    public function get_resolved_before(int $threshold, int $limit = 200): array {
        $sql = "SELECT id, status, timeresolved
                  FROM {" . self::TABLE . "}
                 WHERE status = :resolved
                   AND timeresolved IS NOT NULL
                   AND timeresolved < :threshold
              ORDER BY timeresolved ASC";

        return array_values(
            $this->db->get_records_sql($sql, [
                'resolved'  => \local_moodesk\domain\ticket::STATUS_RESOLVED,
                'threshold' => $threshold,
            ], 0, $limit)
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Read operations — agent workload
     * -------------------------------------------------------------------------
     */

    /**
     * Return the number of open tickets assigned to each agent.
     *
     * "Open" = any non-terminal status, i.e. NOT IN (STATUS_RESOLVED,
     * STATUS_CLOSED). Only assignees with at least one open ticket appear in the
     * result; callers should default missing agents to 0. A single grouped query
     * (no per-agent round trip) so it scales with the agent roster.
     *
     * @param int[] $userids Restrict the count to these assignees. Empty = all.
     * @return array<int, int> Map of userid => open ticket count.
     */
    public function count_open_by_assignee(array $userids = []): array {
        $params   = [
            'resolved' => \local_moodesk\domain\ticket::STATUS_RESOLVED,
            'closed'   => \local_moodesk\domain\ticket::STATUS_CLOSED,
        ];
        $useridsql = '';

        if (!empty($userids)) {
            [$insql, $inparams] = $this->db->get_in_or_equal(
                array_map('intval', $userids),
                SQL_PARAMS_NAMED,
                'uid'
            );
            $useridsql = " AND assigneeid {$insql}";
            $params    = array_merge($params, $inparams);
        }

        $sql = "SELECT assigneeid, COUNT(id) AS cnt
                  FROM {" . self::TABLE . "}
                 WHERE assigneeid IS NOT NULL
                   AND status NOT IN (:resolved, :closed)
                       {$useridsql}
              GROUP BY assigneeid";

        $counts = [];
        foreach ($this->db->get_records_sql($sql, $params) as $row) {
            $counts[(int) $row->assigneeid] = (int) $row->cnt;
        }

        return $counts;
    }

    /**
     * Return the ids of open tickets currently assigned to an agent.
     *
     * "Open" = non-terminal status, i.e. NOT IN (STATUS_RESOLVED, STATUS_CLOSED).
     * Used by the offboard requeue (A3) to unassign an agent's live tickets before
     * their role is revoked.
     *
     * @param int $userid
     * @return int[] Open ticket ids assigned to the user.
     */
    public function get_open_by_assignee(int $userid): array {
        $sql = "SELECT id
                  FROM {" . self::TABLE . "}
                 WHERE assigneeid = :userid
                   AND status NOT IN (:resolved, :closed)
              ORDER BY id ASC";

        $ids = $this->db->get_fieldset_sql($sql, [
            'userid'   => $userid,
            'resolved' => \local_moodesk\domain\ticket::STATUS_RESOLVED,
            'closed'   => \local_moodesk\domain\ticket::STATUS_CLOSED,
        ]);

        return array_map('intval', $ids);
    }

    /*
     * -------------------------------------------------------------------------
     * Write operations
     * -------------------------------------------------------------------------
     */

    /**
     * Insert a new ticket record. Returns the new ID.
     *
     * @param \stdClass $data
     * @return int
     */
    public function insert(\stdClass $data): int {
        return (int) $this->db->insert_record(self::TABLE, $data);
    }

    /**
     * Update an existing ticket record. $data must include 'id'.
     *
     * @param \stdClass $data
     * @return bool
     */
    public function update(\stdClass $data): bool {
        return $this->db->update_record(self::TABLE, $data);
    }

    /*
     * -------------------------------------------------------------------------
     * Ticket merge (Enterprise)
     * -------------------------------------------------------------------------
     */

    /**
     * Take a write lock on the given tickets for the rest of the transaction.
     *
     * Deliberately does NOT reuse base_select(): PostgreSQL refuses FOR UPDATE
     * on the nullable side of an outer join, and base_select() left-joins
     * category and department. This reads the ticket table alone.
     *
     * Rows are locked in id order so that two merges running in opposite
     * directions — A into B while B into A — queue up instead of deadlocking.
     *
     * Must be called inside a transaction; outside one the lock is released
     * immediately and buys nothing.
     *
     * @param int[] $ticketids
     * @return \stdClass[] Locked rows keyed by id.
     */
    public function lock_for_update(array $ticketids): array {
        if (empty($ticketids)) {
            return [];
        }

        [$insql, $params] = $this->db->get_in_or_equal($ticketids, SQL_PARAMS_NAMED, 'lock');

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE id $insql
              ORDER BY id";

        return $this->db->get_records_sql($sql . ' FOR UPDATE', $params);
    }

    /*
     * -------------------------------------------------------------------------
     * Private helpers
     * -------------------------------------------------------------------------
     */

    /**
     * Build the WHERE clause fragment and parameters array from a filter map.
     *
     * Returns a WHERE fragment that is always safe to AND into a query.
     * When the filter array is empty, returns '1 = 1' so callers can
     * unconditionally append it without special-casing.
     *
     * Supported keys:
     *   'status'       → (int|int[])  ticket status value(s)
     *   'priority'     → (int|int[])  ticket priority value(s)
     *   'categoryid'   → (int|null)   category ID
     *   'departmentid' → (int|null)   department ID; 0 = NULL in DB (no dept)
     *   'assigneeid'   → (int|null)   agent user ID; 0 = NULL in DB (unassigned)
     *   'sla_breach'   → (bool)       true = breached tickets only
     *   'search'       → (string)     LIKE search on subject
     *   'created_from' → (int)        t.timecreated  >= value (Unix timestamp)
     *   'created_to'   → (int)        t.timecreated  <= value (Unix timestamp)
     *   'updated_from' → (int)        t.timemodified >= value (Unix timestamp)
     *   'updated_to'   → (int)        t.timemodified <= value (Unix timestamp)
     *   'plugin'       → (array)      plugin filter values, offered to the ticket_list_filters hook
     *
     * @param array $filters Associative filter map (see keys above).
     * @return array{0: string, 1: array} [$wheresql, $params]
     */
    private function build_filters_sql(array $filters): array {
        $conditions = [];
        $params     = [];

        // Status filter.
        if (array_key_exists('status', $filters)) {
            $statuses = (array) $filters['status'];
            if (!empty($statuses)) {
                [$insql, $inparams] = $this->db->get_in_or_equal(
                    $statuses,
                    SQL_PARAMS_NAMED,
                    'fstatus'
                );
                $conditions[] = "t.status {$insql}";
                $params       = array_merge($params, $inparams);
            }
        }

        // Priority filter.
        if (array_key_exists('priority', $filters)) {
            $priorities = (array) $filters['priority'];
            if (!empty($priorities)) {
                [$insql, $inparams] = $this->db->get_in_or_equal(
                    $priorities,
                    SQL_PARAMS_NAMED,
                    'fpri'
                );
                $conditions[] = "t.priority {$insql}";
                $params       = array_merge($params, $inparams);
            }
        }

        // Category filter.
        if (array_key_exists('categoryid', $filters) && $filters['categoryid'] !== null) {
            $conditions[]          = 't.categoryid = :fcategoryid';
            $params['fcategoryid'] = (int) $filters['categoryid'];
        }

        // Department filter. 0 = no department (NULL in DB).
        if (array_key_exists('departmentid', $filters)) {
            if ($filters['departmentid'] === 0) {
                $conditions[] = 't.departmentid IS NULL';
            } else if ($filters['departmentid'] !== null) {
                $conditions[]           = 't.departmentid = :fdepartmentid';
                $params['fdepartmentid'] = (int) $filters['departmentid'];
            }
        }

        // Assignee filter. 0 = unassigned (NULL in DB).
        if (array_key_exists('assigneeid', $filters)) {
            if ($filters['assigneeid'] === 0) {
                $conditions[] = 't.assigneeid IS NULL';
            } else if ($filters['assigneeid'] !== null) {
                $conditions[]          = 't.assigneeid = :fassigneeid';
                $params['fassigneeid'] = (int) $filters['assigneeid'];
            }
        }

        // SLA breach filter. 1 = breached tickets only.
        if (!empty($filters['sla_breach'])) {
            $conditions[] = 't.sla_breached = 1';
        }

        // Full-text search on subject (case-insensitive LIKE).
        if (!empty($filters['search'])) {
            $conditions[]    = $this->db->sql_like('t.subject', ':fsearch', false, false);
            $params['fsearch'] = '%' . $this->db->sql_like_escape(trim($filters['search'])) . '%';
        }

        // Date range filters — Unix timestamps.
        if (!empty($filters['created_from'])) {
            $conditions[]             = 't.timecreated >= :fcreated_from';
            $params['fcreated_from']  = (int) $filters['created_from'];
        }

        if (!empty($filters['created_to'])) {
            $conditions[]           = 't.timecreated <= :fcreated_to';
            $params['fcreated_to']  = (int) $filters['created_to'];
        }

        if (!empty($filters['updated_from'])) {
            $conditions[]             = 't.timemodified >= :fupdated_from';
            $params['fupdated_from']  = (int) $filters['updated_from'];
        }

        if (!empty($filters['updated_to'])) {
            $conditions[]           = 't.timemodified <= :fupdated_to';
            $params['fupdated_to']  = (int) $filters['updated_to'];
        }

        // Plugin filters (hook ticket_list_filters, sql phase), only when the
        // request carries one: without them the query is the one it always was.
        if (!empty($filters['plugin']) && is_array($filters['plugin'])) {
            $hook = \local_moodesk\hook\ticket_list_filters::for_sql($filters['plugin'], 'tl');
            \core\di::get(\core\hook\manager::class)->dispatch($hook);
            [$pluginsql, $pluginparams] = $hook->get_sql();
            if ($pluginsql !== '') {
                $conditions[] = $pluginsql;
                $params      += $pluginparams;
            }
        }

        $where = empty($conditions) ? '1 = 1' : implode(' AND ', $conditions);

        return [$where, $params];
    }
}
