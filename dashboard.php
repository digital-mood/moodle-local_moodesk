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
 * Help Desk dashboard page.
 *
 * An operational support panel: KPIs, trends, SLA performance, workload and
 * activity, all driven by one set of global filters carried in the URL.
 *
 * WHAT A VIEWER SEES is decided here and enforced in the service:
 *  - viewall  → the full panel, with every filter available.
 *  - agent    → the same panel pinned to their own assigned tickets.
 *  - viewown  → a reduced panel covering the tickets they raised.
 *
 * The filter parameters are read but never trusted for scope: build_filter()
 * discards them for anyone but a manager, so a hand-edited URL cannot widen
 * what its owner is allowed to see.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\header_action;
use local_moodesk\output\page_header;
use local_moodesk\domain\dashboard_filter;
use local_moodesk\service\dashboard_service;
use local_moodesk\service\department_service;
use local_moodesk\service\edition_service;
use local_moodesk\service\ticket_service;

require_login();

$context = context_system::instance();

// Require at minimum the ability to see own tickets.
if (
    !has_capability('local/moodesk:viewown', $context)
        && !has_capability('local/moodesk:viewall', $context)
) {
    require_capability('local/moodesk:viewown', $context);
}

$ismanager = has_capability('local/moodesk:viewall', $context);
$isagent   = has_capability('local/moodesk:agent', $context);

// A requester is not an agent with no tickets — they are a different reader
// asking a different question. Conflating the two is why the old dashboard
// showed a requester "My assigned tickets: 0" and nothing else of use.
if ($ismanager) {
    $viewmode = dashboard_service::VIEW_MANAGER;
} else if ($isagent) {
    $viewmode = dashboard_service::VIEW_AGENT;
} else {
    $viewmode = dashboard_service::VIEW_REQUESTER;
}

/* ---- Filter parameters ------------------------------------------------------
 * Sentinel conventions match the ticket list, so the two screens read the same
 * way: -1 means "any", and 0 means "none set" where that is a real choice.
 */

$days = optional_param('days', dashboard_filter::RANGE_30, PARAM_INT);

$rawdepartment = optional_param('departmentid', -1, PARAM_INT);
$rawagent      = optional_param('agentid', -1, PARAM_INT);

$filterdepartment = ($rawdepartment >= 0) ? $rawdepartment : dashboard_filter::ANY;
$filteragent      = ($rawagent >= 0) ? $rawagent : dashboard_filter::ANY;

$service = new dashboard_service();
$filter  = $service->build_filter(
    $viewmode,
    (int) $USER->id,
    $days,
    $filterdepartment,
    $filteragent
);

// Before the plugin filters: their options are formatted, which needs a context.
$PAGE->set_context($context);

// Scope filters plugins add (hook dashboard_filters), for managers only: nobody
// else can change the scope. Only values other than their default travel.
$pluginfilterselects = [];
$plugincriteria = [];
if ($ismanager) {
    $pluginparamshook = \local_moodesk\hook\dashboard_filters::for_params(['days', 'departmentid', 'agentid', 'sesskey']);
    \core\di::get(\core\hook\manager::class)->dispatch($pluginparamshook);
    $plugincriteria = \local_moodesk\hook\filter_extension::read_values(
        $pluginparamshook->get_declared(),
        static fn(string $name, string $type, $default) => optional_param($name, $default, $type)
    );
    $filter = $filter->with_plugin_criteria($plugincriteria);

    $pluginoptionshook = \local_moodesk\hook\dashboard_filters::for_options($plugincriteria);
    \core\di::get(\core\hook\manager::class)->dispatch($pluginoptionshook);
    $pluginfilterselects = $pluginoptionshook->get_selects();
}

// Re-read from the filter rather than the request: an unsupported range falls
// back to the default, and the UI has to show what was actually applied.
$days = $filter->get_range_days() ?? dashboard_filter::RANGE_30;

/* ---- Page setup ------------------------------------------------------------- */

$PAGE->set_url(new moodle_url('/local/moodesk/dashboard.php'));
$PAGE->set_title(get_string('page_dashboard_title', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application shell
// on Moodle's embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'dashboard');

// Charts are an enhancement over markup that already renders every figure, so
// this is loaded unconditionally and simply finds nothing to upgrade when a
// panel has no data. Chart.js itself comes from Moodle core.
$PAGE->requires->js_call_amd('local_moodesk/dashboard_charts', 'init');

/* ---- Metrics ---------------------------------------------------------------- */

$metrics = $service->get_dashboard($filter, $viewmode);

/* ---- Filter options (managers only — nobody else can change the scope) ------- */

$rangeoptions = [];
foreach (dashboard_filter::valid_ranges() as $rangedays) {
    $rangeoptions[] = [
        'value'      => $rangedays,
        'label'      => get_string('dashboard_range_last_days', 'local_moodesk', $rangedays),
        'isselected' => ($days === $rangedays),
    ];
}

$departmentoptions = [];
$agentoptions      = [];

if ($ismanager) {
    foreach (department_service::create()->get_active_menu() as $deptid => $deptname) {
        $departmentoptions[] = [
            'id'         => $deptid,
            'name'       => $deptname,
            'isselected' => ($filterdepartment === (int) $deptid),
        ];
    }


    foreach (ticket_service::create()->get_assignable_agents((int) $USER->id) as $agentuser) {
        $agentoptions[] = [
            'id'         => (int) $agentuser->id,
            'fullname'   => fullname($agentuser),
            'isselected' => ($filteragent === (int) $agentuser->id),
        ];
    }
}

/* ---- Active filter chips ----------------------------------------------------
 * Each chip carries a URL that drops its own criterion and keeps the rest, so a
 * reader can peel a filter off without rebuilding the whole selection.
 */

$baseparams = array_filter([
    'days'         => $days,
    'departmentid' => ($filterdepartment !== dashboard_filter::ANY) ? $filterdepartment : null,
    'agentid'      => ($filteragent !== dashboard_filter::ANY) ? $filteragent : null,
] + $plugincriteria, static function ($value): bool {
    return $value !== null;
});

$chipurl = static function (string $drop) use ($baseparams): string {
    $params = $baseparams;
    unset($params[$drop]);

    return (new moodle_url('/local/moodesk/dashboard.php', $params))->out(false);
};

$labelfor = static function (array $options, int $needle, string $field): string {
    foreach ($options as $option) {
        if ((int) $option['id'] === $needle) {
            return (string) $option[$field];
        }
    }
    return '';
};

$chips = [];
if ($ismanager) {
    if ($filterdepartment !== dashboard_filter::ANY) {
        $chips[] = [
            'label' => ($filterdepartment === dashboard_filter::NONE)
                ? get_string('dept_none', 'local_moodesk')
                : $labelfor($departmentoptions, $filterdepartment, 'name'),
            'dismissurl' => $chipurl('departmentid'),
        ];
    }
    foreach (\local_moodesk\hook\filter_extension::active_selects($pluginfilterselects) as $select) {
        $chips[] = ['label' => $select['value'], 'dismissurl' => $chipurl($select['name'])];
    }
    if ($filteragent !== dashboard_filter::ANY) {
        $chips[] = [
            'label' => ($filteragent === dashboard_filter::NONE)
                ? get_string('unassigned', 'local_moodesk')
                : $labelfor($agentoptions, $filteragent, 'fullname'),
            'dismissurl' => $chipurl('agentid'),
        ];
    }
}

// A chip whose label could not be resolved refers to something the viewer can no
// longer see — an archived department, a departed agent. Dropping it beats
// rendering an empty chip that dismisses an invisible filter.
$chips = array_values(array_filter($chips, static function (array $chip): bool {
    return $chip['label'] !== '';
}));

/* ---- Template context -------------------------------------------------------- */

$templatectx = array_merge($metrics, [
    'is_manager'     => $ismanager,
    'can_filter'     => $ismanager,
    'cansubmit'      => has_capability('local/moodesk:submit', $context),
    'listurl'        => (new moodle_url('/local/moodesk/index.php'))->out(false),
    'submiturl'      => (new moodle_url('/local/moodesk/submit.php'))->out(false),
    'formaction'     => (new moodle_url('/local/moodesk/dashboard.php'))->out(false),
    'clearallurl'    => (new moodle_url('/local/moodesk/dashboard.php', ['days' => $days]))->out(false),
    'rangeoptions'   => $rangeoptions,
    'departmentoptions' => $departmentoptions,
    'hasdepartmentoptions' => !empty($departmentoptions),
    'agentoptions'   => $agentoptions,
    'hasagentoptions' => !empty($agentoptions),
    'pluginfilterselects' => $pluginfilterselects,
    'haspluginfilterselects' => !empty($pluginfilterselects),
    'chips'          => $chips,
    'haschips'       => !empty($chips),
    // Whether a scope criterion is applied at all, which is not the same as
    // whether a chip is showing: a chip whose label cannot be resolved is
    // dropped above, and the empty state still has to offer the way out.
    'hasfilters'     => $ismanager && (
        $filterdepartment !== dashboard_filter::ANY
        || $filteragent !== dashboard_filter::ANY
        || !empty($filter->get_plugin_criteria())
    ),
]);

// The page header. "View all tickets" is a way onward and "New ticket" is the
// commitment, so they go into the secondary and primary slots respectively —
// which is also what puts them in that order on the screen. They used to be
// spelled out the other way round, filled first.
$pageheader = (new page_header(get_string('page_dashboard_heading', 'local_moodesk')))
    ->with_action(new header_action(
        get_string('dashboard_view_all_tickets', 'local_moodesk'),
        new moodle_url('/local/moodesk/index.php'),
    ));
if ($templatectx['cansubmit']) {
    $pageheader->with_primary_action(header_action::create_new(
        get_string('ticket_new', 'local_moodesk'),
        new moodle_url('/local/moodesk/submit.php'),
        ['data-action' => 'mhd-open-new-ticket'],
    ));
}
$templatectx['pageheader'] = $pageheader->to_context();

// What plugins add (hook dashboard_panels): panels after or among the base's, and tiles.
$panelshook = new \local_moodesk\hook\dashboard_panels($filter, $viewmode, (int) $USER->id);
$templatectx['pluginpanels'] = $panelshook->dispatch_and_render($OUTPUT);
$templatectx['haspluginpanels'] = !empty($templatectx['pluginpanels']);
foreach (
    [
        \local_moodesk\hook\dashboard_panels::SLOT_AFTER_PRIORITY,
        \local_moodesk\hook\dashboard_panels::SLOT_BEFORE_AGING,
        \local_moodesk\hook\dashboard_panels::SLOT_BEFORE_ACTIVITY,
    ] as $slot
) {
    $templatectx['pluginpanels' . $slot] = $panelshook->render_slot($slot, $OUTPUT);
}
$templatectx['kpis'] = array_merge($templatectx['kpis'], $panelshook->get_kpis());

$content = $OUTPUT->render_from_template('local_moodesk/dashboard', $templatectx);

// New ticket drawer: same overlay as the ticket list. It posts to submit.php,
// which stays the POST target and the no-JS fallback.
$drawer = \local_moodesk\output\new_ticket_drawer::create();
if ($drawer !== null) {
    $content .= $drawer->render($PAGE);
}

echo $apppage->render_within_shell($content);
