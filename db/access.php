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
 * Capability definitions.
 *
 * Capability model (all evaluated at CONTEXT_SYSTEM):
 *
 *  Requester (any authenticated user):
 *    :submit    — open a new ticket
 *    :viewown   — view own tickets
 *
 *  Helpdesk Agent (custom role, no archetype default):
 *    :agent     — PRIMARY MARKER. Determines who is assignable to a ticket.
 *                 This is the sole capability checked by get_assignable_agents()
 *                 and is_agent(). A user without :agent cannot be assigned.
 *    :viewall   — view all tickets in the queue
 *    :reply     — post a public reply as support staff
 *    :addnote   — add an internal note visible only to agents
 *    :manage    — change status, priority, assignee, and category
 *
 *  Helpdesk Manager (custom role):
 *    :managecategory  — create / archive categories
 *    :managesettings  — manage plugin settings
 *
 * Risk bitmasks describe the surface, not the worst case. Moodle shows them to an
 * administrator in the role editor, so a bit that is not true of this plugin is a
 * false warning and costs the mask its meaning. What was checked, and what it
 * ruled out:
 *
 *  - **No RISK_XSS anywhere.** The ticket and reply composers are plain
 *    `textarea` elements, not Moodle's editor: the body is stored as typed and
 *    rendered through `format_text($message, FORMAT_PLAIN)`. A knowledge base
 *    article (KB-01) is the one place the plugin stores HTML, and it rests on
 *    a different guarantee: the body is kept exactly as authored (PARAM_RAW)
 *    and rendered through `format_text($body, FORMAT_HTML)` with neither
 *    `noclean` nor `trusttext`, so HTML Purifier stands between an author and
 *    every reader. Nothing in the tree passes either option —
 *    tests/kb_html_rendering_test.php fails the moment something does — so
 *    :editkb and :managekb do not carry the bit either. The day an article
 *    is rendered unclean, or an editor is configured with trusttext, the mask
 *    on :editkb becomes RISK_XSS and tests/db/access_test.php is what fails.
 *  - **RISK_SPAM follows the observers, not the wording.** :submit fires
 *    ticket_created (agents are mailed), :reply fires ticket_replied (the
 *    requester is mailed), :manage reassigns and fires ticket_assigned, and an
 *    automation rule reaches all three on many tickets at once. :addnote is
 *    deliberately **not** spam: observer::ticket_replied returns early for
 *    anything that is not TYPE_PUBLIC_REPLY, so an internal note notifies nobody.
 *  - **RISK_PERSONAL follows reach.** :viewall and :manage both satisfy
 *    ticket_access_policy::can_view(), so both read other people's tickets.
 *    :manageagents gates
 *    agent_supervision.php (individual response and resolution times).
 *
 * Seven capabilities declare `0` on purpose — see tests/db/access_test.php, which
 * requires the key on every capability so a new one cannot arrive without a
 * decision, and pins the masks that were reasoned about.
 *
 *  Knowledge base (KB-01):
 *    :viewkb    — read published articles. Every authenticated user.
 *    :editkb    — create and edit articles, drafts and published alike.
 *                 Reaches existing roles by cloning :reply (see below).
 *    :managekb  — publish, unpublish, archive, restore, delete, and change
 *                 who an article is for. Managers.
 *
 * Setup guide for administrators:
 *   The "Helpdesk Agent" role (shortname: mhd_agent) is created automatically
 *   during installation by db/install.php with all six agent capabilities
 *   pre-assigned at System context. Admins only need to assign the role to
 *   their support staff — no manual capability configuration is required.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    /*
     * -------------------------------------------------------------------------
     * Requester capabilities — granted to all authenticated users by default.
     * -------------------------------------------------------------------------
     */

    // Open a new ticket.
    'local/moodesk:submit' => [
        'riskbitmask'  => RISK_SPAM,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user' => CAP_ALLOW,
        ],
    ],

    // View the requester's own tickets.
    'local/moodesk:viewown' => [
        'riskbitmask'  => 0,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user' => CAP_ALLOW,
        ],
    ],

    /*
     * -------------------------------------------------------------------------
     * Agent capabilities — no archetype defaults.
     * Must be explicitly granted to a custom "Helpdesk Agent" role.
     * -------------------------------------------------------------------------
     */

    // PRIMARY AGENT MARKER.
    // get_assignable_agents() and is_agent() check this capability exclusively.
    // A user who holds :agent is considered an assignable helpdesk agent.
    // Site admins are NOT included unless :agent is explicitly granted to them
    // ($doanything = false is used in all capability checks for this cap).
    'local/moodesk:agent' => [
        'riskbitmask'  => 0,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // View all tickets in the helpdesk queue, regardless of ownership.
    'local/moodesk:viewall' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Post a public reply to any ticket.
    'local/moodesk:reply' => [
        'riskbitmask'  => RISK_SPAM,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Add an internal note (not visible to the requester).
    'local/moodesk:addnote' => [
        'riskbitmask'  => 0,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Change ticket status, priority, assignee, and category.
    'local/moodesk:manage' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_SPAM,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    /*
     * -------------------------------------------------------------------------
     * Manager / Admin capabilities — granted to the 'manager' archetype.
     * -------------------------------------------------------------------------
     */

    // Create and archive ticket categories.
    'local/moodesk:managecategory' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Manage plugin-level settings (default category, attachments, etc.).
    'local/moodesk:managesettings' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Phase 3: Create, edit, and archive departments; manage department membership.
    'local/moodesk:managedepartment' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Read-only access to the System Health page. Separate from :managesettings
    // because seeing that ingestion has stopped is not the same authority as
    // changing how the plugin runs — a support lead should be able to look
    // without being handed the settings tree. Acting on what they see
    // (acknowledge, resolve) does require :managesettings.
    'local/moodesk:viewhealth' => [
        'riskbitmask'  => 0,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Phase 11.E: Create, edit, and archive agent teams; manage team membership.
    // Agents do not get this by default — team management is a manager/admin concern.
    // Agent Management (A0): open, configure and offboard support agents from
    // inside mooDesk instead of Moodle's role-assignment back-office. Gates the
    // in-shell Agents directory. This is a curated layer *over* Moodle roles —
    // agent status itself stays a capability (:agent), and this cap only lets a
    // holder grant/revoke the seeded 'mhd_agent' role, never escalate to manager
    // or admin. Manager/admin concern, so no agent default.
    'local/moodesk:manageagents' => [
        'riskbitmask'  => RISK_MANAGETRUST | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    /*
     * -------------------------------------------------------------------------
     * Knowledge base (KB-01).
     *
     * Who may see a given article is decided by
     * \local_moodesk\policy\kb_access_policy and nowhere else: these three say
     * what a holder may *do*, the policy says which rows that applies to. A
     * draft is never visible to anyone without :editkb or :managekb, whatever
     * else they hold.
     * -------------------------------------------------------------------------
     */

    // Read published articles. The reader's capability, and the one every
    // list, search and file-serving path is filtered through.
    'local/moodesk:viewkb' => [
        'riskbitmask'  => 0,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'user' => CAP_ALLOW,
        ],
    ],

    // Create and edit articles — drafts and published alike — but not publish
    // them. The mask is 0 because the body is cleaned on render (see the file
    // header); it becomes RISK_XSS the day that stops being true.
    //
    // No archetype: it is an agent capability, and agents are a custom role.
    // `clonepermissionsfrom` is how it reaches roles that already exist. When
    // update_capabilities() registers a new capability carrying the key, core
    // copies every role_capabilities row of the named capability onto it —
    // role, context and permission, allow / prevent / prohibit alike — and
    // ignores `archetypes`. :reply is the source because it is the closest
    // existing statement of "writes content a requester will read"; a role
    // built to see tickets without answering them does not draft articles
    // either. On a fresh install nothing exists to clone from yet, so
    // db/install.php seeds it on the agent role directly.
    'local/moodesk:editkb' => [
        'riskbitmask'          => 0,
        'captype'              => 'write',
        'contextlevel'         => CONTEXT_SYSTEM,
        'archetypes'           => [],
        'clonepermissionsfrom' => 'local/moodesk:reply',
    ],

    // Publish, unpublish, archive, restore and delete articles, and change who
    // an article is for. Implies :editkb inside the policy — a manager who may
    // publish an article may also write one. Not RISK_SPAM: publishing sends
    // nothing to anybody. Not RISK_CONFIG: an article is content, not
    // configuration.
    'local/moodesk:managekb' => [
        'riskbitmask'  => 0,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

];
