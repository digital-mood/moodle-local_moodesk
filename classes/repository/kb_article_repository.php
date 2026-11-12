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
 * Knowledge base article repository: all database access for
 * local_moodesk_kb_article.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\domain\kb_article;
use local_moodesk\policy\kb_access_policy;

/**
 * Data access layer for local_moodesk_kb_article.
 *
 * CONTRACT:
 *  - This is the only class allowed to query local_moodesk_kb_article.
 *  - No capability checks. No business logic. No events.
 *  - Returns raw stdClass objects or arrays as delivered by Moodle DML.
 *
 * Every read that returns more than one row takes a *scope* — one of the
 * kb_access_policy::SCOPE_* constants — and this class is the only translator
 * of a scope into SQL. It does not decide the scope; the policy does. That
 * split is what lets tests/policy/kb_access_policy_test.php hold the row rule
 * and the query rule to the same answer.
 *
 * On groups (KB-06): rows sharing a groupid are translations of one article,
 * one per language — the database holds that (ux_groupid_lang). A reading
 * surface asks for one row per group by passing a `resolve` filter: the
 * ordered language codes to prefer, the tail (canonical row, then lowest id)
 * being appended here. The chosen row is one the scope allows, because the
 * scope is applied to every candidate the choice is made among — the
 * policy test extends its agreement check to the row list() picks.
 */
class kb_article_repository {
    /** Name of the local_moodesk_kb_article table. */
    private const TABLE = 'local_moodesk_kb_article';

    /** Sort orders a caller may ask for, name => SQL. */
    private const SORTS = [
        'timemodified'  => 't.timemodified DESC, t.id DESC',
        'timepublished' => 't.timepublished DESC, t.id DESC',
        'title'         => 't.title ASC, t.id ASC',
    ];

    /** @var \moodle_database */
    private \moodle_database $db;

    /**
     * Build the repository, falling back to the global database handle.
     *
     * @param \moodle_database|null $db  Injected for testing; falls back to global $DB.
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /*
     * -------------------------------------------------------------------------
     * Read — single row
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch a single article by ID. Returns null if not found.
     *
     * Unscoped on purpose: one row is fetched and then handed to the policy,
     * which is the only way require_can_view() can tell a draft from a
     * missing row and answer both the same way.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public function get_by_id(int $id): ?\stdClass {
        $record = $this->db->get_record(self::TABLE, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Every row of a group, lowest id first, whatever its status.
     *
     * Unscoped like get_by_id(): the caller hands each row to the policy.
     *
     * @param int $groupid
     * @return \stdClass[] Keyed by id.
     */
    public function list_group(int $groupid): array {
        return $this->db->get_records(self::TABLE, ['groupid' => $groupid], 'id ASC');
    }

    /**
     * How many published rows each language has (KB-07 analytics, "by language").
     *
     * @param array $filters categoryid (int).
     * @return array<string, int> Language code => count.
     */
    public function count_published_by_lang(array $filters = []): array {
        $where  = ['status = :status'];
        $params = ['status' => kb_article::STATUS_PUBLISHED];
        if (!empty($filters['categoryid'])) {
            $where[] = 'categoryid = :categoryid';
            $params['categoryid'] = (int) $filters['categoryid'];
        }
        $sql = 'SELECT lang, COUNT(id) AS cnt
                  FROM {' . self::TABLE . '}
                 WHERE ' . implode(' AND ', $where) . '
              GROUP BY lang';

        $counts = [];
        foreach ($this->db->get_records_sql($sql, $params) as $row) {
            $counts[(string) $row->lang] = (int) $row->cnt;
        }
        return $counts;
    }

    /**
     * Published rows changed at or after a moment, oldest change first — the
     * shape Moodle's search indexer asks for (KB-07b).
     *
     * Published only: a draft or an archived row is never a document, so
     * "a draft does not appear" holds before check_access() is asked. A
     * recordset, not an array — the indexer walks it once and the table may
     * be large.
     *
     * @param int $modifiedfrom Unix timestamp; rows with timemodified >= it.
     * @return \moodle_recordset
     */
    public function published_modified_since(int $modifiedfrom): \moodle_recordset {
        return $this->db->get_recordset_select(
            self::TABLE,
            'status = :status AND timemodified >= :modifiedfrom',
            ['status' => kb_article::STATUS_PUBLISHED, 'modifiedfrom' => $modifiedfrom],
            'timemodified ASC, id ASC'
        );
    }

    /**
     * Whether a group already has a row in a language, another row than $excludeid.
     *
     * @param int    $groupid
     * @param string $lang
     * @param int    $excludeid A row to leave out — the one being edited.
     * @return bool
     */
    public function lang_exists_in_group(int $groupid, string $lang, int $excludeid = 0): bool {
        return $this->db->record_exists_select(
            self::TABLE,
            'groupid = :groupid AND lang = :lang AND id <> :exclude',
            ['groupid' => $groupid, 'lang' => $lang, 'exclude' => $excludeid]
        );
    }

    /**
     * Whether any row of a group is in a status other than the one given.
     *
     * @param int $groupid
     * @param int $status A kb_article::STATUS_* constant.
     * @return bool
     */
    public function group_has_status_other_than(int $groupid, int $status): bool {
        return $this->db->record_exists_select(
            self::TABLE,
            'groupid = :groupid AND status <> :status',
            ['groupid' => $groupid, 'status' => $status]
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Read — lists
     * -------------------------------------------------------------------------
     */

    /**
     * Articles within a scope, filtered and sorted.
     *
     * Recognised filters: status (int), categoryid (int; 0 means "no
     * category"), lang (string), resolve (string[]: the language codes to
     * prefer, in order — with it the result holds ONE row per group, see
     * below). A filter cannot widen a scope: status = draft inside
     * SCOPE_PUBLIC returns nothing, as it should.
     *
     * With `resolve`, the row kept for a group is the one ranking highest
     * among the rows of that group the same scope and filters allow: first
     * by position in the codes given, then the canonical row (id = groupid),
     * then the lowest id. Done as an anti-join — no window functions, no
     * LIMIT in a subquery — so the same SQL runs on MySQL, MariaDB and
     * PostgreSQL through DML, and the offset/limit paginate groups.
     *
     * @param int    $scope   A kb_access_policy::SCOPE_* constant.
     * @param array  $filters See above.
     * @param string $sort    One of the SORTS keys.
     * @param int    $limit   0 for no limit.
     * @param int    $offset
     * @return \stdClass[] Keyed by id.
     */
    public function list(int $scope, array $filters = [], string $sort = 'timemodified', int $limit = 0, int $offset = 0): array {
        [$where, $params] = $this->build_where($scope, $filters);
        $order = self::SORTS[$sort] ?? self::SORTS['timemodified'];

        $langs = $this->resolve_langs($filters);
        if ($langs !== null) {
            [$where2, $params2] = $this->build_where($scope, $filters, 't2');
            $params += $params2;
            $siblingbetter = $this->rank_sql('t2', $langs, 'ra', $params) . ' < ' . $this->rank_sql('t', $langs, 'rb', $params);
            $siblingtie    = $this->rank_sql('t2', $langs, 'rc', $params) . ' = ' . $this->rank_sql('t', $langs, 'rd', $params);
            $where .= " AND NOT EXISTS (
                SELECT 1
                  FROM {" . self::TABLE . "} t2
                 WHERE t2.groupid = t.groupid
                   AND t2.id <> t.id
                   AND $where2
                   AND ($siblingbetter OR ($siblingtie AND t2.id < t.id)))";
        }

        $sql = "SELECT t.*
                  FROM {" . self::TABLE . "} t
                 WHERE $where
              ORDER BY $order";

        return $this->db->get_records_sql($sql, $params, $offset, $limit);
    }

    /**
     * How many articles a list() with the same arguments would return —
     * groups, when `resolve` is among the filters.
     *
     * @param int   $scope
     * @param array $filters
     * @return int
     */
    public function count(int $scope, array $filters = []): int {
        [$where, $params] = $this->build_where($scope, $filters);
        $what = $this->resolve_langs($filters) !== null ? 'COUNT(DISTINCT t.groupid)' : 'COUNT(1)';
        return $this->db->count_records_sql("SELECT $what FROM {" . self::TABLE . "} t WHERE $where", $params);
    }

    /**
     * Visible articles per category, for a browse screen — groups per
     * category when `resolve` is among the filters, which is right because
     * every row of a group shares its category (the service keeps it so).
     *
     * @param int   $scope   A kb_access_policy::SCOPE_* constant.
     * @param array $filters Same keys as list() — status, lang, resolve; categoryid is ignored
     *                       since the result is grouped by it.
     * @return array<int, int> categoryid => count. Uncategorised rows land under key 0.
     */
    public function count_by_category(int $scope, array $filters = []): array {
        unset($filters['categoryid']);
        [$where, $params] = $this->build_where($scope, $filters);
        $what = $this->resolve_langs($filters) !== null ? 'COUNT(DISTINCT t.groupid)' : 'COUNT(1)';

        $sql = "SELECT COALESCE(t.categoryid, 0) AS categoryid, $what AS total
                  FROM {" . self::TABLE . "} t
                 WHERE $where
              GROUP BY COALESCE(t.categoryid, 0)";

        $counts = [];
        foreach ($this->db->get_records_sql($sql, $params) as $row) {
            $counts[(int) $row->categoryid] = (int) $row->total;
        }
        return $counts;
    }

    /*
     * -------------------------------------------------------------------------
     * Read — search
     * -------------------------------------------------------------------------
     */

    /**
     * Candidate articles for a tokenised query.
     *
     * Every token must appear in the title or in the derived searchtext
     * (case-insensitive LIKE, portable across the three supported databases);
     * ranking is the service's job, so this returns candidates newest-first up
     * to $fetchlimit and does not try to order them by relevance. Tokens are
     * escaped, so `%` and `_` in a query are literal characters.
     *
     * `%token%` cannot use an index: this is a scan of whatever rows the scope
     * leaves, and it is meant to be. XMLDB has no full-text index to offer
     * across MySQL, MariaDB and PostgreSQL, and the table is small by nature.
     * Measured in KB-03; not to be optimised ahead of that measurement.
     *
     * @param string[] $tokens     Already normalised by the service; non-empty.
     * @param int      $scope      A kb_access_policy::SCOPE_* constant.
     * @param array    $filters    Same keys as list().
     * @param int      $fetchlimit Upper bound on candidates returned.
     * @return \stdClass[] Keyed by id.
     */
    public function search(array $tokens, int $scope, array $filters = [], int $fetchlimit = 200): array {
        if (empty($tokens)) {
            return [];
        }

        [$where, $params] = $this->build_where($scope, $filters);
        $conditions = [$where];

        foreach (array_values($tokens) as $i => $token) {
            $like = '%' . $this->db->sql_like_escape($token) . '%';
            $conditions[] = '('
                . $this->db->sql_like('t.title', ":title$i", false, false) . ' OR '
                . $this->db->sql_like('t.searchtext', ":body$i", false, false)
                . ')';
            $params["title$i"] = $like;
            $params["body$i"]  = $like;
        }

        $sql = "SELECT t.*
                  FROM {" . self::TABLE . "} t
                 WHERE " . implode(' AND ', $conditions) . "
              ORDER BY t.timepublished DESC, t.timemodified DESC, t.id DESC";

        return $this->db->get_records_sql($sql, $params, 0, $fetchlimit);
    }

    /*
     * -------------------------------------------------------------------------
     * Write
     * -------------------------------------------------------------------------
     */

    /**
     * Insert an article and give it its group identity.
     *
     * A row with no groupid is its own group: the source-language article of a
     * group nothing else belongs to yet. Set here, in the one place rows are
     * born, so no persisted row keeps the column default.
     *
     * @param \stdClass $data Full record minus id.
     * @return int New article id.
     */
    public function insert(\stdClass $data): int {
        $id = (int) $this->db->insert_record(self::TABLE, $data);
        if (empty($data->groupid)) {
            $this->db->set_field(self::TABLE, 'groupid', $id, ['id' => $id]);
        }
        return $id;
    }

    /**
     * Update an article. Only the fields present on $data are written.
     *
     * @param \stdClass $data Must have id.
     * @return bool
     */
    public function update(\stdClass $data): bool {
        return $this->db->update_record(self::TABLE, $data);
    }

    /**
     * Delete an article row.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool {
        return $this->db->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Open a delegated transaction on the connection this repository writes to.
     *
     * The service owns what is atomic (a row and its group's shared fields;
     * a delete and the re-parent that follows it); the connection is this
     * class's, so the transaction is opened here and handed back.
     *
     * @return \moodle_transaction
     */
    public function begin_transaction(): \moodle_transaction {
        return $this->db->start_delegated_transaction();
    }

    /**
     * Write the group-shared fields on every row of a group.
     *
     * Category and audience belong to the article, not to its wording, so
     * every translation carries the same value; this is the only writer of
     * them across a group. Not atomic on its own — the service wraps it in
     * the transaction that also writes the edited row.
     *
     * @param int   $groupid
     * @param array $fields categoryid (int|null) and/or audience (int).
     * @return void
     */
    public function update_group_fields(int $groupid, array $fields): void {
        foreach (['categoryid', 'audience'] as $field) {
            if (array_key_exists($field, $fields)) {
                $this->db->set_field(self::TABLE, $field, $fields[$field], ['groupid' => $groupid]);
            }
        }
    }

    /**
     * Move every row of a group to a new group identity.
     *
     * Used when the canonical row (id = groupid) is deleted while
     * translations remain: they are re-pointed to the lowest surviving id so
     * the group keeps a row whose id is its groupid. Row ids never change.
     *
     * @param int $oldgroupid
     * @param int $newgroupid
     * @return void
     */
    public function reparent_group(int $oldgroupid, int $newgroupid): void {
        $this->db->set_field(self::TABLE, 'groupid', $newgroupid, ['groupid' => $oldgroupid]);
    }

    /*
     * -------------------------------------------------------------------------
     * Internals
     * -------------------------------------------------------------------------
     */

    /**
     * The WHERE clause for a scope plus optional filters, on a table alias.
     *
     * The alias is part of every parameter name, so the same clause can be
     * emitted twice in one query (the row and its group siblings in the
     * anti-join) without a name being bound twice. The `resolve` key is not
     * a condition and is left out here.
     *
     * @param int    $scope
     * @param array  $filters
     * @param string $alias The table alias the conditions apply to.
     * @return array{0: string, 1: array}
     */
    private function build_where(int $scope, array $filters, string $alias = 't'): array {
        $p = $alias === 't' ? '' : '_' . $alias;
        [$conditions, $params] = $this->scope_sql($scope, $alias);

        if (isset($filters['status'])) {
            $conditions[]            = "$alias.status = :fstatus$p";
            $params["fstatus$p"] = (int) $filters['status'];
        }
        if (isset($filters['categoryid'])) {
            if ((int) $filters['categoryid'] === 0) {
                $conditions[] = "$alias.categoryid IS NULL";
            } else {
                $conditions[]              = "$alias.categoryid = :fcategory$p";
                $params["fcategory$p"] = (int) $filters['categoryid'];
            }
        }
        if (!empty($filters['lang'])) {
            $conditions[]          = "$alias.lang = :flang$p";
            $params["flang$p"] = (string) $filters['lang'];
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * The conditions a scope imposes. The only translation of scope into SQL.
     *
     * @param int    $scope A kb_access_policy::SCOPE_* constant.
     * @param string $alias The table alias the conditions apply to.
     * @return array{0: string[], 1: array}
     */
    private function scope_sql(int $scope, string $alias = 't'): array {
        $p = $alias === 't' ? '' : '_' . $alias;
        switch ($scope) {
            case kb_access_policy::SCOPE_ALL:
                return [['1 = 1'], []];
            case kb_access_policy::SCOPE_AGENT:
                return [["$alias.status = :sstatus$p"], ["sstatus$p" => kb_article::STATUS_PUBLISHED]];
            case kb_access_policy::SCOPE_PUBLIC:
                return [
                    ["$alias.status = :sstatus$p", "$alias.audience = :saudience$p"],
                    ["sstatus$p" => kb_article::STATUS_PUBLISHED, "saudience$p" => kb_article::AUDIENCE_EVERYONE],
                ];
            case kb_access_policy::SCOPE_NONE:
            default:
                // Unknown is treated as none: a scope this class was not told
                // about must not turn into "everything".
                return [['1 = 0'], []];
        }
    }

    /**
     * The language codes a `resolve` filter asks for, or null when there is
     * no such filter (every row is returned, as before KB-06).
     *
     * @param array $filters
     * @return string[]|null
     */
    private function resolve_langs(array $filters): ?array {
        if (empty($filters['resolve']) || !is_array($filters['resolve'])) {
            return null;
        }
        $langs = [];
        foreach ($filters['resolve'] as $lang) {
            $lang = (string) $lang;
            if ($lang !== '' && !in_array($lang, $langs, true)) {
                $langs[] = $lang;
            }
        }
        return $langs ?: null;
    }

    /**
     * A row's rank in the language chain, as SQL: 0 for the first code, 1
     * for the second, …, then the canonical row, then everything else.
     *
     * Every emission binds its own parameters ($prefix), because DML refuses
     * a named parameter used twice and the anti-join needs the rank of both
     * sides twice over.
     *
     * @param string   $alias  Table alias the rank is computed for.
     * @param string[] $langs  The codes, in order of preference.
     * @param string   $prefix Parameter-name prefix, unique per emission.
     * @param array    $params Extended with this emission's parameters.
     * @return string
     */
    private function rank_sql(string $alias, array $langs, string $prefix, array &$params): string {
        $sql = 'CASE';
        foreach (array_values($langs) as $i => $lang) {
            $sql .= " WHEN $alias.lang = :{$prefix}l$i THEN $i";
            $params["{$prefix}l$i"] = $lang;
        }
        $n = count($langs);
        return $sql . " WHEN $alias.id = $alias.groupid THEN $n ELSE " . ($n + 1) . ' END';
    }
}
