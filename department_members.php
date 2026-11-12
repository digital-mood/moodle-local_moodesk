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
 * Department member management page.
 *
 * Shows current members with a Remove button per row, and an agent selector
 * for adding new members. A server-side search filter narrows the available
 * agents list — no JavaScript required.
 *
 * Structure is intentionally kept simple (select + POST) so it can be
 * upgraded to an autocomplete or drag-and-drop approach in a later phase
 * without changing the service layer.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\page_header;
use local_moodesk\service\department_service;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:managedepartment', $context);

$deptid = required_param('id', PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

$deptservice = department_service::create();
$dept        = $deptservice->get_department($deptid, $USER->id);

$pageurl = new moodle_url('/local/moodesk/department_members.php', ['id' => $deptid]);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);

$pagetitle = get_string('dept_members_heading', 'local_moodesk', format_string($dept->name));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . $pagetitle);
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Breadcrumb.
$PAGE->navbar->add(
    get_string('dept_admin_heading', 'local_moodesk'),
    new moodle_url('/local/moodesk/departments.php')
);
$PAGE->navbar->add(format_string($dept->name));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell on the embedded layout).
$apppage = new \local_moodesk\output\app_page($PAGE, 'departments');

/*
 * -------------------------------------------------------------------------
 * Handle POST: add or remove a member.
 * Redirect-after-POST to prevent re-submission on refresh.
 * -------------------------------------------------------------------------
 */

$action = optional_param('action', '', PARAM_ALPHA);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $userid = required_param('userid', PARAM_INT);

    if ($action === 'add') {
        $deptservice->add_member($deptid, $userid, $USER->id);
        $msg = get_string('department_member_added', 'local_moodesk');
    } else if ($action === 'remove') {
        $deptservice->remove_member($deptid, $userid, $USER->id);
        $msg = get_string('department_member_removed', 'local_moodesk');
    }

    redirect(
        new moodle_url('/local/moodesk/department_members.php', ['id' => $deptid]),
        $msg ?? '',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

/*
 * -------------------------------------------------------------------------
 * Build template context.
 * -------------------------------------------------------------------------
 */

$members   = $deptservice->get_members($deptid, $USER->id);
$available = $deptservice->get_available_agents($deptid, $USER->id, $search);

$memberrows = [];
foreach ($members as $m) {
    // The entity cell's figure. The row's own `id` is the membership's, not the
    // user's, so user_picture() is given a copy carrying the user id it expects.
    // `link => false` because the row is a list entry, not a route into the
    // user's profile.
    $picuser       = clone $m;
    $picuser->id   = (int) $m->userid;
    $userpic       = new user_picture($picuser);
    $userpic->size = 36;
    $userpic->link = false;

    $memberrows[] = [
        'userid'   => (int) $m->userid,
        'fullname' => fullname($m),
        'email'    => $m->email,
        'avatar'   => $OUTPUT->render($userpic),
        'pageurl'  => $pageurl->out(false),
        'sesskey'  => sesskey(),
    ];
}

$availablerows = [];
foreach ($available as $a) {
    $availablerows[] = [
        'id'       => (int) $a->id,
        'fullname' => fullname($a),
        'email'    => $a->email,
    ];
}

$templatectx = [
    'deptid'       => $deptid,
    'deptname'     => format_string($dept->name),
    'members'      => $memberrows,
    'hasmembers'   => !empty($memberrows),
    'available'    => $availablerows,
    'hasavailable' => !empty($availablerows),
    'pageurl'      => $pageurl->out(false),
    'clearurl'     => (new moodle_url('/local/moodesk/department_members.php', ['id' => $deptid]))->out(false),
    'sesskey'      => sesskey(),
    'search'       => $search,
    'hassearch'    => $search !== '',
    'backurl'      => (new moodle_url('/local/moodesk/departments.php'))->out(false),
];

// The page header. Title only: this screen already ends on a link back to the
// list, and the two panels below carry their own headings. What it was missing
// was a statement of which department this is — `$pagetitle` was computed for
// the browser tab and the navbar, and the embedded layout renders neither.
$templatectx['pageheader'] = (new page_header($pagetitle))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/admin_department_members', $templatectx)
);
