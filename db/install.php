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
 * Post-install hook: seeds mandatory data that cannot live in install.xml.
 *
 * IMPORTANT: This function is called by Moodle exactly once, immediately after
 * the schema in install.xml has been created. It must leave the plugin in a
 * fully usable state.
 *
 * Seeds:
 *  1. The mandatory "General" fallback category (required by ticket_service::create()).
 *  2. A default "General" department so the feature is immediately usable.
 *  3. The "Helpdesk Agent" custom role (shortname: mhd_agent) with the six
 *     agent capabilities pre-assigned at System context. Admins only need to
 *     assign the role to their support staff — no manual capability configuration
 *     is needed.
 *  4. The reply-token signing key, so notifications offer a reply-by-email
 *     address from the first ticket.
 *
 * Robustness contract:
 *  1. If the category insert succeeds but set_config() fails, the next admin
 *     page load will detect the missing config key and surface a clear error.
 *  2. Every seeded entity is guarded by an idempotency check so this function
 *     is safe to re-evaluate (e.g. during a restore or re-install scenario).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Seed mandatory post-install data.
 *
 * @return bool True on success. Moodle aborts installation if this returns false.
 */
function xmldb_local_moodesk_install(): bool {
    global $DB;

    // Moodle runs install.php before syncing capabilities from access.php.
    // Force-sync now so assign_capability() can find them below.
    update_capabilities('local_moodesk');

    /*
     * -------------------------------------------------------------------------
     * 1. Seed the "General" fallback category.
     *
     * This is the category assigned to any ticket submitted without an explicit
     * category selection. It must always exist while the plugin is installed.
     * Idempotency: if any visible category already exists, skip insertion and
     * only re-derive the config key if it happens to be missing.
     * -------------------------------------------------------------------------
     */
    $existingcat = $DB->get_record('local_moodesk_category', ['visible' => 1], 'id', IGNORE_MULTIPLE);
    if ($existingcat) {
        $configured = get_config('local_moodesk', 'defaultcategoryid');
        if (empty($configured)) {
            set_config('defaultcategoryid', $existingcat->id, 'local_moodesk');
        }
    } else {
        $category               = new stdClass();
        $category->parentid     = null;
        $category->name         = get_string('defaultcategoryname', 'local_moodesk');
        $category->description  = null;
        $category->sortorder    = 0;
        $category->visible      = 1;
        $category->timecreated  = time();
        $category->usermodified = 0; // 0 = system-seeded, no human actor.

        $categoryid = $DB->insert_record('local_moodesk_category', $category);

        // Store as the configured default. ticket_service::create() reads this
        // key to resolve the fallback when no categoryid is submitted.
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * 2. Seed a default "General" department.
     *
     * Idempotency: skip if any department already exists.
     * -------------------------------------------------------------------------
     */
    $existingdept = $DB->get_record('local_moodesk_department', [], 'id', IGNORE_MULTIPLE);
    if (!$existingdept) {
        $dept               = new stdClass();
        $dept->name         = 'General';
        $dept->description  = null;
        $dept->isactive     = 1;
        $dept->timecreated  = time();
        $dept->timemodified = time();
        $dept->usermodified = 0; // 0 = system-seeded, no human actor.

        $DB->insert_record('local_moodesk_department', $dept);
    }

    /*
     * -------------------------------------------------------------------------
     * 3. Seed the "Helpdesk Agent" custom role.
     *
     * This role is the primary vehicle for granting agent access. Admins assign
     * it to support staff at System context; no manual capability setup is needed.
     *
     * Capabilities granted:
     * :agent     — marks the user as an assignable helpdesk agent
     * :viewall   — see all tickets in the queue
     * :reply     — post public replies
     * :addnote   — post internal notes
     * :manage    — change status, priority, assignee, category
     * :editkb    — draft and edit knowledge base articles (KB-01). Seeded here
     *              because on a fresh site there is nothing yet for its
     *              `clonepermissionsfrom` to copy; an upgraded site gets it
     *              from core cloning :reply instead. See db/access.php.
     *
     * Idempotency: if a role with shortname 'mhd_agent' already exists we do not
     * create another, and the config key is only written when it is missing.
     *
     * A role that already exists is one of two things, and the two get opposite
     * treatment:
     *
     *  - It survived an uninstall. Roles are site data and uninstalling the
     *    plugin rightly leaves them alone — but uninstall_plugin() does remove
     *    every role_capabilities row for a capability the plugin defined. So
     *    the role comes back holding no local/moodesk:* capability at all, and
     *    an install that skipped it here would report success and leave every
     *    agent locked out, silently. Such a role gets the defaults back.
     *  - It is the administrator's. A role holding at least one local/moodesk:*
     *    row — allowed, prevented or prohibited, at any context — has been
     *    configured on purpose, and its capabilities are not touched.
     *
     * The context-level restriction is applied only when the role is created:
     * where the role may be assigned is the administrator's to change.
     * -------------------------------------------------------------------------
     */
    $agentroleshortname = 'mhd_agent';
    $existingrole = $DB->get_record('role', ['shortname' => $agentroleshortname], 'id', IGNORE_MISSING);

    if ($existingrole) {
        $agentroleid = (int) $existingrole->id;

        if (!local_moodesk_install_role_holds_plugin_capabilities($agentroleid)) {
            local_moodesk_install_grant_agent_capabilities($agentroleid);
        }
    } else {
        $agentroleid = create_role(
            get_string('role_agent_name', 'local_moodesk'),
            $agentroleshortname,
            get_string('role_agent_description', 'local_moodesk')
        );

        // Restrict the role to System context so it cannot be accidentally
        // assigned at course or category level.
        set_role_contextlevels($agentroleid, [CONTEXT_SYSTEM]);

        local_moodesk_install_grant_agent_capabilities($agentroleid);
    }

    // Store the seeded role ID so other parts of the plugin can reference it
    // without a DB lookup (e.g. to surface a "Assign this role to agents" link
    // in the admin settings page).
    $configuredroleid = get_config('local_moodesk', 'agentroleid');
    if (empty($configuredroleid)) {
        set_config('agentroleid', $agentroleid, 'local_moodesk');
    }

    return true;
}

/**
 * The capabilities the "Helpdesk Agent" role is seeded with.
 *
 * @return string[] Capability names, all granted at System context.
 */
function local_moodesk_install_agent_capabilities(): array {
    return [
        'local/moodesk:agent',
        'local/moodesk:viewall',
        'local/moodesk:reply',
        'local/moodesk:addnote',
        'local/moodesk:manage',
        'local/moodesk:editkb',
    ];
}

/**
 * Whether a role holds any capability this plugin defines, in any state.
 *
 * "Any state" is deliberate: a capability set to prevent or prohibit is as much
 * an administrative decision as one set to allow, and the question this answers
 * is whether an administrator has ever configured the role, not whether the
 * role currently works. Any context counts for the same reason.
 *
 * @param int $roleid Role to inspect.
 * @return bool
 */
function local_moodesk_install_role_holds_plugin_capabilities(int $roleid): bool {
    global $DB;

    return $DB->record_exists_select(
        'role_capabilities',
        'roleid = :roleid AND ' . $DB->sql_like('capability', ':prefix'),
        ['roleid' => $roleid, 'prefix' => $DB->sql_like_escape('local/moodesk:') . '%']
    );
}

/**
 * Grant the seeded agent capabilities to a role at System context.
 *
 * The one place the defaults are written, whether the role was just created or
 * came back from an uninstall with nothing on it.
 *
 * @param int $roleid Role to grant them to.
 * @return void
 */
function local_moodesk_install_grant_agent_capabilities(int $roleid): void {
    $systemcontext = context_system::instance();

    foreach (local_moodesk_install_agent_capabilities() as $cap) {
        assign_capability($cap, CAP_ALLOW, $roleid, $systemcontext->id, true);
    }
}
