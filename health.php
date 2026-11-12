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
 * System Health — what the plugin absorbed and never told anyone about.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\page_header;
use local_moodesk\output\health_page;
use local_moodesk\service\incident_service;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:viewhealth', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/health.php'));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('health_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'health');

$pageurl = (new moodle_url('/local/moodesk/health.php'))->out(false);

/*
 * -------------------------------------------------------------------------
 * Handle POST: acknowledge / resolve.
 *
 * Viewing is :viewhealth; acting is :managesettings. Seeing that ingestion has
 * stopped is not the same authority as declaring it handled, and the check is
 * repeated here rather than trusted to the button being hidden — the template
 * omits the controls without the capability, but a form post does not care
 * what was rendered.
 * -------------------------------------------------------------------------
 */

$action = optional_param('action', '', PARAM_ALPHA);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    require_capability('local/moodesk:managesettings', $context);

    $incidentid = required_param('incidentid', PARAM_INT);
    $service    = incident_service::create();

    $msg  = '';
    $kind = \core\output\notification::NOTIFY_SUCCESS;

    if ($action === 'acknowledge') {
        $done = $service->acknowledge($incidentid, (int) $USER->id);
        $msg  = $done
            ? get_string('health_acknowledged', 'local_moodesk')
            : get_string('health_incident_missing', 'local_moodesk');
        $kind = $done ? $kind : \core\output\notification::NOTIFY_WARNING;
    } else if ($action === 'resolve') {
        $done = $service->resolve($incidentid, (int) $USER->id);
        $msg  = $done
            ? get_string('health_resolved', 'local_moodesk')
            : get_string('health_incident_missing', 'local_moodesk');
        $kind = $done ? $kind : \core\output\notification::NOTIFY_WARNING;
    }

    // Redirect after POST so a refresh cannot re-run the action.
    redirect(new moodle_url('/local/moodesk/health.php'), $msg, null, $kind);
}

$templatectx = (new health_page())->export($pageurl);

// The page header. The state pill goes into the meta slot, not the action slots:
// it is what the page reports, not something the user can do, and a pill sitting
// where a button sits is a pill people try to click. The window note used to be
// a paragraph under the header; it is the header's description, which is what it
// always was.
$templatectx['pageheader'] = (new page_header(get_string('health_heading', 'local_moodesk')))
    ->with_description(get_string('health_window_note', 'local_moodesk', $templatectx['windowhours']))
    ->with_meta(html_writer::span(
        $templatectx['statelabel'],
        'mhd-pill mhd-pill--' . $templatectx['statevariant']
    ))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/admin_health', $templatectx)
);
