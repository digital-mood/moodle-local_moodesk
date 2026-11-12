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
 * Department administration — list view.
 *
 * Lists all departments (active and archived) with links to edit, manage
 * members, and toggle active/archived state. No physical deletion.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\header_action;
use local_moodesk\output\page_header;
use local_moodesk\service\department_service;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:managedepartment', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/departments.php'));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('dept_admin_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'departments');

$deptservice = department_service::create();

/*
 * -------------------------------------------------------------------------
 * Handle POST: toggle active / archived.
 * No physical deletion — departments may be referenced by existing tickets.
 * -------------------------------------------------------------------------
 */

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $deptid = required_param('deptid', PARAM_INT);
    $dept   = $deptservice->get_department($deptid, $USER->id);

    $update           = new stdClass();
    $update->isactive = (int) $dept->isactive ? 0 : 1;
    $deptservice->update_department($deptid, $update, $USER->id);

    redirect(
        new moodle_url('/local/moodesk/departments.php'),
        get_string('department_updated', 'local_moodesk'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/*
 * -------------------------------------------------------------------------
 * Build template context.
 * -------------------------------------------------------------------------
 */

$departments = $deptservice->get_all($USER->id);
$listurl     = (new moodle_url('/local/moodesk/departments.php'))->out(false);

// A column plugins add (hook department_list_columns).
$columnhook = new \local_moodesk\hook\department_list_columns(array_values($departments));
\core\di::get(\core\hook\manager::class)->dispatch($columnhook);
$columnlabel = $columnhook->get_label();

$rows = [];
foreach ($departments as $dept) {
    $rows[] = [
        'id'               => (int) $dept->id,
        'name'             => format_string($dept->name),
        'isactive'         => (bool) $dept->isactive,
        'statuslabel'      => $dept->isactive
            ? get_string('dept_status_active', 'local_moodesk')
            : get_string('dept_status_archived', 'local_moodesk'),
        'membercount'      => $deptservice->get_member_count((int) $dept->id),
        'plugincell'       => $columnhook->get_cell((int) $dept->id),
        'editurl'          => (new moodle_url('/local/moodesk/department_edit.php', ['id' => $dept->id]))->out(false),
        'membersurl'       => (new moodle_url('/local/moodesk/department_members.php', ['id' => $dept->id]))->out(false),
        'listurl'          => $listurl,
        'togglelabel'      => $dept->isactive
            ? get_string('dept_archive', 'local_moodesk')
            : get_string('dept_activate', 'local_moodesk'),
        'sesskey'          => sesskey(),
    ];
}

$templatectx = [
    'departments'    => $rows,
    'hasdepartments' => !empty($rows),
    'pluginlabel'    => $columnlabel,
    'newurl'         => (new moodle_url('/local/moodesk/department_edit.php'))->out(false),
];

// The page header. The primary action goes through header_action::create_new(),
// which is what makes it the plus glyph alone: one decision about what a "New
// <entity>" control looks like, taken once for the whole product. The label is
// not lost — it becomes the button's accessible name and its tooltip.
$templatectx['pageheader'] = (new page_header(get_string('dept_admin_heading', 'local_moodesk')))
    ->with_primary_action(header_action::create_new(
        get_string('dept_new_heading', 'local_moodesk'),
        new moodle_url('/local/moodesk/department_edit.php'),
    ))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/admin_departments', $templatectx)
);
