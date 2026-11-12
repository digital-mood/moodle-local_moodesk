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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Bulk action handler for the ticket list.
 *
 * Accepts POST only. Requires manage capability + sesskey.
 * Supported actions: status, assign.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\service\ticket_service;

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/moodesk:manage', $context);

// Where to send the agent afterwards. PARAM_LOCALURL is what keeps this from
// being an open redirect: anything pointing off this site is stripped, and the
// bare list is the fallback. The list page posts the URL the agent was standing
// on, so the filters, page and view they were triaging under survive the action.
$rawreturn = optional_param('returnurl', '', PARAM_LOCALURL);
$returnurl = $rawreturn !== ''
    ? new moodle_url($rawreturn)
    : new moodle_url('/local/moodesk/index.php');

// Accept POST only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($returnurl);
}

$ticketids  = optional_param_array('ticketids', [], PARAM_INT);
$bulkaction = required_param('bulkaction', PARAM_ALPHANUMEXT);

$params = [];
if ($bulkaction === ticket_service::BULK_ACTION_STATUS) {
    $params['status'] = required_param('newstatus', PARAM_INT);
} else if ($bulkaction === ticket_service::BULK_ACTION_ASSIGN) {
    $params['assigneeid'] = optional_param('assigneeid', 0, PARAM_INT);
} else {
    throw new \moodle_exception('bulk_invalid_action', 'local_moodesk');
}

try {
    $result = ticket_service::create()->apply_bulk($ticketids, $bulkaction, $params, $USER->id);
} catch (\moodle_exception $e) {
    // Over the cap nothing moved; say so on the list rather than on an error page.
    if ($e->errorcode !== 'bulk_too_many') {
        throw $e;
    }
    redirect($returnurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
}

if ($result->is_empty()) {
    redirect(
        $returnurl,
        get_string('bulk_no_selection', 'local_moodesk'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

if ($result->failure_count() === 0) {
    redirect(
        $returnurl,
        get_string('bulk_success', 'local_moodesk', $result->success_count()),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Name the tickets that stayed behind. A count alone leaves the agent to work
// out which of their selection did not move, which on a page of thirty is the
// whole job again.
$failedids = implode(', ', array_map(
    static fn(int $id): string => '#' . $id,
    $result->failed_ids()
));

if ($result->success_count() > 0) {
    redirect(
        $returnurl,
        get_string('bulk_partial', 'local_moodesk', [
            'done'   => $result->success_count(),
            'failed' => $failedids,
        ]),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

redirect(
    $returnurl,
    get_string('bulk_failed', 'local_moodesk', $failedids),
    null,
    \core\output\notification::NOTIFY_ERROR
);
