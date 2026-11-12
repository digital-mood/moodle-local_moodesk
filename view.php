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
 * Ticket detail page — the canonical URL for a ticket.
 *
 * This entry point owns the request: POST actions are dispatched to
 * ticket_page_handler, and the screen itself is the
 * {@see \local_moodesk\output\ticket_detail} component, which the ticket list
 * also renders inside its overlay drawer. Nothing about the detail is built
 * here, so both mounts cannot drift apart.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\controller\ticket_page_handler;
use local_moodesk\output\app_page;
use local_moodesk\output\ticket_detail;
use local_moodesk\policy\ticket_access_policy;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;

require_login();

$ticketid = required_param('id', PARAM_INT);

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/view.php', ['id' => $ticketid]));

$ticketservice = ticket_service::create();

// Load the ticket first: access is checked inside the service, and the page
// title needs the subject before anything is rendered.
$ticketobj = $ticketservice->get_ticket($ticketid, $USER->id);

$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . format_string($ticketobj->subject));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// Layout, assets and chrome are owned by app_page (the mooDesk application
// shell). The detail screen belongs to the Tickets section ('tickets' page key).
$apppage = new app_page($PAGE, 'tickets');

/*
 * -------------------------------------------------------------------------
 * Handle POST actions — delegated to the page controller. Every mutating path
 * ends in a redirect, so nothing below runs for those requests.
 * -------------------------------------------------------------------------
 */

$accesspolicy = new ticket_access_policy();

(new ticket_page_handler(
    $ticketid,
    $USER->id,
    $ticketservice,
    reply_service::create(),
    $accesspolicy->can_add_note((int) $USER->id, $ticketobj),
    $accesspolicy->can_manage((int) $USER->id, $ticketobj)
))->handle();

/*
 * -------------------------------------------------------------------------
 * Render.
 * -------------------------------------------------------------------------
 */

$detail = ticket_detail::create($ticketid, (int) $USER->id);

// Messages queued by a redirect (reply sent, note added, a merge revert that
// failed) never reach the screen on their own: the shell runs on Moodle's
// 'embedded' layout, which renders no notification region. Drain the queue here
// and hand it to the toast, so the confirmation lands where the user is looking.
$toasts = [];
foreach (\core\notification::fetch() as $notification) {
    $toasts[] = [
        'message' => $notification->get_message(),
        'type'    => $notification->get_message_type(),
    ];
}
if (!empty($toasts)) {
    $PAGE->requires->js_call_amd('local_moodesk/toast', 'flash', [$toasts]);
}

echo $apppage->render_within_shell($detail->render($PAGE, ticket_detail::VARIANT_PAGE));
