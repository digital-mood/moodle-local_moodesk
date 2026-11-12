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
 * Agent management — offboard an agent (Agent Management A3).
 *
 * Remove a managed agent safely: their open tickets are returned to the queue
 * before agent status is revoked, so nothing is orphaned. Unmanaged agents are
 * shown a read-only explanation (their status lives in Moodle). Starter tier.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_moodesk\output\header_action;
use local_moodesk\output\page_header;
use local_moodesk\service\agent_service;

require_login();

$context = context_system::instance();
require_capability('local/moodesk:manageagents', $context);

$userid = required_param('userid', PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/offboard_agent.php', ['userid' => $userid]));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('agents_offboard_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// The directory node stays highlighted while offboarding.
$apppage = new \local_moodesk\output\app_page($PAGE, 'agents');

$service = agent_service::create();
$profile = $service->get_agent_profile($userid, $USER->id);

/*
 * -------------------------------------------------------------------------
 * Handle POST: offboard the agent (only valid for managed agents).
 * -------------------------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey() && $profile->managed) {
    $action = optional_param('action', '', PARAM_ALPHA);

    if ($action === 'offboard') {
        $result = $service->offboard_agent($userid, $USER->id);

        if ($result->residual) {
            // Agent status persists via another role — warn rather than claim success.
            redirect(
                new moodle_url('/local/moodesk/agents.php'),
                get_string('agents_offboard_residual', 'local_moodesk', $profile->fullname),
                null,
                \core\output\notification::NOTIFY_WARNING
            );
        }

        redirect(
            new moodle_url('/local/moodesk/agents.php'),
            get_string('agents_offboard_success', 'local_moodesk', (object) [
                'name'     => $profile->fullname,
                'requeued' => $result->requeued,
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

/*
 * -------------------------------------------------------------------------
 * Build template context.
 * -------------------------------------------------------------------------
 */

$templatectx = [
    'formurl'      => (new moodle_url('/local/moodesk/offboard_agent.php'))->out(false),
    'backurl'      => (new moodle_url('/local/moodesk/agents.php'))->out(false),
    'rolesurl'     => (new moodle_url('/admin/roles/manage.php'))->out(false),
    'userid'       => $userid,
    'fullname'     => $profile->fullname,
    'email'        => $profile->email,
    'managed'      => $profile->managed,
    'openworkload' => $profile->openworkload,
    'hasopen'      => $profile->openworkload > 0,
    // Names the plugin groups the removal also clears, when a plugin has them.
    'confirmbody'  => agent_service::membership_text('agents_offboard_confirm_body', $service->group_type([$userid])),
    'sesskey'      => sesskey(),
];

// The page header. Title only, deliberately: this is a confirmation screen, and
// the card below already offers Cancel in both of its branches. A second escape
// hatch beside the title would not be a second affordance, only more to read on
// the one screen where the user should be reading carefully.
$templatectx['pageheader'] = (new page_header(get_string('agents_offboard_heading', 'local_moodesk')))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/admin_agent_offboard', $templatectx)
);
