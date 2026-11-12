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
 * Agent management application service (Agent Management layer, A0).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\repository\department_repository;
use local_moodesk\repository\ticket_repository;
use local_moodesk\util\user_fields;

/**
 * Agent management application service.
 *
 * A curated orchestration layer over Moodle's role API — it does NOT replace it.
 * Moodle stays the source of truth for who is an agent: agent status is the
 * `local/moodesk:agent` capability, never a private record. This service only
 * decides *who is an agent* (by toggling the seeded `mhd_agent` role) and reads
 * their operational footprint; defining *what* an agent can do stays in Moodle.
 *
 * CONTRACT:
 *  - This is the ONLY place in the plugin that calls role_assign/role_unassign.
 *    Everything else keeps reading agent status through capabilities, so the
 *    abstraction never leaks and Moodle stays authoritative.
 *  - Every mutating/reading admin method requires :manageagents on the actor.
 *  - Site admins are excluded from the agent roster unless they explicitly hold
 *    :agent ($doanything = false everywhere), matching ticket_service::is_agent().
 *
 * Managed vs unmanaged (proposal §7.1):
 *  - "Managed" agents hold the seeded `mhd_agent` role assigned through mooDesk —
 *    fully editable here (grant/revoke).
 *  - "Unmanaged" agents are agents via some *other* Moodle mechanism (site admin,
 *    custom role, SSO role mapping). They are shown but their status is NOT
 *    touched here — that lever lives in Moodle. revoke_agent() refuses them
 *    rather than silently failing.
 *
 * Entitlement (D15, capacity per licence contract v2 §6.4):
 *  - The licence's capacity caps how many *active* agents a site may have
 *    (entitlement_service::agent_limit()). grant_agent() is the one gate: it
 *    refuses to add an agent when the site is at or over its cap. Agents who
 *    arrive through Moodle itself — a role assigned on the admin screens, an
 *    SSO role mapping, a seeder — are counted but not intercepted: mooDesk
 *    does not hook Moodle's role assignment and keeps no parallel roster. A
 *    site can therefore be over its limit; when it is, the pages say so and
 *    this service blocks new grants until it is back under.
 *  - "Active" is one definition, used by the roster filter and by the count
 *    alike: holds :agent at system context with $doanything = false, not
 *    deleted (the capability query already excludes deleted users) and not
 *    suspended. Managed and unmanaged agents both count.
 */
class agent_service {
    /** Primary agent marker capability. */
    private const CAP_AGENT = 'local/moodesk:agent';

    /** Capability required to manage agents. */
    private const CAP_MANAGE = 'local/moodesk:manageagents';

    /** Shortname of the seeded, mooDesk-managed agent role. */
    private const AGENT_ROLE_SHORTNAME = 'mhd_agent';

    /** @var ticket_repository */
    private ticket_repository $ticketrepo;

    /** @var department_repository */
    private department_repository $deptrepo;

    /** @var ticket_service Used to requeue an offboarded agent's tickets. */
    private ticket_service $ticketservice;

    /**
     * Build the service with its injected dependencies.
     *
     * @param ticket_repository     $ticketrepo
     * @param department_repository $deptrepo
     * @param ticket_service        $ticketservice
     */
    public function __construct(
        ticket_repository $ticketrepo,
        department_repository $deptrepo,
        ticket_service $ticketservice
    ) {
        $this->ticketrepo    = $ticketrepo;
        $this->deptrepo      = $deptrepo;
        $this->ticketservice = $ticketservice;
    }

    /**
     * Build a fully-wired service for production use.
     *
     * @return self
     */
    public static function create(): self {
        return new self(
            new ticket_repository(),
            new department_repository(),
            ticket_service::create()
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Read
     * -------------------------------------------------------------------------
     */

    /**
     * Return true if the user is an agent (holds :agent), regardless of *how*.
     *
     * Mirrors ticket_service::is_agent(); duplicated intentionally so the agent
     * layer does not depend on the ticket service.
     *
     * @param int $userid
     * @return bool
     */
    public function is_agent(int $userid): bool {
        return has_capability(self::CAP_AGENT, \context_system::instance(), $userid, false);
    }

    /**
     * Return true if the user is a *managed* agent — i.e. holds the seeded
     * `mhd_agent` role specifically, so mooDesk can toggle their status.
     *
     * A user who is an agent only via another role is unmanaged: this returns
     * false even though {@see is_agent()} returns true.
     *
     * @param int $userid
     * @return bool
     */
    public function is_managed_agent(int $userid): bool {
        $roleid = $this->resolve_agent_role_id();
        if ($roleid === null) {
            return false;
        }
        return user_has_role_assignment($userid, $roleid, \context_system::instance()->id);
    }

    /**
     * Count the site's active agents — the number the entitlement is compared
     * against.
     *
     * Same base query and same "active" predicate as
     * list_agents(['status' => 'active']), by construction: both go through
     * fetch_agents() and is_active(), so the count on the licence page can
     * never disagree with the roster the directory shows.
     *
     * No actor and no capability check: this is a site fact with no personal
     * data in it, read by the grant gate and by admin pages that carry their
     * own access checks.
     *
     * @return int
     */
    public function count_active_agents(): int {
        return count(array_filter($this->fetch_agents(), [self::class, 'is_active']));
    }

    /**
     * Whether an agent row counts as active: not suspended.
     *
     * Deleted users never reach this predicate — the capability query in
     * fetch_agents() excludes them — so "active" is one column here.
     *
     * @param \stdClass $user A row from fetch_agents() (carries suspended).
     * @return bool
     */
    public static function is_active(\stdClass $user): bool {
        return empty($user->suspended);
    }

    /**
     * Return the decorated agent roster: every user holding :agent, with their
     * managed flag, department and group membership and open-ticket workload.
     *
     * Includes both managed and unmanaged agents (proposal §7.1). Site admins
     * are excluded unless they explicitly hold :agent.
     *
     * @param int   $actorid Must hold :manageagents.
     * @param array $filters Optional. Supported: 'search' (name/email substring).
     * @return \stdClass[] Keyed by userid. Each row: id, fullname, email,
     *                     suspended, managed, departments[], teams[], openworkload.
     * @throws \required_capability_exception
     */
    public function list_agents(int $actorid, array $filters = []): array {
        require_capability(self::CAP_MANAGE, \context_system::instance(), $actorid);

        $agents = $this->fetch_agents();

        if (empty($agents)) {
            return [];
        }

        // Search (name / email).
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $agents = array_filter(
                $agents,
                static fn($u) => mb_strpos(mb_strtolower(fullname($u) . ' ' . $u->email), $needle) !== false
            );
        }

        // Status (active / suspended).
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'active') {
            $agents = array_filter($agents, [self::class, 'is_active']);
        } else if ($status === 'suspended') {
            $agents = array_filter($agents, static fn($u) => !self::is_active($u));
        }

        // Department membership.
        $departmentid = (int) ($filters['departmentid'] ?? 0);
        if ($departmentid > 0) {
            $members = array_flip($this->deptrepo->get_member_ids($departmentid));
            $agents  = array_filter($agents, static fn($u) => isset($members[(int) $u->id]));
        }

        // Plugin groups, which may narrow the roster (hook agent_groups).
        $groupshook = $this->groups_for(array_map(static fn($u) => (int) $u->id, $agents), $filters['plugin'] ?? []);
        $kept = $groupshook->get_kept();
        if ($kept !== null) {
            $kept   = array_flip($kept);
            $agents = array_filter($agents, static fn($u) => isset($kept[(int) $u->id]));
        }

        if (empty($agents)) {
            return [];
        }

        $managedids = $this->get_managed_agent_ids();

        // Management origin (managed by mooDesk / by Moodle).
        $management = (string) ($filters['management'] ?? '');
        if ($management === 'managed') {
            $agents = array_filter($agents, static fn($u) => isset($managedids[(int) $u->id]));
        } else if ($management === 'unmanaged') {
            $agents = array_filter($agents, static fn($u) => !isset($managedids[(int) $u->id]));
        }

        if (empty($agents)) {
            return [];
        }

        $userids  = array_map(static fn($u) => (int) $u->id, $agents);
        $workload = $this->ticketrepo->count_open_by_assignee($userids);
        // Memberships for the whole roster in one read per table, rather than
        // two reads per row (scale bench 2026-09-16 §7.2).
        $depts = $this->deptrepo->get_user_departments_for($userids);
        $groups = $groupshook->get_groups();

        $roster = [];
        foreach ($agents as $user) {
            $roster[(int) $user->id] = $this->decorate($user, $managedids, $workload, $depts, $groups);
        }

        return $roster;
    }

    /**
     * Return active departments that currently have no agent members.
     *
     * Powers the directory "needs attention" banner (A1): a department with no
     * members can never receive a routed assignment. Departments are a Starter
     * feature, so this is available in every edition.
     *
     * @param int $actorid Must hold :manageagents.
     * @return \stdClass[] Each: { id, name }.
     * @throws \required_capability_exception
     */
    public function get_departments_without_members(int $actorid): array {
        require_capability(self::CAP_MANAGE, \context_system::instance(), $actorid);

        $empty = [];
        foreach ($this->deptrepo->get_all_active() as $dept) {
            if (empty($this->deptrepo->get_member_ids((int) $dept->id))) {
                $empty[] = (object) [
                    'id'   => (int) $dept->id,
                    'name' => format_string($dept->name),
                ];
            }
        }

        return $empty;
    }

    /**
     * Return the decorated profile for a single agent.
     *
     * @param int $userid
     * @param int $actorid Must hold :manageagents.
     * @return \stdClass Same shape as a {@see list_agents()} row.
     * @throws \required_capability_exception
     * @throws \moodle_exception If the user is not an agent or does not exist.
     */
    public function get_agent_profile(int $userid, int $actorid): \stdClass {
        require_capability(self::CAP_MANAGE, \context_system::instance(), $actorid);

        if (!$this->is_agent($userid)) {
            throw new \moodle_exception('invalidassignee', 'local_moodesk');
        }

        $fields = user_fields::for_userpic('u') . ', u.suspended';
        $user   = \core_user::get_user($userid, str_replace('u.', '', $fields), MUST_EXIST);

        return $this->decorate(
            $user,
            $this->get_managed_agent_ids(),
            $this->ticketrepo->count_open_by_assignee([$userid]),
            $this->deptrepo->get_user_departments_for([$userid]),
            $this->groups_for([$userid])->get_groups()
        );
    }

    /**
     * Search Moodle users who are candidates to become agents.
     *
     * Returns active users (not deleted, not suspended, not the guest) matching
     * the search string by full name or email, EXCLUDING anyone who is already an
     * agent — you cannot "add" an existing agent. Requires a minimal search term
     * and caps the result set, so it stays cheap on large sites (A1 can layer a
     * proper autocomplete on top without changing this contract).
     *
     * @param string $search  Name/email substring. Below 2 chars returns [].
     * @param int    $actorid Must hold :manageagents.
     * @param int    $limit   Maximum candidates to return.
     * @return \stdClass[] Keyed by userid. Each row: id, fullname, email.
     * @throws \required_capability_exception
     */
    public function search_candidate_users(string $search, int $actorid, int $limit = 50): array {
        require_capability(self::CAP_MANAGE, \context_system::instance(), $actorid);

        global $DB, $CFG;

        $search = trim($search);
        if (\core_text::strlen($search) < 2) {
            return [];
        }

        // Exclude everyone who is already an agent (managed or unmanaged) plus the
        // guest account. $doanything = false keeps site admins out of the agent
        // set — they remain valid candidates unless they already hold :agent.
        $agents     = get_users_by_capability(\context_system::instance(), self::CAP_AGENT, 'u.id', '', '', '', '', '', false);
        $excludeids = array_map(static fn($u) => (int) $u->id, $agents);
        $excludeids[] = (int) $CFG->siteguest;

        $fields   = user_fields::for_userpic('u');
        $fullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $term     = '%' . $DB->sql_like_escape($search) . '%';

        $where  = 'u.deleted = 0 AND u.suspended = 0 AND ('
            . $DB->sql_like($fullname, ':searchname', false) . ' OR '
            . $DB->sql_like('u.email', ':searchmail', false) . ')';
        $params = ['searchname' => $term, 'searchmail' => $term];

        [$notinsql, $notinparams] = $DB->get_in_or_equal($excludeids, SQL_PARAMS_NAMED, 'ex', false);
        $where  .= " AND u.id {$notinsql}";
        $params += $notinparams;

        $sql = "SELECT {$fields}
                  FROM {user} u
                 WHERE {$where}
              ORDER BY u.lastname ASC, u.firstname ASC";

        $candidates = [];
        foreach ($DB->get_records_sql($sql, $params, 0, $limit) as $user) {
            $candidates[(int) $user->id] = (object) [
                'id'       => (int) $user->id,
                'fullname' => fullname($user),
                'email'    => $user->email,
                // The record the row came from, so the page can draw the
                // candidate's face without a second query per result. The
                // record, not the markup: user_picture() needs a renderer, and
                // rendering HTML is not this layer's job.
                'user'     => $user,
            ];
        }

        return $candidates;
    }

    /*
     * -------------------------------------------------------------------------
     * Write — the only caller of role_assign / role_unassign
     * -------------------------------------------------------------------------
     */

    /**
     * Grant agent status to an existing Moodle user by assigning the seeded
     * `mhd_agent` role at System context.
     *
     * Idempotent (role_assign is a no-op if already assigned). This can only ever
     * assign the fixed agent role — it can never escalate a user to manager or
     * admin.
     *
     * Refused with `agents_limit_reached` when the user is not yet an agent and
     * the site has no room under its capacity (see the class contract). The
     * message names the way out for the site's plan (contract §8).
     *
     * @param int $userid  The user to make an agent.
     * @param int $actorid Must hold :manageagents.
     * @throws \required_capability_exception
     * @throws \moodle_exception If the agent role cannot be resolved, the user is
     *                           invalid, or the agent limit is reached.
     */
    public function grant_agent(int $userid, int $actorid): void {
        require_capability(self::CAP_MANAGE, \context_system::instance(), $actorid);

        $roleid = $this->resolve_agent_role_id();
        if ($roleid === null) {
            throw new \moodle_exception('agents_role_missing', 'local_moodesk');
        }

        $user = \core_user::get_user($userid);
        if (!$user || $user->deleted) {
            throw new \moodle_exception('invaliduser', 'error');
        }

        // The entitlement gate, after authorisation and validation so a caller
        // without :manageagents is refused for that reason and never learns the
        // count. Only a grant that would ADD an agent is gated: re-granting an
        // existing agent (idempotent call, or a managed role for someone who is
        // an agent through another role) changes no number and stays allowed
        // even on an over-limit site. can_add_agent() applies the agent_capacity
        // hook, which may lift the refusal altogether.
        if (!$this->is_agent($userid)) {
            $active = $this->count_active_agents();
            if (!entitlement_service::can_add_agent($active)) {
                $ent = entitlement_service::agent_entitlement();
                throw new \moodle_exception('agents_limit_reached', 'local_moodesk', '', (object) [
                    'active'   => $active,
                    'capacity' => $ent->limit,
                    'edition'  => get_string('edition_' . $ent->edition, 'local_moodesk'),
                ]);
            }
        }

        role_assign($roleid, $userid, \context_system::instance()->id);
    }

    /**
     * Revoke agent status from a *managed* agent by unassigning the `mhd_agent`
     * role, and clean up their department and group membership.
     *
     * Guards:
     *  - Unmanaged agents are refused (their status lives in another role, which
     *    we must not silently touch — proposal §7.1).
     *  - Refuses while the agent still holds open assigned tickets, so revoking
     *    never orphans tickets on a now-non-agent. The guided reassignment flow
     *    (A3) clears these before calling revoke.
     *
     * @param int $userid
     * @param int $actorid Must hold :manageagents.
     * @throws \required_capability_exception
     * @throws \moodle_exception If unmanaged, if the role is missing, or if open tickets remain.
     */
    public function revoke_agent(int $userid, int $actorid): void {
        require_capability(self::CAP_MANAGE, \context_system::instance(), $actorid);

        $roleid = $this->resolve_agent_role_id();
        if ($roleid === null) {
            throw new \moodle_exception('agents_role_missing', 'local_moodesk');
        }

        if (!user_has_role_assignment($userid, $roleid, \context_system::instance()->id)) {
            // Not a managed agent — cannot revoke here (may be an agent via
            // another role; that lever lives in Moodle).
            throw new \moodle_exception('agents_cannot_revoke_unmanaged', 'local_moodesk');
        }

        $workload = $this->ticketrepo->count_open_by_assignee([$userid]);
        if (!empty($workload[$userid])) {
            throw new \moodle_exception('agents_has_open_tickets', 'local_moodesk');
        }

        // Remove operational membership so no stale row points at a non-agent.
        $this->purge_membership($userid);

        role_unassign($roleid, $userid, \context_system::instance()->id);
    }

    /**
     * Offboard a managed agent end to end: requeue their open tickets, then
     * revoke agent status and purge membership.
     *
     * This is the safe, orphan-free offboard (proposal §4, §7): open tickets are
     * returned to the queue before the role is removed, so revoke_agent()'s
     * no-orphan guard passes. Unmanaged agents are refused up front, before any
     * ticket is touched. The returned residual flag tells the caller whether the
     * user is STILL an agent through another role, so the UI can warn that agent
     * status persists.
     *
     * @param int $userid
     * @param int $actorid Must hold :manageagents.
     * @return \stdClass { requeued: int, residual: bool }
     * @throws \required_capability_exception
     * @throws \moodle_exception If the role is missing or the agent is unmanaged.
     */
    public function offboard_agent(int $userid, int $actorid): \stdClass {
        require_capability(self::CAP_MANAGE, \context_system::instance(), $actorid);

        // Refuse unmanaged agents before touching their tickets.
        $roleid = $this->resolve_agent_role_id();
        if ($roleid === null) {
            throw new \moodle_exception('agents_role_missing', 'local_moodesk');
        }
        if (!user_has_role_assignment($userid, $roleid, \context_system::instance()->id)) {
            throw new \moodle_exception('agents_cannot_revoke_unmanaged', 'local_moodesk');
        }

        // Return their open tickets to the queue so nothing is orphaned, then
        // revoke (its guard now passes) and purge dept/gram membership.
        $requeued = $this->ticketservice->requeue_agent_tickets($userid, $actorid);
        $this->revoke_agent($userid, $actorid);

        // The user may still be an agent via another role — surface it so the UI
        // can warn that revoking the managed role did not remove agent status.
        $residual = $this->is_agent($userid);

        return (object) [
            'requeued' => $requeued,
            'residual' => $residual,
        ];
    }

    /*
     * -------------------------------------------------------------------------
     * Private helpers
     * -------------------------------------------------------------------------
     */

    /**
     * Resolve a display-only "level" label for an agent (A4 panel).
     *
     * admin (site admin) > manager (holds a mooDesk manage capability) > agent.
     * Read-only: derived from Moodle capabilities, never edited here. $doanything
     * is false so a site admin is caught by the is_siteadmin() check, not by
     * "can do anything" leaking into the manager branch.
     *
     * @param int $userid
     * @return string One of 'admin' | 'manager' | 'agent'.
     */
    public function resolve_level(int $userid): string {
        if (is_siteadmin($userid)) {
            return 'admin';
        }
        if (has_capability('local/moodesk:managesettings', \context_system::instance(), $userid, false)) {
            return 'manager';
        }
        return 'agent';
    }

    /**
     * Decorate a raw user record with agent metadata.
     *
     * @param \stdClass $user       Must carry id, fullname fields, email, suspended.
     * @param array     $managedids Set of managed agent user ids, keyed by userid.
     * @param array     $workload   Open ticket count keyed by userid.
     * @param array     $depts      Active department records keyed by userid
     *                              ({@see department_repository::get_user_departments_for()}).
     * @param array     $groups     Plugin group names keyed by userid
     *                              ({@see \local_moodesk\hook\agent_groups}).
     * @return \stdClass
     */
    private function decorate(
        \stdClass $user,
        array $managedids,
        array $workload,
        array $depts,
        array $groups
    ): \stdClass {
        $uid = (int) $user->id;

        $departments = array_values(array_map(
            static fn($d) => format_string($d->name),
            $depts[$uid] ?? []
        ));
        $groups = array_values($groups[$uid] ?? []);

        return (object) [
            'id'           => $uid,
            'fullname'     => fullname($user),
            'email'        => $user->email,
            // The record the row came from, carried through so a page can render
            // the agent's avatar without going back to the database once per row.
            // Deliberately the record and not the markup: user_picture needs a
            // renderer, and rendering HTML is not this layer's job.
            'user'         => $user,
            'suspended'    => (bool) $user->suspended,
            'managed'      => isset($managedids[$uid]),
            'departments'  => $departments,
            'groups'       => $groups,
            // Attention flag (A1): an agent in no department and no group cannot
            // receive any routed assignment.
            'nomembership' => empty($departments) && empty($groups),
            'openworkload' => $workload[$uid] ?? 0,
        ];
    }

    /**
     * The one query behind the roster and the count: every user holding :agent
     * at system context, with $doanything = false so site admins are not agents
     * unless granted :agent. Deleted users are excluded by the capability
     * query; suspended ones are returned and left to is_active().
     *
     * @return \stdClass[] Keyed by userid; each carries the picture fields and suspended.
     */
    private function fetch_agents(): array {
        return get_users_by_capability(
            \context_system::instance(),
            self::CAP_AGENT,
            // The directory draws each agent's face, so the roster is selected with
            // the picture fields as well as the name ones — see util\user_fields.
            user_fields::for_userpic('u') . ', u.suspended',
            'u.lastname ASC, u.firstname ASC',
            '',
            '',
            '',
            '',
            false
        );
    }

    /**
     * Return the set of user ids that hold the managed `mhd_agent` role.
     *
     * Queries the core role_assignments table directly rather than
     * get_role_users(): that helper's default $sort ('u.lastname, u.firstname')
     * emits a debugging() warning on Moodle 4.5 when $fields does not include
     * those columns (removed in 5.0), which fails under PHPUnit. All we need here
     * is the id set of users holding the role at system context.
     *
     * @return array<int, bool> Map of userid => true (a membership set).
     */
    private function get_managed_agent_ids(): array {
        global $DB;

        $roleid = $this->resolve_agent_role_id();
        if ($roleid === null) {
            return [];
        }

        $userids = $DB->get_fieldset_select(
            'role_assignments',
            'DISTINCT userid',
            'roleid = :roleid AND contextid = :contextid',
            ['roleid' => $roleid, 'contextid' => \context_system::instance()->id]
        );

        $ids = [];
        foreach ($userids as $uid) {
            $ids[(int) $uid] = true;
        }

        return $ids;
    }

    /**
     * Resolve the seeded agent role id defensively.
     *
     * Prefers the config key written at install; falls back to a shortname lookup
     * if the config is stale or the role was re-created. Returns null if the role
     * is genuinely absent (e.g. an admin deleted it) so callers can surface a
     * clear "re-seed" error instead of crashing.
     *
     * @return int|null
     */
    private function resolve_agent_role_id(): ?int {
        global $DB;

        $configured = get_config('local_moodesk', 'agentroleid');
        if (!empty($configured) && $DB->record_exists('role', ['id' => $configured])) {
            return (int) $configured;
        }

        $roleid = $DB->get_field('role', 'id', ['shortname' => self::AGENT_ROLE_SHORTNAME], IGNORE_MISSING);

        return $roleid ? (int) $roleid : null;
    }

    /**
     * Remove a user from every department and plugin group they belong to.
     *
     * Goes through the repositories directly (not the Pro-gated dept/team
     * services) because offboard cleanup must run in every edition.
     *
     * @param int $userid
     */
    private function purge_membership(int $userid): void {
        foreach ($this->deptrepo->get_user_departments($userid) as $dept) {
            $this->deptrepo->remove_member((int) $dept->id, $userid);
        }
        \core\di::get(\core\hook\manager::class)->dispatch(new \local_moodesk\hook\agent_revoking($userid));
    }

    /**
     * What plugin groups are called, if a plugin says (hook agent_groups).
     *
     * @param int[] $userids The agents the text is about; empty for a roster.
     * @return string|null Null when only departments group agents.
     */
    public function group_type(array $userids = []): ?string {
        return $this->groups_for($userids)->get_group_type();
    }

    /**
     * A text about agent membership, naming plugin groups when there are any.
     *
     * Each identifier has a `_withgroup` twin that takes the group type: as
     * `{$a}`, or as `{$a->group}` beside `{$a->count}` when the text also
     * carries a number.
     *
     * @param string      $identifier A local_moodesk string about membership.
     * @param string|null $grouptype  From {@see group_type()}.
     * @param int|null    $count      The number the text carries, if any.
     * @return string
     */
    public static function membership_text(string $identifier, ?string $grouptype, ?int $count = null): string {
        if ($grouptype === null) {
            return get_string($identifier, 'local_moodesk', $count);
        }
        $a = $count === null ? $grouptype : (object) ['count' => $count, 'group' => $grouptype];
        return get_string($identifier . '_withgroup', 'local_moodesk', $a);
    }

    /**
     * Ask plugins for the groups of these agents (hook agent_groups).
     *
     * @param int[] $userids
     * @param array $values  Plugin filter values, name => value.
     * @return \local_moodesk\hook\agent_groups
     */
    private function groups_for(array $userids, array $values = []): \local_moodesk\hook\agent_groups {
        $hook = new \local_moodesk\hook\agent_groups($userids, $values);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return $hook;
    }
}
