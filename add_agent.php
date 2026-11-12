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
 * Agent management — onboard an agent (Agent Management A2).
 *
 * Search an existing Moodle user by name/email and grant them agent status
 * (assign the seeded mhd_agent role) without leaving mooDesk. Starter tier.
 * Initial department/team membership is deliberately out of scope here — that
 * UI belongs to A4.
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

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moodesk/add_agent.php'));
$PAGE->set_title(get_string('pluginname', 'local_moodesk') . ': ' . get_string('agents_add_heading', 'local_moodesk'));
$PAGE->set_heading(get_string('page_global_heading', 'local_moodesk'));

// The directory node stays highlighted while onboarding.
$apppage = new \local_moodesk\output\app_page($PAGE, 'agents');

$service = agent_service::create();
$search  = optional_param('search', '', PARAM_TEXT);

/*
 * -------------------------------------------------------------------------
 * Handle POST: grant agent status.
 * -------------------------------------------------------------------------
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);

    if ($action === 'grant') {
        $userid = required_param('userid', PARAM_INT);

        try {
            $service->grant_agent($userid, $USER->id);
        } catch (moodle_exception $e) {
            // The entitlement gate is an expected outcome of this form, not a
            // fault: it comes back as a notification on this screen, where the
            // operator can read the count and decide what to do. Every other
            // failure keeps Moodle's error page.
            if ($e->errorcode !== 'agents_limit_reached') {
                throw $e;
            }
            redirect(
                new moodle_url('/local/moodesk/add_agent.php', ['search' => $search]),
                $e->getMessage(),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }

        redirect(
            new moodle_url('/local/moodesk/agents.php'),
            get_string('agents_add_success', 'local_moodesk'),
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

$search     = trim($search);
$searched   = ($search !== '');
$candidates = $searched ? $service->search_candidate_users($search, $USER->id) : [];

$rows = [];
foreach ($candidates as $candidate) {
    // The entity cell's figure. `link => false` because the row's action is to
    // grant the role, not to visit a profile.
    $userpic       = new user_picture($candidate->user);
    $userpic->size = 36;
    $userpic->link = false;

    $rows[] = [
        'id'       => $candidate->id,
        'fullname' => $candidate->fullname,
        'email'    => $candidate->email,
        'avatar'   => $OUTPUT->render($userpic),
    ];
}

// The entitlement, read once for the screen. Where a grant at the cap is refused
// the grant buttons are disabled and a notice says why, so the operator learns
// the capacity before choosing a person, not after.
$entitlement = \local_moodesk\service\entitlement_service::export_entitlement();
$refused = $entitlement['atlimit'] && $entitlement['refuses'];

$templatectx = [
    'formurl'        => (new moodle_url('/local/moodesk/add_agent.php'))->out(false),
    'search'         => $search,
    'searched'       => $searched,
    'candidates'     => $rows,
    'hascandidates'  => !empty($rows),
    'sesskey'        => sesskey(),
    'atlimit'        => $refused,
    'limitnotice'    => $refused
        ? get_string('agents_add_limit_reached', 'local_moodesk', (object) [
            'active'   => $entitlement['active'],
            'capacity' => $entitlement['limit'],
            'edition'  => $entitlement['editionlabel'],
        ])
        : '',
];

// The page header. One way back and no primary action: this screen's commitment
// is made by the form below it, not by a button beside the title.
$templatectx['pageheader'] = (new page_header(get_string('agents_add_heading', 'local_moodesk')))
    ->with_description(get_string('agents_add_intro', 'local_moodesk'))
    ->with_action(new header_action(
        get_string('agents_add_back', 'local_moodesk'),
        new moodle_url('/local/moodesk/agents.php'),
    ))
    ->to_context();

echo $apppage->render_within_shell(
    $OUTPUT->render_from_template('local_moodesk/admin_agent_add', $templatectx)
);
