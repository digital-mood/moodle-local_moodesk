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
 * Create a small set of demonstration data for mooDesk.
 *
 * For test and demonstration sites only, never a production site: it creates
 * a course, users and tickets that are not real.
 *
 * Idempotent and additive: a re-run finds what it created before (by username,
 * course shortname or ticket subject) and skips it. It never deletes or resets.
 *
 * Creates:
 *   - 1 course (shortname: SMOKE-COURSE)
 *   - 1 department (name: Smoke Test Department)
 *   - 3 users: smoke_requester, smoke_agent, smoke_manager, with random
 *     passwords
 *   - The agent gets the mhd_agent role and is added to the department
 *   - 5 tickets: SMOKE-001..SMOKE-005, with varied priorities and statuses,
 *     each with at least one public reply
 *   - A marker file, moodesk_smoke/marker.txt, in the data directory, to check
 *     that files survive a backup and restore
 *
 * Usage, from the Moodle root, as the web server user:
 *   php local/moodesk/cli/seed_smoke_data.php
 *   php local/moodesk/cli/seed_smoke_data.php --verbose   # also log what it skips
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'verbose' => false],
    ['h' => 'help', 'v' => 'verbose']
);

if ($options['help']) {
    cli_writeln("Seed smoke-test data for mooDesk backup/restore validation.");
    cli_writeln("Usage: php seed_smoke_data.php [--verbose]");
    exit(0);
}

$verbose = (bool) $options['verbose'];

/**
 * Write a progress line to the CLI, unless the caller asked for quiet output.
 *
 * @param string $msg
 * @param bool $verbose
 */
function smoke_log(string $msg, bool $verbose = true): void {
    if ($verbose) {
        cli_writeln('[seed] ' . $msg);
    }
}

/**
 * Idempotent user creation by username.
 *
 * @param string $username
 * @param string $firstname
 * @param string $lastname
 * @param string $email
 * @param bool   $verbose
 * @return \stdClass The user record (existing or freshly created).
 */
function smoke_get_or_create_user(string $username, string $firstname, string $lastname, string $email, bool $verbose): \stdClass {
    global $DB, $CFG;
    $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if ($existing) {
        smoke_log("user '$username' exists (id={$existing->id}) — skip create", $verbose);
        return $existing;
    }
    $user = new \stdClass();
    $user->auth      = 'manual';
    $user->confirmed = 1;
    $user->username  = $username;
    $user->password  = generate_password(); // Random: nobody signs in as a smoke user.
    $user->firstname = $firstname;
    $user->lastname  = $lastname;
    $user->email     = $email;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->lang      = 'en';
    $user->timezone  = 'UTC';
    $userid = user_create_user($user, true, false);
    smoke_log("user '$username' created (id=$userid)", true);
    return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
}

/**
 * Idempotent course creation by shortname.
 *
 * @param string $shortname
 * @param string $fullname
 * @param bool   $verbose
 * @return \stdClass The course record (existing or freshly created).
 */
function smoke_get_or_create_course(string $shortname, string $fullname, bool $verbose): \stdClass {
    global $DB;
    $existing = $DB->get_record('course', ['shortname' => $shortname]);
    if ($existing) {
        smoke_log("course '$shortname' exists (id={$existing->id}) — skip create", $verbose);
        return $existing;
    }
    $category = $DB->get_record('course_categories', ['parent' => 0], '*', IGNORE_MULTIPLE);
    if (!$category) {
        cli_error("No course category found — Moodle install incomplete?");
    }
    $data = new \stdClass();
    $data->category   = $category->id;
    $data->shortname  = $shortname;
    $data->fullname   = $fullname;
    $data->summary    = 'Smoke-test course for mooDesk backup/restore validation.';
    $data->summaryformat = FORMAT_HTML;
    $data->visible    = 1;
    $course = create_course($data);
    smoke_log("course '$shortname' created (id={$course->id})", true);
    return $course;
}

/**
 * Idempotent department creation by exact name.
 *
 * @param string $name
 * @param int    $actorid User id recorded as the creator.
 * @param bool   $verbose
 * @return \stdClass The department record (existing or freshly created).
 */
function smoke_get_or_create_department(string $name, int $actorid, bool $verbose): \stdClass {
    global $DB;
    $existing = $DB->get_record('local_moodesk_department', ['name' => $name]);
    if ($existing) {
        smoke_log("department '$name' exists (id={$existing->id}) — skip create", $verbose);
        return $existing;
    }
    $svc = \local_moodesk\service\department_service::create();
    $data = new \stdClass();
    $data->name        = $name;
    $data->description = 'Smoke-test department.';
    $data->isactive    = 1;
    $deptid = $svc->create_department($data, $actorid);
    smoke_log("department '$name' created (id=$deptid)", true);
    return $DB->get_record('local_moodesk_department', ['id' => $deptid], '*', MUST_EXIST);
}

/**
 * Idempotent ticket creation by subject. Returns the existing or new ticket id.
 *
 * @param string $subject
 * @param string $message      First reply body.
 * @param int    $requesterid  User id of the ticket requester.
 * @param int    $departmentid Routing department id.
 * @param int    $courseid     Optional course context (0 for site-level).
 * @param int    $priority     Priority constant from ticket service.
 * @param bool   $verbose
 * @return int The ticket id (existing or freshly created).
 */
function smoke_get_or_create_ticket(
    string $subject,
    string $message,
    int $requesterid,
    int $departmentid,
    int $courseid,
    int $priority,
    bool $verbose
): int {
    global $DB;
    $existing = $DB->get_record_select(
        'local_moodesk_ticket',
        $DB->sql_equal('subject', ':subject', false),
        ['subject' => $subject],
        'id'
    );
    if ($existing) {
        smoke_log("ticket '$subject' exists (id={$existing->id}) — skip create", $verbose);
        return (int) $existing->id;
    }
    $svc = \local_moodesk\service\ticket_service::create();
    $data = new \stdClass();
    $data->userid       = $requesterid;
    $data->subject      = $subject;
    $data->message      = $message;
    $data->messageformat = FORMAT_HTML;
    $data->priority     = $priority;
    $data->departmentid = $departmentid;
    $data->courseid     = $courseid > 0 ? $courseid : null;
    $result = $svc->create_ticket($data, $requesterid);
    smoke_log("ticket '$subject' created (id={$result['ticketid']})", true);
    return (int) $result['ticketid'];
}

/**
 * Add a public reply to a seeded ticket.
 *
 * Idempotent: a ticket that already carries more than its opening reply is
 * left untouched, so the seeder can be re-run against the same site.
 *
 * @param int $ticketid
 * @param string $message
 * @param int $actorid
 * @param bool $verbose
 */
function smoke_add_reply(int $ticketid, string $message, int $actorid, bool $verbose): void {
    global $DB;
    // Skip if the ticket already has more than its initial reply (idempotency).
    $count = $DB->count_records('local_moodesk_reply', ['ticketid' => $ticketid]);
    if ($count > 1) {
        smoke_log("ticket id=$ticketid already has $count replies — skip", $verbose);
        return;
    }
    $svc = \local_moodesk\service\reply_service::create();
    $data = new \stdClass();
    $data->message       = $message;
    $data->messageformat = FORMAT_HTML;
    $svc->add_public_reply($ticketid, $data, $actorid);
    smoke_log("reply added to ticket id=$ticketid", true);
}

/**
 * Assign a seeded ticket to an agent.
 *
 * Idempotent: skips when the ticket already carries that assignee.
 *
 * @param int $ticketid
 * @param int $assigneeid
 * @param int $actorid
 * @param bool $verbose
 */
function smoke_assign_ticket(int $ticketid, int $assigneeid, int $actorid, bool $verbose): void {
    global $DB;
    $current = $DB->get_field('local_moodesk_ticket', 'assigneeid', ['id' => $ticketid]);
    if ((int) $current === $assigneeid) {
        smoke_log("ticket id=$ticketid already assigned to userid=$assigneeid — skip", $verbose);
        return;
    }
    \local_moodesk\service\ticket_service::create()->assign($ticketid, $assigneeid, $actorid);
    smoke_log("ticket id=$ticketid assigned to userid=$assigneeid", true);
}

/**
 * Move a seeded ticket to a new status.
 *
 * Idempotent: skips when the ticket already sits at that status.
 *
 * @param int $ticketid
 * @param int $newstatus
 * @param int $actorid
 * @param bool $verbose
 */
function smoke_transition_status(int $ticketid, int $newstatus, int $actorid, bool $verbose): void {
    global $DB;
    $current = $DB->get_field('local_moodesk_ticket', 'status', ['id' => $ticketid]);
    if ((int) $current === $newstatus) {
        smoke_log("ticket id=$ticketid already at status $newstatus — skip", $verbose);
        return;
    }
    \local_moodesk\service\ticket_service::create()->transition_status($ticketid, $newstatus, $actorid);
    smoke_log("ticket id=$ticketid → status $newstatus", true);
}

/* ---- Main ------------------------------------------------------------------ */

\core\session\manager::set_user(get_admin());
$adminid = (int) get_admin()->id;

cli_writeln('[seed] starting smoke seed (admin actorid=' . $adminid . ')');

$requester = smoke_get_or_create_user('smoke_requester', 'Smoke', 'Requester', 'smoke_requester@example.com', $verbose);
$agent     = smoke_get_or_create_user('smoke_agent', 'Smoke', 'Agent', 'smoke_agent@example.com', $verbose);
$manager   = smoke_get_or_create_user('smoke_manager', 'Smoke', 'Manager', 'smoke_manager@example.com', $verbose);

// Promote agent to mhd_agent role at system context.
$agentroleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent']);
if (!$agentroleid) {
    cli_error("Role 'mhd_agent' does not exist. Has local_moodesk been installed?");
}
$systemcontext = \context_system::instance();
if (!user_has_role_assignment($agent->id, $agentroleid, $systemcontext->id)) {
    role_assign($agentroleid, $agent->id, $systemcontext->id);
    smoke_log("agent userid={$agent->id} assigned to role mhd_agent at system context", true);
}

// Course.
$course = smoke_get_or_create_course('SMOKE-COURSE', 'Smoke Test Course', $verbose);

// Department + agent membership.
$dept = smoke_get_or_create_department('Smoke Test Department', $adminid, $verbose);
$deptsvc = \local_moodesk\service\department_service::create();
$existingmember = $DB->record_exists('local_moodesk_dept_member', [
    'departmentid' => $dept->id,
    'userid'       => $agent->id,
]);
if (!$existingmember) {
    $deptsvc->add_member($dept->id, $agent->id, $adminid);
    smoke_log("agent userid={$agent->id} added to department id={$dept->id}", true);
} else {
    smoke_log("agent already in department — skip", $verbose);
}

// Tickets — vary priority + status + assignee to exercise more code paths.
$ticketdomain = '\local_moodesk\domain\ticket';

$fixtures = [
    [
        'subject'  => 'SMOKE-001',
        'message'  => '<p>Cannot log into Moodle. Says my password is wrong.</p>',
        'priority' => $ticketdomain::PRIORITY_HIGH,
        'reply'    => '<p>Reset your password from /login/forgot_password.php and try again.</p>',
        'assign'   => true,
        'status'   => $ticketdomain::STATUS_INPROGRESS,
    ],
    [
        'subject'  => 'SMOKE-002',
        'message'  => '<p>How do I enrol students in bulk?</p>',
        'priority' => $ticketdomain::PRIORITY_NORMAL,
        'reply'    => '<p>Use Site administration → Users → Bulk user actions.</p>',
        'assign'   => true,
        'status'   => $ticketdomain::STATUS_RESOLVED,
    ],
    [
        'subject'  => 'SMOKE-003',
        'message'  => '<p>Quiz timer is not counting down for some students.</p>',
        'priority' => $ticketdomain::PRIORITY_URGENT,
        'reply'    => '<p>Investigating — please collect a screen recording from one affected student.</p>',
        'assign'   => true,
        'status'   => $ticketdomain::STATUS_PENDING,
    ],
    [
        'subject'  => 'SMOKE-004',
        'message'  => '<p>Request: enable MathJax filter on the site.</p>',
        'priority' => $ticketdomain::PRIORITY_LOW,
        'reply'    => '<p>Approved. Will be enabled in the next maintenance window.</p>',
        'assign'   => false,
        'status'   => $ticketdomain::STATUS_OPEN,
    ],
    [
        'subject'  => 'SMOKE-005',
        'message'  => '<p>SCORM package upload failing with HTTP 500.</p>',
        'priority' => $ticketdomain::PRIORITY_HIGH,
        'reply'    => '<p>Increased PHP upload limit. Please retry and confirm.</p>',
        'assign'   => true,
        'status'   => $ticketdomain::STATUS_CLOSED,
    ],
];

foreach ($fixtures as $f) {
    $tid = smoke_get_or_create_ticket(
        $f['subject'],
        $f['message'],
        $requester->id,
        (int) $dept->id,
        (int) $course->id,
        (int) $f['priority'],
        $verbose
    );
    smoke_add_reply($tid, $f['reply'], (int) $agent->id, $verbose);
    if ($f['assign']) {
        smoke_assign_ticket($tid, (int) $agent->id, $adminid, $verbose);
    }
    smoke_transition_status($tid, (int) $f['status'], $adminid, $verbose);
}

// Drop a tiny marker file in moodledata so the restore can verify file restoration too.
$markerdir = $CFG->dataroot . '/moodesk_smoke';
if (!is_dir($markerdir)) {
    mkdir($markerdir, 0775, true);
}
$markerfile = $markerdir . '/marker.txt';
if (!file_exists($markerfile)) {
    file_put_contents(
        $markerfile,
        "moodesk smoke-test marker\nseeded: " . date('c') . "\n"
    );
    smoke_log("moodledata marker written: $markerfile", true);
} else {
    smoke_log("moodledata marker already present — skip", $verbose);
}

cli_writeln('[seed] done — verify in UI: ticket list shows SMOKE-001..SMOKE-005');
