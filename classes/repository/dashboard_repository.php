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
 * Dashboard repository: read-only aggregate queries for the dashboard page.
 *
 * CONTRACT:
 *  - SELECT-only. This class never writes to the database.
 *  - No capability checks. No business logic. No events.
 *  - All queries operate on local_moodesk_ticket as the primary table, aliased
 *    to `t` without exception, so the scope fragments below always apply.
 *  - "Active" tickets = status NOT IN (STATUS_RESOLVED=4, STATUS_CLOSED=5).
 *    STATUS_INPROGRESS=6 is intentionally included as active.
 *  - "Resolved" always means `timeresolved IS NOT NULL AND status IN (4, 5)`.
 *    A merged-away ticket is closed but never stamped with timeresolved, so it
 *    correctly stays out of every resolution metric — a merge is not a fix.
 *  - Queries avoid DB-specific date functions (no FROM_UNIXTIME, no DATE_TRUNC)
 *    to stay compatible with both MySQL/MariaDB and PostgreSQL.
 *
 * SNAPSHOT VS RANGE: methods marked "snapshot" describe the present and apply
 * only the filter's scope criteria, ignoring its date range — see
 * {@see \local_moodesk\domain\dashboard_filter} for why. Methods marked "range"
 * apply both.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\dashboard_filter;
use local_moodesk\domain\ticket;

/**
 * Read-only aggregate queries for the Help Desk dashboard.
 */
class dashboard_repository {
    /** @var \moodle_database */
    protected \moodle_database $db;

    /** Name of the local_moodesk_ticket table. */
    protected const TABLE         = 'local_moodesk_ticket';
    /** Name of the local_moodesk_department table. */
    protected const TABLE_DEPT    = 'local_moodesk_department';
    /** Name of the local_moodesk_dept_member table. */
    protected const TABLE_MEMBER  = 'local_moodesk_dept_member';
    /** Name of the local_moodesk_category table. */
    protected const TABLE_CAT     = 'local_moodesk_category';

    /** Seconds in one day — used for day-bucket arithmetic. */
    protected const DAY_SECONDS = 86400;

    /**
     * Upper bounds, in days, of the ticket-aging buckets.
     *
     * Each entry closes a bucket; one final open-ended bucket is appended for
     * everything older. [1, 3, 7, 14] therefore yields: under 24h, 1-3 days,
     * 3-7 days, 7-14 days, over 14 days.
     */
    public const AGING_BUCKET_DAYS = [1, 3, 7, 14];

    /** Status values considered "active" (not resolved, not closed). */
    protected const ACTIVE_STATUSES = [
        ticket::STATUS_NEW,
        ticket::STATUS_OPEN,
        ticket::STATUS_PENDING,
        ticket::STATUS_ON_HOLD,
        ticket::STATUS_INPROGRESS,
    ];

    /**
     * Constructor.
     *
     * @param \moodle_database|null $db
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /*
     * =========================================================================
     * Query building
     * =========================================================================
     */

    /**
     * Build the "active tickets" clause plus the filter's scope criteria.
     *
     * @param dashboard_filter|null $filter Null applies no scope at all.
     * @param string                $prefix Unique per-query parameter prefix.
     * @return array{0: string, 1: array} SQL fragment (already ANDed) and params.
     */
    protected function active_scope(?dashboard_filter $filter, string $prefix): array {
        [$insql, $params] = $this->db->get_in_or_equal(
            self::ACTIVE_STATUSES,
            SQL_PARAMS_NAMED,
            $prefix . 'st'
        );

        [$scopesql, $scopeparams] = $this->scope_sql($filter, $prefix);

        return ["t.status {$insql}{$scopesql}", $params + $scopeparams];
    }

    /**
     * Build the department / team / agent criteria shared by every metric.
     *
     * Returns a fragment that always starts with " AND " (or is empty), so it
     * can be appended to any WHERE clause that already has one condition.
     *
     * @param dashboard_filter|null $filter Null applies no scope at all.
     * @param string                $prefix Unique per-query parameter prefix.
     * @return array{0: string, 1: array} SQL fragment and params.
     */
    protected function scope_sql(?dashboard_filter $filter, string $prefix): array {
        if ($filter === null) {
            return ['', []];
        }

        $sql    = '';
        $params = [];

        $departmentid = $filter->get_departmentid();
        if ($departmentid !== dashboard_filter::ANY) {
            if ($departmentid === dashboard_filter::NONE) {
                $sql .= ' AND t.departmentid IS NULL';
            } else {
                $sql .= " AND t.departmentid = :{$prefix}dept";
                $params[$prefix . 'dept'] = $departmentid;
            }
        }

        $agentid = $filter->get_agentid();
        if ($agentid !== dashboard_filter::ANY) {
            if ($agentid === dashboard_filter::NONE) {
                $sql .= ' AND t.assigneeid IS NULL';
            } else {
                $sql .= " AND t.assigneeid = :{$prefix}agent";
                $params[$prefix . 'agent'] = $agentid;
            }
        }

        $requesterid = $filter->get_requesterid();
        if ($requesterid !== dashboard_filter::ANY) {
            // The userid column is the requester — whose problem this is — not
            // createdby, which records who typed it in when an agent raises a
            // ticket on someone else's behalf.
            $sql .= " AND t.userid = :{$prefix}req";
            $params[$prefix . 'req'] = $requesterid;
        }

        // Plugin criteria (hook dashboard_filters, sql phase), only when the
        // filter carries one; their parameters are prefixed like ours.
        $criteria = $filter->get_plugin_criteria();
        if (!empty($criteria)) {
            $hook = \local_moodesk\hook\dashboard_filters::for_sql($criteria, $prefix);
            \core\di::get(\core\hook\manager::class)->dispatch($hook);
            [$pluginsql, $pluginparams] = $hook->get_sql();
            if ($pluginsql !== '') {
                $sql    .= " AND {$pluginsql}";
                $params += $pluginparams;
            }
        }

        return [$sql, $params];
    }

    /**
     * Build a closed date-range condition on the given column.
     *
     * @param dashboard_filter $filter Supplies the window.
     * @param string           $column Fully qualified column (e.g. 't.timecreated').
     * @param string           $prefix Unique per-query parameter prefix.
     * @return array{0: string, 1: array} SQL fragment (already ANDed) and params.
     */
    protected function range_sql(dashboard_filter $filter, string $column, string $prefix): array {
        return [
            " AND {$column} >= :{$prefix}from AND {$column} <= :{$prefix}to",
            [$prefix . 'from' => $filter->get_from(), $prefix . 'to' => $filter->get_to()],
        ];
    }

    /**
     * Run a grouped query whose first column is not unique per row.
     *
     * get_records_sql() keys its result by the first column and silently drops
     * collisions, which has already cost this plugin two breakdown bugs. Any
     * grouping on more than one column goes through here instead.
     *
     * @param string $sql
     * @param array  $params
     * @param int    $limitnum Maximum rows returned (0 = no limit).
     * @return array List of stdClass rows, in query order.
     */
    protected function get_rows(string $sql, array $params, int $limitnum = 0): array {
        $rows = [];
        $rs = $this->db->get_recordset_sql($sql, $params, 0, $limitnum);
        foreach ($rs as $row) {
            $rows[] = $row;
        }
        $rs->close();

        return $rows;
    }

    /*
     * =========================================================================
     * Headline counters (snapshot — the date range does not apply)
     * =========================================================================
     */

    /**
     * Count active tickets (not resolved, not closed). Snapshot.
     *
     * @param dashboard_filter|null $filter
     * @return int
     */
    public function count_active(?dashboard_filter $filter = null): int {
        [$where, $params] = $this->active_scope($filter, 'ca');

        return (int) $this->db->count_records_sql(
            "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t WHERE {$where}",
            $params
        );
    }

    /**
     * Count active tickets with no assignee. Snapshot.
     *
     * Under an agent criterion this is zero by definition; the service layer is
     * responsible for labelling that rather than showing a bare zero.
     *
     * @param dashboard_filter|null $filter
     * @return int
     */
    public function count_unassigned_active(?dashboard_filter $filter = null): int {
        [$where, $params] = $this->active_scope($filter, 'un');

        return (int) $this->db->count_records_sql(
            "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t
              WHERE {$where} AND t.assigneeid IS NULL",
            $params
        );
    }

    /**
     * Count active tickets carrying the stored SLA breach flag. Snapshot.
     *
     * KNOWN LIMITATION: sla_breached is set by the hourly check_sla_breaches
     * task and only ever evaluated on tickets that are still open, so a ticket
     * that breached and was resolved between two runs is never flagged. This
     * under-reports breaches and over-reports compliance. Documented in
     * docs/dashboard-redesign.md; not corrected in this phase.
     *
     * @param dashboard_filter|null $filter
     * @return int
     */
    public function count_sla_breached_active(?dashboard_filter $filter = null): int {
        [$where, $params] = $this->active_scope($filter, 'sb');

        return (int) $this->db->count_records_sql(
            "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t
              WHERE {$where} AND t.sla_breached = 1",
            $params
        );
    }

    /**
     * Count tickets resolved inside the filter's window. Range.
     *
     * Callers wanting a fixed window regardless of the user's selection (the
     * "resolved this week" KPI, or its previous-week comparison) pass a filter
     * derived with dashboard_filter::with_range().
     *
     * @param dashboard_filter $filter
     * @return int
     */
    public function count_resolved(dashboard_filter $filter): int {
        [$scopesql, $params] = $this->scope_sql($filter, 'cr');
        [$rangesql, $rangeparams] = $this->range_sql($filter, 't.timeresolved', 'cr');
        $params += $rangeparams;
        $params['crres'] = ticket::STATUS_RESOLVED;
        $params['crclo'] = ticket::STATUS_CLOSED;

        return (int) $this->db->count_records_sql(
            "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t
              WHERE t.timeresolved IS NOT NULL
                AND t.status IN (:crres, :crclo){$rangesql}{$scopesql}",
            $params
        );
    }

    /*
     * =========================================================================
     * Breakdowns (snapshot — the date range does not apply)
     * =========================================================================
     */

    /**
     * Return active ticket counts grouped by status. Snapshot.
     *
     * @param dashboard_filter|null $filter
     * @return array Array of stdClass: status (int), cnt (int).
     */
    public function count_active_by_status(?dashboard_filter $filter = null): array {
        [$where, $params] = $this->active_scope($filter, 'bs');

        $sql = "SELECT t.status, COUNT(t.id) AS cnt
                  FROM {" . self::TABLE . "} t
                 WHERE {$where}
              GROUP BY t.status
              ORDER BY t.status ASC";

        return array_values($this->db->get_records_sql($sql, $params));
    }

    /**
     * Return active ticket counts grouped by priority. Snapshot.
     *
     * @param dashboard_filter|null $filter
     * @return array Array of stdClass: priority (int), cnt (int).
     */
    public function count_active_by_priority(?dashboard_filter $filter = null): array {
        [$where, $params] = $this->active_scope($filter, 'bp');

        $sql = "SELECT t.priority, COUNT(t.id) AS cnt
                  FROM {" . self::TABLE . "} t
                 WHERE {$where}
              GROUP BY t.priority
              ORDER BY t.priority DESC";

        return array_values($this->db->get_records_sql($sql, $params));
    }

    /**
     * Return active ticket counts grouped by department AND status. Snapshot.
     *
     * This is what the stacked department bars are built from; a caller wanting
     * plain per-department totals sums the segments. Tickets with no department
     * come back with departmentid = null and an empty deptname.
     *
     * Grouping is on (departmentid, status) because department names are not
     * unique — the schema puts no unique constraint on
     * local_moodesk_department.name — so two departments sharing a name must
     * stay two rows.
     *
     * @param dashboard_filter|null $filter
     * @return array Array of stdClass: departmentid (int|null), deptname (string),
     *               status (int), cnt (int).
     */
    public function count_active_by_department_status(?dashboard_filter $filter = null): array {
        [$where, $params] = $this->active_scope($filter, 'bd');

        $sql = "SELECT t.departmentid, COALESCE(d.name, '') AS deptname, t.status, COUNT(t.id) AS cnt
                  FROM {" . self::TABLE . "} t
             LEFT JOIN {" . self::TABLE_DEPT . "} d ON d.id = t.departmentid
                 WHERE {$where}
              GROUP BY t.departmentid, COALESCE(d.name, ''), t.status
              ORDER BY t.departmentid ASC, t.status ASC";

        return $this->get_rows($sql, $params);
    }

    /**
     * Return active ticket counts bucketed by age. Snapshot.
     *
     * Buckets come from self::AGING_BUCKET_DAYS, with one extra open-ended
     * bucket appended for anything older than the last bound. Every bucket is
     * always present, including empty ones, so the chart keeps a stable shape
     * as tickets move between bands.
     *
     * @param dashboard_filter|null $filter
     * @param int|null              $now Evaluation point (null = time()).
     * @return array Array of stdClass: bucket (int, 0-based), upper_days (int|null,
     *               null on the final open-ended bucket), cnt (int).
     */
    public function count_active_by_age(?dashboard_filter $filter = null, ?int $now = null): array {
        $now = $now ?? time();
        [$where, $params] = $this->active_scope($filter, 'ag');

        // One SUM(CASE ...) column per bucket, so the whole distribution costs a
        // single pass over the active tickets instead of one query per band.
        //
        // Every SUM is wrapped in COALESCE because SUM over zero rows is NULL,
        // and get_record_sql() keys its result by the first column — a NULL
        // there is a null array offset, which PHP 8.4 deprecates. Zero is also
        // the honest answer: an empty band holds no tickets, it is not unknown.
        $columns  = [];
        $previous = 0;
        foreach (self::AGING_BUCKET_DAYS as $index => $days) {
            $upper = $days * self::DAY_SECONDS;
            $lower = $previous * self::DAY_SECONDS;
            $columns[] = "COALESCE(SUM(CASE WHEN (:agnow{$index}a - t.timecreated) >= {$lower}
                                             AND (:agnow{$index}b - t.timecreated) < {$upper}
                                            THEN 1 ELSE 0 END), 0) AS bucket{$index}";
            $params["agnow{$index}a"] = $now;
            $params["agnow{$index}b"] = $now;
            $previous = $days;
        }
        $lastindex = count(self::AGING_BUCKET_DAYS);
        $lastlower = $previous * self::DAY_SECONDS;
        $columns[] = "COALESCE(SUM(CASE WHEN (:agnow{$lastindex}a - t.timecreated) >= {$lastlower}
                                       THEN 1 ELSE 0 END), 0) AS bucket{$lastindex}";
        $params["agnow{$lastindex}a"] = $now;

        $sql = 'SELECT ' . implode(",\n                       ", $columns) .
               "\n                  FROM {" . self::TABLE . "} t
                 WHERE {$where}";

        $row = $this->db->get_record_sql($sql, $params);

        $out = [];
        foreach (self::AGING_BUCKET_DAYS as $index => $days) {
            $out[] = (object) [
                'bucket'     => $index,
                'upper_days' => $days,
                'cnt'        => (int) ($row->{"bucket{$index}"} ?? 0),
            ];
        }
        $out[] = (object) [
            'bucket'     => $lastindex,
            'upper_days' => null,
            'cnt'        => (int) ($row->{"bucket{$lastindex}"} ?? 0),
        ];

        return $out;
    }

    /*
     * =========================================================================
     * Trends (range)
     * =========================================================================
     */

    /**
     * Return ticket counts per day, bucketed on the given timestamp column.
     *
     * Days with no tickets are absent from the result — the service fills the
     * gaps, because a chart needs a point for every day but SQL should not
     * invent rows.
     *
     * The bucket size is interpolated as a literal rather than bound as a
     * parameter: PostgreSQL matches a GROUP BY expression against the SELECT
     * list textually, and two bound placeholders ($1 vs $3) make the otherwise
     * identical expressions look unrelated, failing with "column timecreated
     * must appear in the GROUP BY clause". The value is a class constant, never
     * user input, so there is no injection surface.
     *
     * @param dashboard_filter $filter
     * @param string           $column Either 'timecreated' or 'timeresolved'.
     * @return array Array of stdClass: day_bucket (int), cnt (int).
     */
    private function count_by_day(dashboard_filter $filter, string $column): array {
        $bucket = self::DAY_SECONDS;
        $prefix = ($column === 'timeresolved') ? 'dr' : 'dc';

        [$scopesql, $params] = $this->scope_sql($filter, $prefix);
        [$rangesql, $rangeparams] = $this->range_sql($filter, "t.{$column}", $prefix);
        $params += $rangeparams;

        // Resolution counts must not include tickets merged away or reopened —
        // both can carry a stale timestamp — so they carry the status guard too.
        $statussql = '';
        if ($column === 'timeresolved') {
            $statussql = " AND t.timeresolved IS NOT NULL AND t.status IN (:{$prefix}res, :{$prefix}clo)";
            $params[$prefix . 'res'] = ticket::STATUS_RESOLVED;
            $params[$prefix . 'clo'] = ticket::STATUS_CLOSED;
        }

        $sql = "SELECT FLOOR(t.{$column} / {$bucket}) * {$bucket} AS day_bucket,
                       COUNT(t.id) AS cnt
                  FROM {" . self::TABLE . "} t
                 WHERE 1 = 1{$rangesql}{$statussql}{$scopesql}
              GROUP BY FLOOR(t.{$column} / {$bucket}) * {$bucket}
              ORDER BY day_bucket ASC";

        return array_values($this->db->get_records_sql($sql, $params));
    }

    /**
     * Return tickets created per day inside the window. Range.
     *
     * @param dashboard_filter $filter
     * @return array Array of stdClass: day_bucket (int), cnt (int).
     */
    public function count_created_by_day(dashboard_filter $filter): array {
        return $this->count_by_day($filter, 'timecreated');
    }

    /**
     * Return tickets resolved per day inside the window. Range.
     *
     * @param dashboard_filter $filter
     * @return array Array of stdClass: day_bucket (int), cnt (int).
     */
    public function count_resolved_by_day(dashboard_filter $filter): array {
        return $this->count_by_day($filter, 'timeresolved');
    }

    /*
     * =========================================================================
     * Durations and SLA (range)
     * =========================================================================
     */

    /**
     * Return mean first-response time for tickets created in the window. Range.
     *
     * Tickets with no first response yet are excluded from the mean rather than
     * counted as zero, so `sample` is reported alongside it: an average over
     * three tickets is not the same claim as an average over three hundred.
     *
     * @param dashboard_filter $filter
     * @return \stdClass Properties: sample (int), avg_seconds (float|null).
     */
    public function get_first_response_stats(dashboard_filter $filter): \stdClass {
        [$scopesql, $params] = $this->scope_sql($filter, 'fr');
        [$rangesql, $rangeparams] = $this->range_sql($filter, 't.timecreated', 'fr');
        $params += $rangeparams;

        $sql = "SELECT COUNT(t.id) AS sample,
                       AVG(t.timefirstresponse - t.timecreated) AS avg_seconds
                  FROM {" . self::TABLE . "} t
                 WHERE t.timefirstresponse IS NOT NULL
                   AND t.timefirstresponse > t.timecreated{$rangesql}{$scopesql}";

        return $this->duration_stats($sql, $params);
    }

    /**
     * Return mean resolution time for tickets resolved in the window. Range.
     *
     * Keyed on timeresolved, not timecreated: the question this answers is "how
     * fast are we closing things out lately", and keying on creation date would
     * silently exclude every long-running ticket that finally landed this month.
     *
     * @param dashboard_filter $filter
     * @return \stdClass Properties: sample (int), avg_seconds (float|null).
     */
    public function get_resolution_stats(dashboard_filter $filter): \stdClass {
        [$scopesql, $params] = $this->scope_sql($filter, 'rt');
        [$rangesql, $rangeparams] = $this->range_sql($filter, 't.timeresolved', 'rt');
        $params += $rangeparams;
        $params['rtres'] = ticket::STATUS_RESOLVED;
        $params['rtclo'] = ticket::STATUS_CLOSED;

        $sql = "SELECT COUNT(t.id) AS sample,
                       AVG(t.timeresolved - t.timecreated) AS avg_seconds
                  FROM {" . self::TABLE . "} t
                 WHERE t.timeresolved IS NOT NULL
                   AND t.status IN (:rtres, :rtclo)
                   AND t.timeresolved > t.timecreated{$rangesql}{$scopesql}";

        return $this->duration_stats($sql, $params);
    }

    /**
     * Execute a duration query and normalise its result.
     *
     * @param string $sql
     * @param array  $params
     * @return \stdClass Properties: sample (int), avg_seconds (float|null).
     */
    private function duration_stats(string $sql, array $params): \stdClass {
        $row = $this->db->get_record_sql($sql, $params);

        $sample = (int) ($row->sample ?? 0);

        return (object) [
            'sample'      => $sample,
            // AVG over an empty set is NULL, and null is the honest answer:
            // "no data" must not render as an average of zero seconds.
            'avg_seconds' => ($sample > 0 && $row->avg_seconds !== null)
                ? (float) $row->avg_seconds
                : null,
        ];
    }

    /**
     * Return SLA compliance totals for tickets resolved in the window. Range.
     *
     * Subject to the same stored-flag limitation as
     * {@see self::count_sla_breached_active()}.
     *
     * @param dashboard_filter $filter
     * @return \stdClass Properties: total (int), within_sla (int), breached (int).
     */
    public function get_sla_compliance(dashboard_filter $filter): \stdClass {
        [$scopesql, $params] = $this->scope_sql($filter, 'sc');
        [$rangesql, $rangeparams] = $this->range_sql($filter, 't.timeresolved', 'sc');
        $params += $rangeparams;
        $params['scres'] = ticket::STATUS_RESOLVED;
        $params['scclo'] = ticket::STATUS_CLOSED;

        $sql = "SELECT COUNT(t.id)                                            AS total,
                       SUM(CASE WHEN t.sla_breached = 0 THEN 1 ELSE 0 END)    AS within_sla,
                       SUM(CASE WHEN t.sla_breached = 1 THEN 1 ELSE 0 END)    AS breached
                  FROM {" . self::TABLE . "} t
                 WHERE t.timeresolved IS NOT NULL
                   AND t.status IN (:scres, :scclo){$rangesql}{$scopesql}";

        $row = $this->db->get_record_sql($sql, $params);

        return (object) [
            'total'      => (int) ($row->total ?? 0),
            'within_sla' => (int) ($row->within_sla ?? 0),
            'breached'   => (int) ($row->breached ?? 0),
        ];
    }

    /*
     * =========================================================================
     * Agent workload
     * =========================================================================
     */

    /*
     * =========================================================================
     * Categories, satisfaction and activity
     * =========================================================================
     */

    /**
     * Return the most recently touched tickets in the window. Range.
     *
     * Ordered by timemodified, which every write path already maintains, rather
     * than replaying local_moodesk_ticket_history: the panel shows what state a
     * ticket is in now, and the history table is indexed per ticket, not
     * globally by time.
     *
     * @param dashboard_filter $filter
     * @param int              $limit Maximum tickets returned.
     * @return array Array of stdClass: id, subject, status, priority, timemodified.
     */
    public function get_recent_activity(dashboard_filter $filter, int $limit = 5): array {
        [$scopesql, $params] = $this->scope_sql($filter, 'ra');
        [$rangesql, $rangeparams] = $this->range_sql($filter, 't.timemodified', 'ra');
        $params += $rangeparams;

        $sql = "SELECT t.id, t.subject, t.status, t.priority, t.timemodified
                  FROM {" . self::TABLE . "} t
                 WHERE 1 = 1{$rangesql}{$scopesql}
              ORDER BY t.timemodified DESC, t.id DESC";

        return array_values($this->db->get_records_sql($sql, $params, 0, max(0, $limit)));
    }

    /*
     * =========================================================================
     * Agent-scoped metrics (the agent's own workspace, not the global panel)
     * =========================================================================
     */

    /**
     * Count unassigned active tickets in departments the agent belongs to.
     *
     * Not expressible through dashboard_filter: the scope is "every department
     * this user is a member of", which is a membership join rather than a
     * single department criterion.
     *
     * @param int $agentid
     * @return int
     */
    public function count_dept_unassigned(int $agentid): int {
        [$where, $params] = $this->active_scope(null, 'du');
        $params['duagt'] = $agentid;

        return (int) $this->db->count_records_sql(
            "SELECT COUNT(t.id) FROM {" . self::TABLE . "} t
              WHERE {$where}
                AND t.assigneeid IS NULL
                AND t.departmentid IS NOT NULL
                AND EXISTS (
                    SELECT 1 FROM {" . self::TABLE_MEMBER . "} dm
                     WHERE dm.departmentid = t.departmentid
                       AND dm.userid = :duagt
                )",
            $params
        );
    }
}
