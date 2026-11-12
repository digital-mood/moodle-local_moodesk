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
 * Department repository: all database access for department tables.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

use local_moodesk\util\user_fields;

/**
 * Data access layer for local_moodesk_department and local_moodesk_dept_member.
 *
 * CONTRACT:
 *  - This is the only class allowed to query these two tables.
 *  - No capability checks. No business logic. No events.
 *  - Returns raw stdClass objects or arrays as delivered by Moodle DML.
 */
class department_repository {
    /** @var \moodle_database */
    private \moodle_database $db;

    /** Name of the local_moodesk_department table. */
    private const TABLE_DEPT   = 'local_moodesk_department';
    /** Name of the local_moodesk_dept_member table. */
    private const TABLE_MEMBER = 'local_moodesk_dept_member';

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
     * Department CRUD
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch a single department by ID. Returns null if not found.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public function get_by_id(int $id): ?\stdClass {
        $record = $this->db->get_record(self::TABLE_DEPT, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Return all active departments, ordered by name.
     *
     * @return \stdClass[]
     */
    public function get_all_active(): array {
        return array_values(
            $this->db->get_records(self::TABLE_DEPT, ['isactive' => 1], 'name ASC')
        );
    }

    /**
     * Return all departments (active and archived), ordered by name.
     *
     * @return \stdClass[]
     */
    public function get_all(): array {
        return array_values(
            $this->db->get_records(self::TABLE_DEPT, [], 'name ASC')
        );
    }

    /**
     * Return an id => name map of active departments.
     * Convenient for building select element options.
     *
     * @return array<int, string>
     */
    public function get_active_menu(): array {
        return $this->db->get_records_menu(self::TABLE_DEPT, ['isactive' => 1], 'name ASC', 'id, name');
    }

    /**
     * Insert a new department. Returns the new ID.
     *
     * @param \stdClass $data
     * @return int
     */
    public function insert(\stdClass $data): int {
        return (int) $this->db->insert_record(self::TABLE_DEPT, $data);
    }

    /**
     * Update an existing department. $data must include 'id'.
     *
     * @param \stdClass $data
     * @return bool
     */
    public function update(\stdClass $data): bool {
        return $this->db->update_record(self::TABLE_DEPT, $data);
    }

    /**
     * Return true if a department with the given ID exists.
     *
     * @param int $id
     * @return bool
     */
    public function exists(int $id): bool {
        return $this->db->record_exists(self::TABLE_DEPT, ['id' => $id]);
    }

    /**
     * Return true if an active department with the given ID exists.
     *
     * @param int $id
     * @return bool
     */
    public function exists_active(int $id): bool {
        return $this->db->record_exists(self::TABLE_DEPT, ['id' => $id, 'isactive' => 1]);
    }

    /*
     * -------------------------------------------------------------------------
     * Department membership
     * -------------------------------------------------------------------------
     */

    /**
     * Return user IDs of all members of a department.
     *
     * @param int $departmentid
     * @return int[]
     */
    public function get_member_ids(int $departmentid): array {
        $records = $this->db->get_records(
            self::TABLE_MEMBER,
            ['departmentid' => $departmentid],
            '',
            'userid'
        );
        return array_map('intval', array_keys($records));
    }

    /**
     * Return department IDs the given user belongs to.
     *
     * @param int $userid
     * @return int[]
     */
    public function get_user_department_ids(int $userid): array {
        $records = $this->db->get_records(
            self::TABLE_MEMBER,
            ['userid' => $userid],
            '',
            'departmentid'
        );
        return array_map('intval', array_keys($records));
    }

    /**
     * Return department records the given user belongs to (active only).
     *
     * @param int $userid
     * @return \stdClass[]
     */
    public function get_user_departments(int $userid): array {
        $sql = "SELECT d.*
                  FROM {" . self::TABLE_DEPT . "} d
                  JOIN {" . self::TABLE_MEMBER . "} dm ON dm.departmentid = d.id
                 WHERE dm.userid = :uid
                   AND d.isactive = 1
              ORDER BY d.name ASC";

        return array_values($this->db->get_records_sql($sql, ['uid' => $userid]));
    }

    /**
     * Return the active departments of each of the given users, in one query.
     *
     * The batch form of {@see get_user_departments()}: same filter, same order,
     * one read for the whole set instead of one per user. Users with no
     * department are absent from the result, so read it with `?? []`.
     *
     * @param int[] $userids
     * @return array<int, \stdClass[]> Department records keyed by userid, each
     *                                 list ordered by department name.
     */
    public function get_user_departments_for(array $userids): array {
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return [];
        }

        [$insql, $params] = $this->db->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $sql = "SELECT dm.id AS membershipid, dm.userid, d.*
                  FROM {" . self::TABLE_DEPT . "} d
                  JOIN {" . self::TABLE_MEMBER . "} dm ON dm.departmentid = d.id
                 WHERE dm.userid {$insql}
                   AND d.isactive = 1
              ORDER BY d.name ASC, d.id ASC";

        $byuser = [];
        foreach ($this->db->get_records_sql($sql, $params) as $row) {
            $userid = (int) $row->userid;
            unset($row->membershipid, $row->userid);
            $byuser[$userid][] = $row;
        }

        return $byuser;
    }

    /**
     * Return true if the user is a member of the department.
     *
     * @param int $departmentid
     * @param int $userid
     * @return bool
     */
    public function is_member(int $departmentid, int $userid): bool {
        return $this->db->record_exists(self::TABLE_MEMBER, [
            'departmentid' => $departmentid,
            'userid'       => $userid,
        ]);
    }

    /**
     * Add a user to a department. No-op if already a member.
     *
     * @param int $departmentid
     * @param int $userid
     * @return void
     */
    public function add_member(int $departmentid, int $userid): void {
        if ($this->is_member($departmentid, $userid)) {
            return;
        }
        $record               = new \stdClass();
        $record->departmentid = $departmentid;
        $record->userid       = $userid;
        $record->timecreated  = time();
        $this->db->insert_record(self::TABLE_MEMBER, $record);
    }

    /**
     * Remove a user from a department.
     *
     * @param int $departmentid
     * @param int $userid
     * @return void
     */
    public function remove_member(int $departmentid, int $userid): void {
        $this->db->delete_records(self::TABLE_MEMBER, [
            'departmentid' => $departmentid,
            'userid'       => $userid,
        ]);
    }

    /**
     * Remove all members from a department (used when archiving).
     *
     * @param int $departmentid
     * @return void
     */
    public function remove_all_members(int $departmentid): void {
        $this->db->delete_records(self::TABLE_MEMBER, ['departmentid' => $departmentid]);
    }

    /**
     * Return the number of members in a department.
     *
     * @param int $departmentid
     * @return int
     */
    public function count_members(int $departmentid): int {
        return (int) $this->db->count_records(self::TABLE_MEMBER, ['departmentid' => $departmentid]);
    }

    /**
     * Return full member records for a department, joined with user data.
     *
     * All fullname()-required name fields are selected so the result can be
     * passed straight to fullname() without raising the developer-mode warning.
     *
     * @param int $departmentid
     * @return \stdClass[]  Each record has: id (from dm), userid, timecreated, all name fields, email.
     */
    public function get_members_with_userdata(int $departmentid): array {
        // Note: for_userpic() is not used here — it selects `u.id`, which would collide
        // with the membership's own `id` in the same row. The picture columns are
        // named instead — the name fields and email are already above.
        $sql = "SELECT dm.id, dm.userid, dm.timecreated,
                       " . user_fields::for_fullname('u') . ", u.email, u.picture, u.imagealt
                  FROM {" . self::TABLE_MEMBER . "} dm
                  JOIN {user} u ON u.id = dm.userid
                 WHERE dm.departmentid = :deptid
                   AND u.deleted = 0
              ORDER BY u.lastname ASC, u.firstname ASC";

        return array_values($this->db->get_records_sql($sql, ['deptid' => $departmentid]));
    }
}
