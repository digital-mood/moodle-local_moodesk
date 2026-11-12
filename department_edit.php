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
 * Department create / edit page.
 *
 * ?id=N  → edit existing department.
 * (no id) → create a new department.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\form_page;
use local_moodesk\output\page_header;
use local_moodesk\service\department_service;
use local_moodesk\form\department_form;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:managedepartment', $context);

$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/department_edit.php', $id ? ['id' => $id] : []));

$pagetitle = $id
    ? get_string('dept_edit_heading', 'local_moodesk')
    : get_string('dept_new_heading', 'local_moodesk');

$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . $pagetitle);
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Breadcrumb.
$PAGE->navbar->add(
    get_string('dept_admin_heading', 'local_moodesk'),
    new moodle_url('/local/moodesk/departments.php')
);
$PAGE->navbar->add($pagetitle);

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout). moodleform is captured via
// render() (string) instead of display() so it can be mounted in the shell.
$apppage = new \local_moodesk\output\app_page($PAGE, 'departments');

$deptservice = department_service::create();

$form = new department_form(
    new moodle_url('/local/moodesk/department_edit.php', $id ? ['id' => $id] : []),
    ['id' => $id]
);

/*
 * -------------------------------------------------------------------------
 * Process form submission.
 * -------------------------------------------------------------------------
 */

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/moodesk/departments.php'));
}

$formdata = $form->get_data();
if ($formdata !== null) {
    $data              = new stdClass();
    $data->name        = $formdata->name;
    $data->description = $formdata->description ?? '';
    $data->isactive    = !empty($formdata->isactive) ? 1 : 0;


    if ($id) {
        $deptservice->update_department($id, $data, $USER->id);
        $savedid = $id;
        $msg = get_string('department_updated', 'local_moodesk');
    } else {
        $savedid = $deptservice->create_department($data, $USER->id);
        $msg = get_string('department_created', 'local_moodesk');
    }

    // Plugins save their own fields now that the department exists.
    $form->save_plugin_fields((int) $savedid, $formdata, (int) $USER->id);

    redirect(
        new moodle_url('/local/moodesk/departments.php'),
        $msg,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Pre-populate the form when editing (only on first GET load, not on re-display after errors).
if ($id && !$form->is_submitted()) {
    $dept = $deptservice->get_department($id, $USER->id);

    $defaults = [
        'name'        => $dept->name,
        'description' => $dept->description ?? '',
        'isactive'    => (int) $dept->isactive,
    ];

    // Plugins fill their own fields; the form's own keys win.
    $form->set_data($defaults + $form->plugin_defaults($id, (int) $USER->id));
}

/*
 * -------------------------------------------------------------------------
 * Render.
 * -------------------------------------------------------------------------
 */

echo $apppage->render_within_shell(
    form_page::render($form->render(), new page_header($pagetitle))
);
