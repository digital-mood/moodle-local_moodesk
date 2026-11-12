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
 * Ticket list page.
 *
 * Agent view (viewall): tabbed workspace — All / My Tickets / Unassigned / My Departments.
 * Requester view (viewown): own tickets with basic status filter.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\header_action;
use local_moodesk\output\page_header;
use local_moodesk\output\ticket_list_query;
use local_moodesk\output\ticket_list_results;
use local_moodesk\service\ticket_service;
use local_moodesk\service\category_service;
use local_moodesk\service\department_service;
use local_moodesk\service\edition_service;
use local_moodesk\service\saved_view_service;
use local_moodesk\domain\ticket;
use local_moodesk\domain\department;
use local_moodesk\util\url_params;

require_login();

$context = context_system::instance();

if (
    !has_capability('local/moodesk:viewall', $context)
        && !has_capability('local/moodesk:viewown', $context)
) {
    require_capability('local/moodesk:viewown', $context);
}

$isagent = has_capability('local/moodesk:viewall', $context);

// Bulk and quick actions post to bulk_action.php, which requires :manage. Reading
// the list is :viewall, so an agent can legitimately hold one and not the other —
// the controls follow the capability that decides whether the request will be
// accepted, not the one that decided the page could be seen.
$canbulk = has_capability('local/moodesk:manage', $context);

// Everything this list is filtered, sorted and paginated by, read once into a
// value object. The fragment that refreshes the results region builds the same
// object out of the same query string through the same cleaning, so the page and
// every refresh after it cannot disagree about what the current query is.
$query = ticket_list_query::from_request($isagent);

// The filter bar and the active chips below are written against these names, and
// read the same values the query holds. Aliased rather than reworked: what this
// change is for is the results region, and the filter controls are not it.
$page              = $query->get_page();
$view              = $query->get_view();
$filtersearch      = $query->get_search();
$filterstatuses    = $query->get_statuses();
$filterpriority    = $query->get_priority();
$filtercategory    = $query->get_category_id();
$filterdepartment  = $query->get_department_id();
$filterassignee    = $query->get_assignee_id();
$filtersla         = $query->get_sla();
$filtercreatedfrom = $query->get_created_from();
$filtercreatedto   = $query->get_created_to();
$filterupdatedfrom = $query->get_updated_from();
$filterupdatedto   = $query->get_updated_to();
$sort              = $query->get_sort();
$morefiltersactive = $query->count_secondary_filters();

// Status and priority label maps — used by the filter selects and the chips.
$statuslabels   = ticket::status_labels();
$prioritylabels = ticket::priority_labels();

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/index.php'));
$PAGE->set_title(get_string('page_list_title', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'tickets');

if ($canbulk) {
    $bulkactionurl = (new moodle_url('/local/moodesk/bulk_action.php'))->out(false);
    $PAGE->requires->js_call_amd(
        'local_moodesk/bulk_actions',
        'init',
        [$bulkactionurl, sesskey(), ticket_service::BULK_CONFIRM_FROM]
    );
    $PAGE->requires->js_call_amd('local_moodesk/ticket_list_actions', 'init', [$bulkactionurl, sesskey(), $USER->id]);
}

if ($isagent) {
    $ajaxurl = (new moodle_url('/local/moodesk/ajax.php'))->out(false);
    $PAGE->requires->js_call_amd('local_moodesk/filters', 'init', [
        $ajaxurl,
        sesskey(),
        get_string('filter_date_range_invalid', 'local_moodesk'),
    ]);
}

// The results region refreshes itself in place when a mutation elsewhere on the
// screen may have changed what this query returns — a property saved from the
// ticket detail drawer, or the page coming back out of the browser's back/forward
// cache after a ticket was raised. It re-runs the query on the server rather than
// deciding anything about the rows itself.
$PAGE->requires->js_call_amd('local_moodesk/ticket_list', 'init', [
    \context_system::instance()->id,
    get_string('list_refresh_failed', 'local_moodesk'),
    get_string('list_ticket_left_view', 'local_moodesk'),
]);

// The results: rows, total and pagination. Owned by ticket_list_results, which
// the ticket_list_results fragment renders too — this page must not build them a
// second way, or the initial paint and a refresh could drift apart.
$service = ticket_service::create();
$results = ticket_list_results::create($query, $USER->id, $canbulk);

/*
 * -------------------------------------------------------------------------
 * Build filter select data for the filter bar.
 * -------------------------------------------------------------------------
 */

// Status options (multi-select — isselected reflects the active array).
$statusselectopts = [];
foreach ($statuslabels as $sval => $slabel) {
    $statusselectopts[] = [
        'value'      => $sval,
        'label'      => $slabel,
        'isselected' => in_array($sval, $filterstatuses, true),
    ];
}

// Priority options (agents only).
$priorityselectopts = [];
if ($isagent) {
    foreach ($prioritylabels as $pval => $plabel) {
        $priorityselectopts[] = [
            'value'      => $pval,
            'label'      => $plabel,
            'isselected' => ($filterpriority === $pval),
        ];
    }
}

// Category options.
$catservice         = category_service::create();
$catmenu            = $catservice->get_active_menu();
$categoryselectopts = [];
foreach ($catmenu as $catid => $catname) {
    $categoryselectopts[] = [
        'id'         => $catid,
        'name'       => $catname,
        'isselected' => ($filtercategory === $catid),
    ];
}

// Department options (agents only).
$deptselectopts = [];
if ($isagent) {
    $deptservice    = department_service::create();
    $deptmenu       = $deptservice->get_active_menu();
    foreach ($deptmenu as $deptid => $deptname) {
        $deptselectopts[] = [
            'id'         => $deptid,
            'name'       => $deptname,
            'isselected' => ($filterdepartment === $deptid),
        ];
    }
}

// Filter controls plugins add (hook ticket_list_filters, options phase).
$pluginfilterselects = [];
if ($isagent) {
    $pluginfilterhook = \local_moodesk\hook\ticket_list_filters::for_options($query->get_plugin_values());
    \core\di::get(\core\hook\manager::class)->dispatch($pluginfilterhook);
    $pluginfilterselects = $pluginfilterhook->get_selects();
}

// Build tab URLs for the agent workspace.
$baseurl = new moodle_url('/local/moodesk/index.php');
$tabs = [];
if ($isagent) {
    $tabdefs = [
        department::VIEW_ALL        => get_string('view_all', 'local_moodesk'),
        department::VIEW_MINE       => get_string('view_mine', 'local_moodesk'),
        department::VIEW_UNASSIGNED => get_string('view_unassigned', 'local_moodesk'),
        department::VIEW_DEPARTMENT => get_string('view_department', 'local_moodesk'),
    ];
    foreach ($tabdefs as $tabview => $tablabel) {
        $taburl = new moodle_url('/local/moodesk/index.php', ['view' => $tabview]);
        $tabs[] = [
            'label'    => $tablabel,
            'url'      => $taburl->out(false),
            'isactive' => ($view === $tabview),
        ];
    }
}

// Build bulk agents list for the assign dropdown.
// filterassignees reuses the same result — single DB call for both.
//
// Gated on :manage because get_assignable_agents() requires it: an agent who
// could read the queue but not manage it used to reach this call and take an
// uncaught required_capability_exception, so the list page did not render at
// all for them. The assignee filter goes with it rather than growing a second
// query for a viewer who, until now, could not load the page to use it.
$bulkagents      = [];
$filterassignees = [];
if ($canbulk) {
    $agentusers = $service->get_assignable_agents($USER->id);
    foreach ($agentusers as $au) {
        $agentfullname   = fullname($au);
        $bulkagents[]    = [
            'id'       => (int) $au->id,
            'fullname' => $agentfullname,
        ];
        $filterassignees[] = [
            'id'         => (int) $au->id,
            'fullname'   => $agentfullname,
            'isselected' => ($filterassignee === (int) $au->id),
        ];
    }
}

/*
 * -------------------------------------------------------------------------
 * PRO-1: Active filter chips — one chip per active filter with a dismiss URL.
 * Each dismiss URL is the current page URL with that specific param removed.
 * -------------------------------------------------------------------------
 */

$filterchips = [];

// Every filter currently in force, as URL parameters — the chips dismiss out of
// this, and the results region publishes it as its own address. Built by the
// query object so the page, the chips and a refresh all read one definition of
// "the filters in force".
$activeparams = $query->to_active_params();

// Build one chip per active filter with a dismiss URL (all params minus this one).
if (!empty(trim($filtersearch))) {
    $dismissparams = $activeparams;
    unset($dismissparams['search']);
    $filterchips[] = [
        'label'      => get_string('filter_active_search', 'local_moodesk', s($filtersearch)),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
// One chip per selected status; dismissing a chip removes just that value.
if ($isagent && !empty($filterstatuses)) {
    foreach ($filterstatuses as $sval) {
        $dismissparams = url_params::without_value($activeparams, 'status', $sval);
        $filterchips[] = [
            'label'      => get_string('filter_active_status', 'local_moodesk', $statuslabels[$sval] ?? $sval),
            'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
        ];
    }
}
if ($isagent && $filterpriority >= 0) {
    $dismissparams = $activeparams;
    unset($dismissparams['priority']);
    $filterchips[] = [
        'label'      => get_string('filter_active_priority', 'local_moodesk', $prioritylabels[$filterpriority] ?? $filterpriority),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filtercategory > 0) {
    $dismissparams = $activeparams;
    unset($dismissparams['categoryid']);
    $catname = '';
    foreach ($categoryselectopts as $opt) {
        if ($opt['id'] === $filtercategory) {
            $catname = $opt['name'];
            break;
        }
    }
    $filterchips[] = [
        'label'      => get_string('filter_active_category', 'local_moodesk', $catname),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filterdepartment >= 0) {
    $dismissparams = $activeparams;
    unset($dismissparams['departmentid']);
    $deptname = '';
    foreach ($deptselectopts as $opt) {
        if ($opt['id'] === $filterdepartment) {
            $deptname = $opt['name'];
            break;
        }
    }
    $filterchips[] = [
        'label'      => get_string('filter_active_department', 'local_moodesk', $deptname),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filterassignee >= 0) {
    $dismissparams  = $activeparams;
    unset($dismissparams['assigneeid']);
    $assigneelabel = '';
    foreach ($filterassignees as $ao) {
        if ($ao['id'] === $filterassignee) {
            $assigneelabel = $ao['fullname'];
            break;
        }
    }
    $filterchips[] = [
        'label'      => get_string('filter_active_assignee', 'local_moodesk', $assigneelabel),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
foreach (\local_moodesk\hook\filter_extension::active_selects($pluginfilterselects) as $select) {
    $dismissparams = $activeparams;
    unset($dismissparams[$select['name']]);
    $filterchips[] = [
        'label'      => get_string('filter_active_plugin', 'local_moodesk', (object) [
            'label' => $select['label'],
            'value' => $select['value'],
        ]),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filtercreatedfrom !== '') {
    $dismissparams = $activeparams;
    unset($dismissparams['created_from']);
    $filterchips[] = [
        'label'      => get_string('filter_active_created_from', 'local_moodesk', s($filtercreatedfrom)),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filtercreatedto !== '') {
    $dismissparams = $activeparams;
    unset($dismissparams['created_to']);
    $filterchips[] = [
        'label'      => get_string('filter_active_created_to', 'local_moodesk', s($filtercreatedto)),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filterupdatedfrom !== '') {
    $dismissparams = $activeparams;
    unset($dismissparams['updated_from']);
    $filterchips[] = [
        'label'      => get_string('filter_active_updated_from', 'local_moodesk', s($filterupdatedfrom)),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filterupdatedto !== '') {
    $dismissparams = $activeparams;
    unset($dismissparams['updated_to']);
    $filterchips[] = [
        'label'      => get_string('filter_active_updated_to', 'local_moodesk', s($filterupdatedto)),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filtersla === 1) {
    $dismissparams = $activeparams;
    unset($dismissparams['sla']);
    $filterchips[] = [
        'label'      => get_string('filter_active_sla', 'local_moodesk'),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $filtersla === 2) {
    $dismissparams = $activeparams;
    unset($dismissparams['sla']);
    $filterchips[] = [
        'label'      => get_string('filter_active_sla_warning', 'local_moodesk'),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}
if ($isagent && $sort === 'sla') {
    $dismissparams = $activeparams;
    unset($dismissparams['sort']);
    $filterchips[] = [
        'label'      => get_string('sort_by_sla_active', 'local_moodesk'),
        'dismissurl' => url_params::to_url('/local/moodesk/index.php', $dismissparams)->out(false),
    ];
}

// Saved views — stored and shaped by saved_view_service, which ajax.php also
// writes through, so a view applies exactly the filters it was saved with.
$savedviews = [];
if ($isagent) {
    foreach (saved_view_service::all_for((int) $USER->id) as $viewdata) {
        $savedviews[] = [
            // Raw: the template's {{name}} escapes it, and s() here made it twice.
            'name' => (string) $viewdata['name'],
            'url'  => url_params::to_url('/local/moodesk/index.php', $viewdata['params'])->out(false),
            'key'  => $viewdata['key'],
        ];
    }
}

// Clear-all URL: resets all filters, keeps only the current tab view.
$clearallurl = (new moodle_url('/local/moodesk/index.php', ['view' => $view]))->out(false);

// The URL the viewer is standing on, rebuilt from the same parameters the filter
// chips and the pagination bar already travel on. A bulk action posts this back
// so the redirect lands on the same filters, page and view the agent was
// triaging, instead of a bare list. Page zero and an empty sort are the
// defaults, so they are left out rather than spelled out.
$returnparams = $activeparams;
if ($page > 0) {
    $returnparams['page'] = $page;
}
if ($sort !== '') {
    $returnparams['sort'] = $sort;
}
$returnurl = url_params::to_url('/local/moodesk/index.php', $returnparams)->out(false);

$templatectx = [
    'isagent'               => $isagent,
    'canbulk'               => $canbulk,
    'tabs'                  => $tabs,
    'hastabs'               => !empty($tabs),
    'currentview'           => $view,
    // Filter bar data.
    'filtersearch'          => $filtersearch,
    'filterstatuses'        => $statusselectopts,
    'filterpriorities'      => $priorityselectopts,
    'filtercategories'      => $categoryselectopts,
    'hasfiltercategories'   => !empty($categoryselectopts),
    'filterdepartments'     => $deptselectopts,
    'hasfilterdepartments'  => !empty($deptselectopts),
    'filterassignees'       => $filterassignees,
    'hasfilterassignees'    => !empty($filterassignees),
    'pluginfilterselects'   => $pluginfilterselects,
    'haspluginfilterselects' => !empty($pluginfilterselects),
    'filtersla'             => $filtersla,
    'filtersla_breach'      => ($filtersla === 1),
    'filtersla_warning'     => ($filtersla === 2),
    'filtercreatedfrom'     => $filtercreatedfrom,
    'filtercreatedto'       => $filtercreatedto,
    'filterupdatedfrom'     => $filterupdatedfrom,
    'filterupdatedto'       => $filterupdatedto,
    'filterformurl'         => (new moodle_url('/local/moodesk/index.php'))->out(false),
    // Bulk actions (:manage only).
    'bulkactionurl'         => $canbulk ? (new moodle_url('/local/moodesk/bulk_action.php'))->out(false) : '',
    'sesskey'               => $canbulk ? sesskey() : '',
    'bulkagents'            => $bulkagents,
    'hasbulkagents'         => !empty($bulkagents),
    'returnurl'             => $canbulk ? $returnurl : '',
    // PRO-1: Quick actions.
    'currentuserid'         => $canbulk ? (int) $USER->id : 0,
    // PRO-1: Active filter chips.
    'activefilterchips'     => $filterchips,
    'hasactivefilters'      => !empty($filterchips),
    // Secondary filters: how many of the six behind the disclosure carry a
    // value. On a phone that group starts folded, so without this count an
    // active filter would narrow the list with nothing on screen to explain it.
    'morefilterscount'      => $morefiltersactive,
    'hasmorefiltersactive'  => $morefiltersactive > 0,
    'clearallurl'           => $clearallurl,
    // Saved views.
    'savedviews'            => $savedviews,
    'hassavedviews'         => !empty($savedviews),
];

// The page header. `data-action` is passed through the action's attributes, so
// the control is still the plain link to submit.php that ticket_drawer.js
// upgrades into the drawer trigger — the progressive enhancement is unchanged,
// only the markup around it moved.
$templatectx['pageheader'] = (new page_header(get_string('page_list_heading', 'local_moodesk')))
    ->with_primary_action(header_action::create_new(
        get_string('ticket_new', 'local_moodesk'),
        new moodle_url('/local/moodesk/submit.php'),
        ['data-action' => 'mhd-open-new-ticket'],
    ))
    ->to_context();

// The results region's own context, merged in so the partial renders from the
// component that owns it. Merged rather than nested because a Mustache partial
// reads the context it is included in.
$templatectx += $results->get_context();

$content = $OUTPUT->render_from_template('local_moodesk/ticket_list', $templatectx);

// New ticket drawer: "New ticket" opens an overlay over the list instead of
// navigating away. The panel posts to submit.php, which stays the POST target
// and the no-JS fallback, so nothing about the submission flow changes here.
$drawer = \local_moodesk\output\new_ticket_drawer::create();
if ($drawer !== null) {
    $content .= $drawer->render($PAGE);
}

// Ticket detail drawer: rows open the detail over the list. The panel ships
// empty and pulls the detail fragment on demand; every row stays a real link to
// view.php?id=N, which remains the canonical page and the no-JS path.
$content .= (new \local_moodesk\output\ticket_detail_drawer())->render($PAGE);

echo $apppage->render_within_shell($content);
