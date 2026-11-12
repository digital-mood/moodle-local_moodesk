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
 * Category create / edit page.
 *
 * ?id=N  → edit existing category.
 * (no id) → create a new category.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\form_page;
use local_moodesk\output\page_header;
use local_moodesk\service\category_service;
use local_moodesk\form\category_form;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:managecategory', $context);

$id = optional_param('id', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/category_edit.php', $id ? ['id' => $id] : []));

$pagetitle = $id
    ? get_string('cat_edit_heading', 'local_moodesk')
    : get_string('cat_new_heading', 'local_moodesk');

$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . $pagetitle);
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

$PAGE->navbar->add(
    get_string('cat_admin_heading', 'local_moodesk'),
    new moodle_url('/local/moodesk/categories.php')
);
$PAGE->navbar->add($pagetitle);

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout). moodleform is captured via
// render() (string) instead of display() so it can be mounted in the shell.
$apppage = new \local_moodesk\output\app_page($PAGE, 'categories');

$catservice = category_service::create();

$form = new category_form(
    new moodle_url('/local/moodesk/category_edit.php', $id ? ['id' => $id] : []),
    ['id' => $id]
);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/moodesk/categories.php'));
}

$formdata = $form->get_data();
if ($formdata !== null) {
    $data              = new stdClass();
    $data->name        = $formdata->name;
    $data->description = $formdata->description ?? '';
    $data->sortorder   = (int) ($formdata->sortorder ?? 0);

    if ($id) {
        $catservice->update_category($id, $data, $USER->id);
        $msg = get_string('category_updated', 'local_moodesk');
    } else {
        $catservice->create_category($data, $USER->id);
        $msg = get_string('category_created', 'local_moodesk');
    }

    redirect(
        new moodle_url('/local/moodesk/categories.php'),
        $msg,
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Pre-populate the form on GET (not after a re-display with errors).
if ($id && !$form->is_submitted()) {
    $cat = $catservice->get_category($id, $USER->id);
    $form->set_data([
        'name'        => $cat->name,
        'description' => $cat->description ?? '',
        'sortorder'   => (int) $cat->sortorder,
    ]);
}

echo $apppage->render_within_shell(
    form_page::render($form->render(), new page_header($pagetitle))
);
