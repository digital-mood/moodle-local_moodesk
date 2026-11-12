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
 * Category administration — list view.
 *
 * Categories is a Starter-tier feature: no edition gate.
 * The default category (plugin config 'defaultcategoryid') cannot be archived.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\header_action;
use local_moodesk\output\page_header;
use local_moodesk\service\category_service;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:managecategory', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/categories.php'));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('cat_admin_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'categories');

$catservice = category_service::create();

/*
 * -------------------------------------------------------------------------
 * Handle POST: toggle visibility.
 * -------------------------------------------------------------------------
 */

$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $catid = required_param('categoryid', PARAM_INT);
    $catservice->toggle_visible($catid, $USER->id);

    redirect(
        new moodle_url('/local/moodesk/categories.php'),
        get_string('category_updated', 'local_moodesk'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/*
 * -------------------------------------------------------------------------
 * Build template context.
 * -------------------------------------------------------------------------
 */

$categories = $catservice->get_all($USER->id);
$listurl    = (new moodle_url('/local/moodesk/categories.php'))->out(false);
$defaultid  = (int) get_config('local_moodesk', 'defaultcategoryid');

$rows = [];
foreach ($categories as $cat) {
    $isdefault = ((int) $cat->id === $defaultid);
    $visible   = (bool) $cat->visible;

    $rows[] = [
        'id'             => (int) $cat->id,
        'name'           => format_string($cat->name),
        'description'    => format_string($cat->description ?? ''),
        'sortorder'      => (int) $cat->sortorder,
        'visible'        => $visible,
        'statuslabel'    => $visible
            ? get_string('cat_status_active', 'local_moodesk')
            : get_string('cat_status_archived', 'local_moodesk'),
        'isdefault'      => $isdefault,
        'editurl'        => (new moodle_url('/local/moodesk/category_edit.php', ['id' => $cat->id]))->out(false),
        'listurl'        => $listurl,
        'togglelabel'    => $visible
            ? get_string('cat_archive', 'local_moodesk')
            : get_string('cat_activate', 'local_moodesk'),
        'toggledisabled' => $isdefault && $visible,
        'sesskey'        => sesskey(),
    ];
}

$templatectx = [
    'categories'    => $rows,
    'hascategories' => !empty($rows),
    'newurl'        => (new moodle_url('/local/moodesk/category_edit.php'))->out(false),
];

// The page header. The primary action goes through header_action::create_new(),
// which is what makes it the plus glyph alone: one decision about what a "New
// <entity>" control looks like, taken once for the whole product. The label is
// not lost — it becomes the button's accessible name and its tooltip.
$templatectx['pageheader'] = (new page_header(get_string('cat_admin_heading', 'local_moodesk')))
    ->with_primary_action(header_action::create_new(
        get_string('cat_new_heading', 'local_moodesk'),
        new moodle_url('/local/moodesk/category_edit.php'),
    ))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/admin_categories', $templatectx)
);
