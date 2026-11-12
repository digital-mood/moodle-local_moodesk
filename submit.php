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
 * New ticket submission page.
 *
 * Accessible to any authenticated user who holds :submit capability.
 * An optional courseid parameter pre-populates the course context field
 * and is preserved through the form POST.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_moodesk\output\form_page;
use local_moodesk\output\page_header;
use local_moodesk\service\ticket_service;
use local_moodesk\output\new_ticket_drawer;

require_login();

$courseid = optional_param('courseid', 0, PARAM_INT);
// A category to start from — the knowledge base's "Open a ticket" passes the
// article's. Validated by the drawer against the active categories; never
// trusted as such.
$categoryid = optional_param('categoryid', 0, PARAM_INT);

$context = context_system::instance();
require_capability('local/moodesk:submit', $context);

// Page setup.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/submit.php', $courseid ? ['courseid' => $courseid] : []));
$PAGE->set_title(get_string('page_submit_title', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell). 'newticket' is a real nav item (child of Dashboard), so the shell
// highlights it.
$apppage = new \local_moodesk\output\app_page($PAGE, 'newticket');

// Option loading, the draft file area and the form itself are owned by
// new_ticket_drawer, so this page and the New ticket drawer on the list and
// dashboard screens build one and the same form. The drawer posts here.
$drawer          = new_ticket_drawer::create($courseid, get_string('ticket_submit', 'local_moodesk'), $categoryid);
$form            = $drawer->get_form();
$attachenabled   = $drawer->attachments_enabled();
$attachoptions   = $drawer->get_attachment_options();

// The form's own controls — the growing message box and the attach button —
// belong to the form, so this page queues them exactly as the drawer does.
$drawer->require_composer_js($PAGE);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/moodesk/index.php'));
}

if ($formdata = $form->get_data()) {
    $service = ticket_service::create();

    $ticketdata          = new stdClass();
    $ticketdata->userid  = $USER->id;
    $ticketdata->subject = $formdata->subject;
    $ticketdata->message = $formdata->message ?? '';
    // Stated here rather than reported by an editor. The message box is a plain
    // textarea, so the text is exactly what was typed and FORMAT_PLAIN is the
    // truth about it — escaped on the way out by the one format_text() call the
    // thread makes. Tickets raised before this change keep whatever format they
    // were stored with; the thread renders each reply by its own.
    $ticketdata->messageformat = FORMAT_PLAIN;
    $ticketdata->categoryid    = !empty($formdata->categoryid) ? (int) $formdata->categoryid : null;
    $ticketdata->departmentid  = !empty($formdata->departmentid) ? (int) $formdata->departmentid : null;
    $ticketdata->courseid      = !empty($formdata->courseid) ? (int) $formdata->courseid : null;

    $result   = $service->create_ticket($ticketdata, $USER->id);
    $ticketid = $result['ticketid'];
    $replyid  = $result['replyid'];

    // Plugins save their own fields now that the ticket exists.
    $drawer->save_plugin_fields((int) $ticketid, $formdata, (int) $USER->id);

    // Save attachments as reply_attachment against the first reply ID.
    // This keeps the file area uniform with all subsequent replies.
    if ($attachenabled && isset($formdata->attachments)) {
        file_save_draft_area_files(
            $formdata->attachments,
            $context->id,
            'local_moodesk',
            'reply_attachment',
            $replyid,
            $attachoptions
        );
    }

    redirect(
        new moodle_url('/local/moodesk/view.php', ['id' => $ticketid]),
        get_string('ticket_submitted', 'local_moodesk'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Output — build the page body as a string so it can be mounted in the shell.
// The moodleform is rendered verbatim (validation/CSRF untouched) and wrapped in
// a mooDesk product card; the scoped SCSS (components/_forms-controls.scss)
// restyles it into the standalone form experience.
//
// The heading used to be three html_writer calls right here, against a
// `.moodesk-form-page__head` that was a fourth copy of the same three rules the
// dashboard, the reports screen and the ticket list each had their own version
// of. It is the shared component now — same title, same description, one place.
//
// The wrapper around it went the same way, and for the same reason: these four
// lines were the only description of what a mooDesk form page is, and they were
// in an entry point rather than in a type, so the seven admin edit screens never
// found them. form_page is that description now.
echo $apppage->render_within_shell(form_page::render(
    $form->render(),
    (new page_header(get_string('page_submit_heading', 'local_moodesk')))
        ->with_description(get_string('submit_intro', 'local_moodesk'))
));
