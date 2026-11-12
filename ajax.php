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
 * AJAX endpoint for the actions the ticket detail offers.
 *
 * Sidebar properties (status, priority, assign, department, category, team),
 * the reply composer, custom field values and the undo of a merge, plus saved
 * filter views and the composer's draft attachments.
 *
 * Accepts the same POST parameters as the regular form POSTs in view.php but
 * returns a JSON response instead of a redirect, which is what lets the ticket
 * detail drawer run a whole ticket without ever leaving the list.
 *
 * Request: POST with action, ticketid, sesskey + action-specific params.
 * Response: {"success":true, "action":"...", ...updated values...}
 *           {"success":false, "error":"...message..."}
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_moodesk\controller\ticket_page_handler;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;
use local_moodesk\service\department_service;
use local_moodesk\service\category_service;
use local_moodesk\service\saved_view_service;
use local_moodesk\domain\ticket;
use local_moodesk\util\ajax_error;
use local_moodesk\util\user_fields;
use local_moodesk\validator\ticket_action_validator;

require_login();

header('Content-Type: application/json; charset=utf-8');

// Only POST is accepted.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    die();
}

// Require sesskey.
if (!confirm_sesskey()) {
    echo json_encode(['success' => false, 'error' => 'Invalid session key']);
    die();
}

$action = required_param('action', PARAM_ALPHANUMEXT);

/*
 * -------------------------------------------------------------------------
 * Preference actions — save/delete named filter views.
 * These do not operate on a ticket and do not require :manage capability.
 * Only agents with :viewall (queue access) can manage saved views.
 * -------------------------------------------------------------------------
 */
if ($action === 'save_filter_view' || $action === 'delete_filter_view') {
    $context = \context_system::instance();
    if (!has_capability('local/moodesk:viewall', $context)) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        die();
    }

    try {
        if ($action === 'save_filter_view') {
            $name   = required_param('name', PARAM_TEXT);
            $params = required_param('params', PARAM_RAW);

            // Validate params is a valid JSON object.
            $decoded = json_decode($params, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                echo json_encode(['success' => false, 'error' => 'Invalid JSON in params']);
                die();
            }

            // The service owns the allowlist, the parameter shape and the slug.
            $slug = saved_view_service::save((int) $USER->id, $name, $decoded);

            echo json_encode(['success' => true, 'action' => 'save_filter_view', 'key' => $slug]);
        } else {
            // Action: delete_filter_view.
            $key = required_param('key', PARAM_ALPHANUMEXT);
            saved_view_service::delete((int) $USER->id, $key);
            echo json_encode(['success' => true, 'action' => 'delete_filter_view', 'key' => $key]);
        }
    } catch (\Throwable $e) {
        echo json_encode(ajax_error::payload($e, $action));
    }
    die();
}

/*
 * -------------------------------------------------------------------------
 * Reply composer draft attachments — read the draft, remove one file from it.
 *
 * The composer draws its own attachment chips rather than rendering Moodle's
 * file manager (ADR: docs/reply-composer-attachments.md), so it has to ask what
 * the draft actually holds. Moodle has no supported client-side answer: the
 * core_files_* external functions are web-service-token only (none declares
 * 'ajax' => true), and repository/draftfiles_ajax.php is core's internal
 * endpoint, not an API.
 *
 * Both actions answer with the draft's full contents, so a delete is also a
 * re-sync: the chips are only ever a view of what the server just reported,
 * never a record the client keeps on its own.
 *
 * Two composers use this. A reply names the ticket it belongs to; a ticket
 * being written names none, because it does not exist yet. `ticketid` is
 * therefore optional, and which gate runs follows from it:
 *
 *   ticketid > 0  the ticket is one this user may see, and its composer is one
 *                 this user may use. Both are asked of ticket_detail rather
 *                 than restated here, so the endpoint cannot drift from the
 *                 screen it serves.
 *   ticketid = 0  the new-ticket composer, gated on :submit in the system
 *                 context — the same capability new_ticket_drawer::create()
 *                 requires before it will build the form at all.
 *
 * Around that, in order: authenticated and not a guest, sesskey (both enforced
 * above), and uploads enabled site-wide. Ownership of the draft itself needs no
 * check: every File API call in the service resolves against the current user's
 * own context, so another user's draft has nothing to resolve to.
 * -------------------------------------------------------------------------
 */
if ($action === 'draft_list' || $action === 'draft_delete') {
    if (isguestuser()) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        die();
    }

    $ticketid    = optional_param('ticketid', 0, PARAM_INT);
    $draftitemid = required_param('draftitemid', PARAM_INT);

    try {
        if (!get_config('local_moodesk', 'attachments_enabled')) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            die();
        }

        if ($ticketid > 0) {
            $detail = \local_moodesk\output\ticket_detail::create($ticketid, $USER->id);

            if (!$detail->has_composer()) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                die();
            }
        } else {
            // Raises required_capability_exception, which the catch below
            // answers with the same refusal as every other failure here.
            require_capability('local/moodesk:submit', \context_system::instance());
        }

        $draftservice = \local_moodesk\service\draft_attachment_service::create();

        if ($action === 'draft_delete') {
            $draftservice->delete_file(
                $draftitemid,
                optional_param('filepath', '/', PARAM_PATH),
                required_param('filename', PARAM_FILE)
            );
        }

        echo json_encode([
            'success' => true,
            'action'  => $action,
            'files'   => $draftservice->list_files($draftitemid),
        ]);
    } catch (\Throwable $e) {
        // Includes the ticket being out of reach, which the service raises.
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
    }
    die();
}

/*
 * -------------------------------------------------------------------------
 * The reply, from the ticket detail.
 *
 * The drawer is where a ticket is worked on, so none of these may end in a
 * redirect: the panel would go, and with it the list behind it. They are the
 * same actions view.php runs, reached through the same controller — the bodies
 * on ticket_page_handler report what happened instead of redirecting, and the
 * page keeps the redirect by wrapping them. Neither entry point holds a rule of
 * its own, which is the only way the fast path cannot save something the slow
 * one would have refused.
 *
 * Reach is asked once, of the ticket service, exactly as view.php asks it
 * before it renders anything. What each action additionally requires is left
 * where it already lives: has_composer() for a reply.
 * -------------------------------------------------------------------------
 */
if ($action === 'reply') {
    $ticketid = required_param('ticketid', PARAM_INT);
    $context  = \context_system::instance();

    // The reply composer is a moodleform, and building one reads $PAGE->context.
    // A page sets that on its way to rendering; an AJAX script never renders, so
    // it never had a reason to — until this endpoint started building the same
    // form the page does. Without it the form dies in its constructor, and the
    // send comes back refused for a reason that has nothing to do with the reply.
    $PAGE->set_context($context);

    try {
        $ticketservice = ticket_service::create();

        // Raises if this user cannot reach the ticket at all.
        $ticketobj = $ticketservice->get_ticket($ticketid, $USER->id);

        if ($action === 'reply') {
            $detail = \local_moodesk\output\ticket_detail::create($ticketid, (int) $USER->id);
            if (!$detail->has_composer()) {
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                die();
            }
        }

        $accesspolicy = new \local_moodesk\policy\ticket_access_policy();

        $handler = new ticket_page_handler(
            $ticketid,
            (int) $USER->id,
            $ticketservice,
            reply_service::create(),
            $accesspolicy->can_add_note((int) $USER->id, $ticketobj),
            $accesspolicy->can_manage((int) $USER->id, $ticketobj)
        );

        $result = $handler->submit_reply();

        // Null is the form refusing itself — not submitted, or invalid.
        // Cancel never arrives here (the composer handles it without a
        // request), and is answered the same way if it ever does.
        if ($result === null || !empty($result['cancelled'])) {
            echo json_encode([
                'success' => false,
                'error'   => get_string('detail_save_failed', 'local_moodesk'),
            ]);
            die();
        }

        echo json_encode([
            'success'   => true,
            'action'    => 'reply',
            'replytype' => $result['replytype'],
            'message'   => $result['message'],
        ]);
    } catch (\Throwable $e) {
        // Includes the ticket being out of reach, which carries its own localised
        // message. Anything else is kept
        // off the wire by ajax_error.
        echo json_encode(ajax_error::payload($e, $action));
    }
    die();
}

/*
 * -------------------------------------------------------------------------
 * A ticket action this script does not know: a plugin may own it (hook
 * ticket_actions), on the same reach check view.php makes, and checks what it
 * needs beyond that itself. Unknown to everybody, or asked about a ticket out
 * of reach, the answer is the one an unknown action has always had, which also
 * says nothing about the ticket.
 * -------------------------------------------------------------------------
 */
if (!in_array($action, ['status', 'priority', 'assign', 'department', 'updatecategory'], true)) {
    $ticketid = required_param('ticketid', PARAM_INT);
    $ticketservice = ticket_service::create();
    try {
        try {
            $ticketobj = $ticketservice->get_ticket($ticketid, $USER->id);
        } catch (\moodle_exception $e) {
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            die();
        }
        $accesspolicy = new \local_moodesk\policy\ticket_access_policy();
        $handler      = new ticket_page_handler(
            $ticketid,
            (int) $USER->id,
            $ticketservice,
            reply_service::create(),
            $accesspolicy->can_add_note((int) $USER->id, $ticketobj),
            $accesspolicy->can_manage((int) $USER->id, $ticketobj)
        );
        $result = $handler->run_plugin_action($action);
        if ($result === null) {
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            die();
        }
        echo json_encode([
            'success' => true,
            'action'  => $action,
            'message' => $result['message'],
        ] + $result['data']);
    } catch (\Throwable $e) {
        echo json_encode(ajax_error::payload($e, $action));
    }
    die();
}

/*
 * -------------------------------------------------------------------------
 * Ticket mutation actions — require :manage capability and a valid ticketid.
 * -------------------------------------------------------------------------
 */
try {
    ticket_action_validator::require_manage_capability();
} catch (\required_capability_exception $e) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    die();
}

$ticketid = required_param('ticketid', PARAM_INT);

$ticketservice = ticket_service::create();

// Status labels and priority labels for returning updated display values.
$statuslabels   = ticket::status_labels();
$prioritylabels = ticket::priority_labels();

try {
    switch ($action) {
        case 'status':
            $newstatus = required_param('newstatus', PARAM_INT);
            ticket_action_validator::require_valid_status($newstatus);
            $ticketservice->transition_status($ticketid, $newstatus, $USER->id);

            // Where the ticket can go from its new state. The detail rail saves
            // on selection, so a select still offering the old state's
            // transitions would hand the user a move the service is about to
            // refuse.
            $transitions = [];
            foreach ((ticket::VALID_TRANSITIONS[$newstatus] ?? []) as $next) {
                $transitions[] = ['value' => $next, 'label' => $statuslabels[$next] ?? $next];
            }

            echo json_encode([
                'success'     => true,
                'action'      => 'status',
                'newstatus'   => $newstatus,
                'statuslabel' => $statuslabels[$newstatus] ?? $newstatus,
                'transitions' => $transitions,
            ]);
            break;

        case 'priority':
            $newpriority = required_param('newpriority', PARAM_INT);
            ticket_action_validator::require_valid_priority($newpriority);
            $ticketservice->set_priority($ticketid, $newpriority, $USER->id);
            echo json_encode([
                'success'       => true,
                'action'        => 'priority',
                'newpriority'   => $newpriority,
                'prioritylabel' => $prioritylabels[$newpriority] ?? $newpriority,
            ]);
            break;

        case 'assign':
            $assigneeid = optional_param('assigneeid', null, PARAM_INT);
            $ticketservice->assign($ticketid, $assigneeid ?: null, $USER->id);

            $assigneename = get_string('unassigned', 'local_moodesk');
            if ($assigneeid) {
                $agent = $DB->get_record('user', ['id' => $assigneeid], user_fields::for_fullname());
                if ($agent) {
                    $assigneename = fullname($agent);
                }
            }
            echo json_encode([
                'success'      => true,
                'action'       => 'assign',
                'assigneeid'   => $assigneeid ?: null,
                'assigneename' => $assigneename,
            ]);
            break;

        case 'department':
            $newdeptid = optional_param('departmentid', null, PARAM_INT);
            $ticketservice->set_department($ticketid, $newdeptid ?: null, $USER->id);

            $deptname = '';
            if ($newdeptid) {
                $deptname = $DB->get_field('local_moodesk_department', 'name', ['id' => $newdeptid]) ?: '';
            }
            echo json_encode([
                'success'        => true,
                'action'         => 'department',
                'departmentid'   => $newdeptid ?: null,
                'departmentname' => format_string($deptname),
            ]);
            break;

        case 'updatecategory':
            $newcategoryid = required_param('categoryid', PARAM_INT);
            ticket_action_validator::require_positive_int($newcategoryid, 'categoryid');
            $ticketservice->set_category($ticketid, $newcategoryid, $USER->id);

            $catname = $DB->get_field('local_moodesk_category', 'name', ['id' => $newcategoryid]) ?: '';
            echo json_encode([
                'success'      => true,
                'action'       => 'updatecategory',
                'categoryid'   => $newcategoryid,
                'categoryname' => format_string($catname),
            ]);
            break;
    }
} catch (\Throwable $e) {
    echo json_encode(ajax_error::payload($e, $action));
}
