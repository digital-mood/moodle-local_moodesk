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
 * Department application service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\repository\department_repository;
use local_moodesk\service\edition_service;
use local_moodesk\util\user_fields;

/**
 * Department application service.
 *
 * CONTRACT:
 *  - All department business logic lives here.
 *  - Capability checks happen first in every public method.
 *  - No $DB access directly — all data access goes through department_repository.
 */
class department_service {
    /** @var department_repository */
    private department_repository $deptrepo;

    /**
     * Build the service with its injected dependencies.
     *
     * @param department_repository $deptrepo
     */
    public function __construct(department_repository $deptrepo) {
        $this->deptrepo = $deptrepo;
    }

    /**
     * Create a fully-wired service instance for production use.
     *
     * @return self
     */
    public static function create(): self {
        return new self(new department_repository());
    }

    /*
     * -------------------------------------------------------------------------
     * Read
     * -------------------------------------------------------------------------
     */

    /**
     * Return all active departments as an id => name map (for select elements).
     *
     * No capability check — reading the list for display is always allowed
     * to authenticated users (the list is shown in ticket submission forms).
     *
     * @return array<int, string>
     */
    public function get_active_menu(): array {
        return $this->deptrepo->get_active_menu();
    }

    /**
     * Return all active department records.
     *
     * @return \stdClass[]
     */
    public function get_all_active(): array {
        return $this->deptrepo->get_all_active();
    }

    /**
     * Return all department records (active and archived) for admin views.
     *
     * @param int $actorid
     * @return \stdClass[]
     * @throws \required_capability_exception
     */
    public function get_all(int $actorid): array {
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);
        return $this->deptrepo->get_all();
    }

    /**
     * Return the departments the given agent belongs to.
     *
     * @param int $userid
     * @return \stdClass[]
     */
    public function get_user_departments(int $userid): array {
        return $this->deptrepo->get_user_departments($userid);
    }

    /**
     * Return the user IDs of members of a department.
     *
     * @param int $departmentid
     * @return int[]
     */
    public function get_member_ids(int $departmentid): array {
        return $this->deptrepo->get_member_ids($departmentid);
    }

    /**
     * Return a single department by ID.
     *
     * @param int $id
     * @param int $actorid
     * @return \stdClass
     * @throws \required_capability_exception
     * @throws \moodle_exception If not found.
     */
    public function get_department(int $id, int $actorid): \stdClass {
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);
        $dept = $this->deptrepo->get_by_id($id);
        if (!$dept) {
            throw new \moodle_exception('departmentnotfound', 'local_moodesk');
        }
        return $dept;
    }

    /**
     * Return the number of members in a department.
     *
     * No capability check — count is used for display in admin list.
     *
     * @param int $departmentid
     * @return int
     */
    public function get_member_count(int $departmentid): int {
        return $this->deptrepo->count_members($departmentid);
    }

    /**
     * Return member records (with user data) for a department.
     *
     * @param int $departmentid
     * @param int $actorid
     * @return \stdClass[]
     * @throws \required_capability_exception
     */
    public function get_members(int $departmentid, int $actorid): array {
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);
        return $this->deptrepo->get_members_with_userdata($departmentid);
    }

    /**
     * Return agents who hold :agent capability but are NOT yet members of the department.
     *
     * Optionally filtered by a name/email search string. Designed to populate the
     * "Add agent" selector on the department members page. Structure is intentionally
     * simple (in-PHP filtering of the full agents list) so it can be replaced with
     * an autocomplete/AJAX approach in a future phase without touching the service contract.
     *
     * @param int    $departmentid
     * @param int    $actorid
     * @param string $search  Optional substring to match against fullname or email.
     * @return \stdClass[]  Each record has: id, all fullname() name fields, email.
     * @throws \required_capability_exception
     */
    public function get_available_agents(int $departmentid, int $actorid, string $search = ''): array {
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);

        $context     = \context_system::instance();
        $memberids   = $this->deptrepo->get_member_ids($departmentid);
        $memberidset = array_flip($memberids);

        // Passing $doanything = false ensures site admins are not included unless they
        // explicitly hold :agent — consistent with get_assignable_agents().
        $allagents = get_users_by_capability(
            $context,
            'local/moodesk:agent',
            user_fields::for_fullname('u') . ', u.email',
            'u.lastname ASC, u.firstname ASC',
            '',
            '',
            '',
            '',
            false
        );

        $search    = trim($search);
        $available = [];

        foreach ($allagents as $agent) {
            if (isset($memberidset[$agent->id])) {
                continue;
            }
            if ($search !== '') {
                $haystack = mb_strtolower(fullname($agent) . ' ' . $agent->email);
                if (mb_strpos($haystack, mb_strtolower($search)) === false) {
                    continue;
                }
            }
            $available[] = $agent;
        }

        return $available;
    }

    /*
     * -------------------------------------------------------------------------
     * Department CRUD
     * -------------------------------------------------------------------------
     */

    /**
     * Create a new department.
     *
     * @param \stdClass $data  Must have: name. Optional: description.
     * @param int       $actorid
     * @return int  New department ID.
     * @throws \required_capability_exception
     * @throws \moodle_exception On missing required fields.
     */
    public function create_department(\stdClass $data, int $actorid): int {
        edition_service::require_feature('multi_department');
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);

        if (empty(trim($data->name ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'name');
        }

        $now    = time();
        $record = new \stdClass();
        $record->name         = clean_param(trim($data->name), PARAM_TEXT);
        $record->description  = clean_param(trim($data->description ?? ''), PARAM_TEXT);
        $record->isactive     = 1;
        $record->timecreated  = $now;
        $record->timemodified = $now;
        $record->usermodified = $actorid;

        return $this->deptrepo->insert($record);
    }

    /**
     * Update an existing department.
     *
     * @param int       $departmentid
     * @param \stdClass $data  Fields to update (name, description, isactive).
     * @param int       $actorid
     * @throws \required_capability_exception
     * @throws \moodle_exception If department does not exist.
     */
    public function update_department(int $departmentid, \stdClass $data, int $actorid): void {
        edition_service::require_feature('multi_department');
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);

        if (!$this->deptrepo->exists($departmentid)) {
            throw new \moodle_exception('departmentnotfound', 'local_moodesk');
        }

        $update               = new \stdClass();
        $update->id           = $departmentid;
        $update->timemodified = time();
        $update->usermodified = $actorid;

        if (isset($data->name)) {
            $update->name = clean_param(trim($data->name), PARAM_TEXT);
        }
        if (isset($data->description)) {
            $update->description = clean_param(trim($data->description), PARAM_TEXT);
        }
        if (isset($data->isactive)) {
            $update->isactive = (int) (bool) $data->isactive;
        }

        $this->deptrepo->update($update);
    }

    /*
     * -------------------------------------------------------------------------
     * Membership management
     * -------------------------------------------------------------------------
     */

    /**
     * Add an agent to a department.
     *
     * @param int $departmentid
     * @param int $userid        The agent to add.
     * @param int $actorid       Must hold :managedepartment.
     * @throws \required_capability_exception
     * @throws \moodle_exception If department does not exist or user lacks :agent cap.
     */
    public function add_member(int $departmentid, int $userid, int $actorid): void {
        edition_service::require_feature('multi_department');
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);

        if (!$this->deptrepo->exists_active($departmentid)) {
            throw new \moodle_exception('departmentnotfound', 'local_moodesk');
        }

        // Only users with :agent cap should be department members.
        if (!has_capability('local/moodesk:agent', \context_system::instance(), $userid, false)) {
            throw new \moodle_exception('invalidassignee', 'local_moodesk');
        }

        $this->deptrepo->add_member($departmentid, $userid);
    }

    /**
     * Remove an agent from a department.
     *
     * @param int $departmentid
     * @param int $userid
     * @param int $actorid
     * @throws \required_capability_exception
     */
    public function remove_member(int $departmentid, int $userid, int $actorid): void {
        edition_service::require_feature('multi_department');
        require_capability('local/moodesk:managedepartment', \context_system::instance(), $actorid);
        $this->deptrepo->remove_member($departmentid, $userid);
    }
}
