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
 * Agent management — directory (Agent Management A0).
 *
 * The in-shell roster that replaces the deep link into Moodle's role screens.
 * Read-only in A0: onboarding (A2), offboarding (A3) and per-agent editing (A4)
 * are layered on top in later phases. Available in every edition; screen-level
 * edition gating for Pro/Enterprise features arrives with A4/A5.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\header_action;
use local_moodesk\output\page_header;
use local_moodesk\service\agent_service;
use local_moodesk\service\department_service;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:manageagents', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/agents.php'));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('agents_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application shell).
$apppage = new \local_moodesk\output\app_page($PAGE, 'agents');

/*
 * -------------------------------------------------------------------------
 * Read filters (A1).
 * -------------------------------------------------------------------------
 */

$search       = trim(optional_param('search', '', PARAM_TEXT));
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$status       = optional_param('status', '', PARAM_ALPHA);       // Values: '' | active | suspended.
$management   = optional_param('management', '', PARAM_ALPHA);   // Values: '' | managed | unmanaged.

// Filters and a column plugins add (hook agent_directory_filters).
$pluginparamshook = \local_moodesk\hook\agent_directory_filters::for_params(
    ['search', 'departmentid', 'status', 'management', 'sesskey']
);
\core\di::get(\core\hook\manager::class)->dispatch($pluginparamshook);
$pluginvalues = \local_moodesk\hook\filter_extension::read_values(
    $pluginparamshook->get_declared(),
    static fn(string $name, string $type, $default) => optional_param($name, $default, $type)
);
$pluginoptionshook = \local_moodesk\hook\agent_directory_filters::for_options($pluginvalues);
\core\di::get(\core\hook\manager::class)->dispatch($pluginoptionshook);
$groupscolumn = $pluginoptionshook->get_groups_column();

$filters = array_filter([
    'search'       => $search,
    'departmentid' => $departmentid,
    'plugin'       => $pluginvalues,
    'status'       => $status,
    'management'   => $management,
]);
$hasfilters = !empty($filters);

/*
 * -------------------------------------------------------------------------
 * Load roster (full for totals/attention, filtered for display).
 * -------------------------------------------------------------------------
 */

$service   = agent_service::create();
$allagents = $service->list_agents($USER->id);
$agents    = $hasfilters ? $service->list_agents($USER->id, $filters) : $allagents;

// A plugin may offer a page per agent; the directory only links it.
$detaillink = new \local_moodesk\hook\agent_detail_link();
\core\di::get(\core\hook\manager::class)->dispatch($detaillink);
$detailurl = $detaillink->get_url();

$nomembershipcount = count(array_filter($allagents, static fn($a) => $a->nomembership));
// What plugin groups are called, so the membership texts name them too.
$grouptype         = $service->group_type();
$emptydepts        = $service->get_departments_without_members($USER->id);

/*
 * -------------------------------------------------------------------------
 * Build rows.
 * -------------------------------------------------------------------------
 */

$rows = [];
foreach ($agents as $agent) {
    // The entity cell's figure. Rendered here rather than in the service: the
    // service hands over the user record, and turning one into markup needs a
    // renderer. `link => false` because the row's own Detail button owns that
    // navigation — a second route into the same screen, hidden behind a
    // portrait, is not a second affordance, it is a surprise.
    $userpic       = new user_picture($agent->user);
    $userpic->size = 36;
    $userpic->link = false;

    $rows[] = [
        'fullname'     => $agent->fullname,
        'email'        => $agent->email,
        'avatar'       => $OUTPUT->render($userpic),
        'managed'      => $agent->managed,
        'managedlabel' => $agent->managed
            ? get_string('agents_managed_moodesk', 'local_moodesk')
            : get_string('agents_managed_moodle', 'local_moodesk'),
        'departments'  => $agent->departments,
        'hasdepartments' => !empty($agent->departments),
        'groups'       => $agent->groups,
        'hasgroups'    => !empty($agent->groups),
        'groupsempty'  => $groupscolumn['empty'] ?? '',
        'nomembership' => $agent->nomembership,
        'openworkload' => $agent->openworkload,
        'suspended'    => $agent->suspended,
        'statuslabel'  => $agent->suspended
            ? get_string('agents_status_suspended', 'local_moodesk')
            : get_string('agents_status_active', 'local_moodesk'),
        // Offboard: only managed agents can be removed from mooDesk (A3).
        'offboardurl'  => (new moodle_url('/local/moodesk/offboard_agent.php', ['userid' => $agent->id]))->out(false),
        'detailurl'    => $detailurl !== null
            ? (new moodle_url($detailurl, ['userid' => $agent->id]))->out(false)
            : null,
    ];
}

/*
 * -------------------------------------------------------------------------
 * Filter dropdown options.
 * -------------------------------------------------------------------------
 */

$deptoptions = [];
foreach (department_service::create()->get_active_menu() as $id => $name) {
    $deptoptions[] = ['id' => $id, 'name' => format_string($name), 'selected' => ($id === $departmentid)];
}

$statusoptions = [
    [
        'value' => 'active',
        'label' => get_string('agents_status_active', 'local_moodesk'),
        'selected' => ($status === 'active'),
    ],
    [
        'value' => 'suspended',
        'label' => get_string('agents_status_suspended', 'local_moodesk'),
        'selected' => ($status === 'suspended'),
    ],
];
$managementoptions = [
    [
        'value' => 'managed',
        'label' => get_string('agents_managed_moodesk', 'local_moodesk'),
        'selected' => ($management === 'managed'),
    ],
    [
        'value' => 'unmanaged',
        'label' => get_string('agents_managed_moodle', 'local_moodesk'),
        'selected' => ($management === 'unmanaged'),
    ],
];

$templatectx = [
    'agents'       => $rows,
    'hasagents'    => !empty($rows),
    'total'        => count($allagents),
    'hastotal'     => !empty($allagents),
    'shown'        => count($rows),
    'hasfilters'   => $hasfilters,
    // Filter bar.
    'listurl'      => (new moodle_url('/local/moodesk/agents.php'))->out(false),
    'search'       => $search,
    'deptoptions'  => $deptoptions,
    'pluginfilterselects' => $pluginoptionshook->get_selects(),
    'hasgroupscolumn'     => $groupscolumn !== null,
    'groupslabel'         => $groupscolumn['label'] ?? '',
    'statusoptions'     => $statusoptions,
    'managementoptions' => $managementoptions,
    // Attention states.
    'emptydepts'        => $emptydepts,
    'hasemptydepts'     => !empty($emptydepts),
    'nomembershipcount' => $nomembershipcount,
    'hasnomembership'   => $nomembershipcount > 0,
    'nomembershiptext'  => agent_service::membership_text('agents_attention_nomembership', $grouptype, $nomembershipcount),
    'nomembershiphint'  => agent_service::membership_text('agents_attention_nomembership_hint', $grouptype),
    // The empty state's invitation. Not a header action: it keeps its label,
    // because an empty state is where the product explains what to do next and
    // a lone glyph explains nothing.
    'addurl'    => (new moodle_url('/local/moodesk/add_agent.php'))->out(false),
];

// The page header. Built here rather than spelled out in the template: the slot
// an action goes into is what decides its emphasis and its position, so "Add
// agent is the primary action and sits at the far right" is a property of this
// call, not a class anyone has to remember. Before the component, this screen
// put the filled button to the LEFT of the outlined one — and inverted a second
// time below 576px, where its own `flex-column` stacked the pair with the filled
// one on top.
$templatectx['pageheader'] = (new page_header(get_string('agents_heading', 'local_moodesk')))
    ->with_description(get_string('agents_intro', 'local_moodesk'))
    ->with_action(new header_action(
        get_string('agents_manage_in_moodle', 'local_moodesk'),
        new moodle_url('/admin/roles/manage.php'),
        null,
        ['target' => '_blank', 'rel' => 'noopener'],
    ))
    ->with_primary_action(header_action::create_new(
        get_string('agents_add_heading', 'local_moodesk'),
        new moodle_url('/local/moodesk/add_agent.php'),
    ))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/admin_agents', $templatectx)
);
