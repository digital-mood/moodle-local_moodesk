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
 * Admin settings page for the Help Desk plugin.
 *
 * Phase 1 settings:
 *  1. defaultcategoryid       — Default category for uncategorised tickets.
 *  2. attachments_enabled     — Allow file attachments on tickets and replies.
 *  3. max_attachment_size     — Max file size per attachment (bytes; 0 = site default).
 *  4. notify_requester_reply  — Notify requester when an agent posts a public reply.
 *  5. notify_assignee_assign  — Notify agent when they are assigned to a ticket.
 *  6. notify_agents_new       — Notify all agents when a new unassigned ticket is created.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_moodesk\service\edition_service;

if ($hassiteconfig) {
    // For local plugins Moodle does not always pre-create $settings before
    // including this file (unlike mod/block plugins). Create the page
    // explicitly and register it under the 'localplugins' node.
    if (!isset($settings) || !($settings instanceof admin_settingpage)) {
        $settings = new admin_settingpage(
            'local_moodesk',
            get_string('pluginname', 'local_moodesk')
        );
        $ADMIN->add('localplugins', $settings);
    }

    // Department management link — visible to users with :managedepartment.
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_moodesk_departments',
        get_string('dept_admin_heading', 'local_moodesk'),
        new moodle_url('/local/moodesk/departments.php'),
        'local/moodesk:managedepartment'
    ));

    // Roadmap (Pro+) admin links — registered only when the target page exists.
    // Each entry below points to a page that ships in a future release. We guard
    // every registration with file_exists() so the admin tree never offers a
    // link that 404s. Once the corresponding page lands, the guard becomes a
    // no-op and the link appears automatically.
    $roadmappages = [
        'local_moodesk_categories' => [
            'file'       => 'categories.php',
            'heading'    => 'cat_admin_heading',
            'capability' => 'local/moodesk:managecategory',
        ],
        'local_moodesk_kb' => [
            'file'       => 'kb_articles.php',
            'heading'    => 'kb_articles_heading',
            'capability' => 'local/moodesk:editkb',
        ],
    ];
    foreach ($roadmappages as $name => $cfg) {
        if (file_exists(__DIR__ . '/' . $cfg['file'])) {
            $ADMIN->add('localplugins', new admin_externalpage(
                $name,
                get_string($cfg['heading'], 'local_moodesk'),
                new moodle_url('/local/moodesk/' . $cfg['file']),
                $cfg['capability']
            ));
        }
    }

    /*
     * =========================================================================
     */

    $settings->add(new admin_setting_heading(
        'local_moodesk/general_heading',
        get_string('settings_general_heading', 'local_moodesk'),
        ''
    ));

    /*
     * -------------------------------------------------------------------------
     * 1. Default category
     * Populated dynamically from the live category table.
     * Falls back gracefully if no categories exist yet (during install).
     * -------------------------------------------------------------------------
     */

    // Build options lazily — the table may not exist during the very first install run.
    $categoryoptions = [];
    try {
        $categoryoptions = $DB->get_records_menu(
            'local_moodesk_category',
            ['visible' => 1],
            'sortorder ASC',
            'id,name'
        );
    } catch (\Throwable $e) {
        // Table does not exist yet (pre-install). Settings page still loads.
        $categoryoptions = [];
    }

    $settings->add(new admin_setting_configselect(
        'local_moodesk/defaultcategoryid',
        get_string('setting_defaultcategoryid', 'local_moodesk'),
        get_string('setting_defaultcategoryid_desc', 'local_moodesk'),
        0,
        $categoryoptions ?: [0 => get_string('none')]
    ));

    /*
     * -------------------------------------------------------------------------
     * 1b. Knowledge base enabled
     * A presentation switch: hides the Articles entry and turns its screens
     * into a notice. Not a rule of the domain — kb_service, the external
     * functions and future consumers do not consult it.
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configcheckbox(
        'local_moodesk/kb_enabled',
        get_string('setting_kb_enabled', 'local_moodesk'),
        get_string('setting_kb_enabled_desc', 'local_moodesk'),
        1
    ));

    /* ---- Knowledge base analytics retention (KB-07) ----------------------- */

    /*
     * -------------------------------------------------------------------------
     * 2. Attachments enabled
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configcheckbox(
        'local_moodesk/attachments_enabled',
        get_string('setting_attachments_enabled', 'local_moodesk'),
        get_string('setting_attachments_enabled_desc', 'local_moodesk'),
        0
    ));

    /*
     * -------------------------------------------------------------------------
     * 3. Max attachment size
     * Options mirror Moodle's standard upload size selector.
     * Value 0 means "use the site default" ($CFG->maxbytes).
     * -------------------------------------------------------------------------
     */

    $sizeoptions = get_max_upload_sizes($CFG->maxbytes);

    $settings->add(new admin_setting_configselect(
        'local_moodesk/max_attachment_size',
        get_string('setting_max_attachment_size', 'local_moodesk'),
        get_string('setting_max_attachment_size_desc', 'local_moodesk'),
        0,
        $sizeoptions
    ));

    /*
     * -------------------------------------------------------------------------
     * 4. Max files per attachment
     * Maximum number of files a user may attach per ticket or reply.
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configselect(
        'local_moodesk/max_files_per_attachment',
        get_string('setting_max_files_per_attachment', 'local_moodesk'),
        get_string('setting_max_files_per_attachment_desc', 'local_moodesk'),
        5,
        [1 => '1', 2 => '2', 3 => '3', 5 => '5', 10 => '10']
    ));

    /*
     * -------------------------------------------------------------------------
     * Notification settings heading
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_heading(
        'local_moodesk/notifications_heading',
        get_string('settings_notifications_heading', 'local_moodesk'),
        ''
    ));

    /*
     * -------------------------------------------------------------------------
     * 4. Notify requester on reply
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configcheckbox(
        'local_moodesk/notify_requester_reply',
        get_string('setting_notify_requester_reply', 'local_moodesk'),
        get_string('setting_notify_requester_reply_desc', 'local_moodesk'),
        1
    ));

    /*
     * -------------------------------------------------------------------------
     * 5. Notify assignee on assignment
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configcheckbox(
        'local_moodesk/notify_assignee_assign',
        get_string('setting_notify_assignee_assign', 'local_moodesk'),
        get_string('setting_notify_assignee_assign_desc', 'local_moodesk'),
        1
    ));

    /*
     * -------------------------------------------------------------------------
     * 6. Notify all agents on new unassigned ticket
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configcheckbox(
        'local_moodesk/notify_agents_new',
        get_string('setting_notify_agents_new', 'local_moodesk'),
        get_string('setting_notify_agents_new_desc', 'local_moodesk'),
        1
    ));

    /*
     * -------------------------------------------------------------------------
     * 7. Notify on SLA breach
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configcheckbox(
        'local_moodesk/notify_sla_breach',
        get_string('setting_notify_sla_breach', 'local_moodesk'),
        get_string('setting_notify_sla_breach_desc', 'local_moodesk'),
        1
    ));

    // Comma-separated Moodle user ids notified of an operational incident.
    // Empty → the site administrators.
    $settings->add(new admin_setting_configtext(
        'local_moodesk/email_alert_recipients',
        get_string('setting_email_alert_recipients', 'local_moodesk'),
        get_string('setting_email_alert_recipients_desc', 'local_moodesk'),
        '',
        PARAM_SEQUENCE
    ));

    // Operational incidents, next to the recipients of their alerts.
    $settings->add(new admin_setting_configselect(
        'local_moodesk/incident_alert_severity',
        get_string('setting_incident_alert_severity', 'local_moodesk'),
        get_string('setting_incident_alert_severity_desc', 'local_moodesk'),
        \local_moodesk\domain\incident::DEFAULT_ALERT_THRESHOLD,
        [
            \local_moodesk\domain\incident::SEVERITY_WARNING  =>
                get_string('incident_severity_warning', 'local_moodesk'),
            \local_moodesk\domain\incident::SEVERITY_ERROR    =>
                get_string('incident_severity_error', 'local_moodesk'),
            \local_moodesk\domain\incident::SEVERITY_CRITICAL =>
                get_string('incident_severity_critical', 'local_moodesk'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/incident_alert_cooldown',
        get_string('setting_incident_alert_cooldown', 'local_moodesk'),
        get_string('setting_incident_alert_cooldown_desc', 'local_moodesk'),
        60,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/incident_active_window_hours',
        get_string('setting_incident_active_window_hours', 'local_moodesk'),
        get_string('setting_incident_active_window_hours_desc', 'local_moodesk'),
        24,
        PARAM_INT
    ));

    // Retention deletes; the active window above only stops counting. They are
    // adjacent on screen because an operator reaching for one often wants the
    // other, and the descriptions have to make the difference unmissable.
    $settings->add(new admin_setting_configtext(
        'local_moodesk/incident_retention_days',
        get_string('setting_incident_retention_days', 'local_moodesk'),
        get_string('setting_incident_retention_days_desc', 'local_moodesk'),
        \local_moodesk\domain\incident::DEFAULT_RETENTION_DAYS,
        PARAM_INT
    ));

    /*
     * -------------------------------------------------------------------------
     * Automation settings heading
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_heading(
        'local_moodesk/automation_heading',
        get_string('settings_automation_heading', 'local_moodesk'),
        ''
    ));

    /*
     * -------------------------------------------------------------------------
     * 7. Auto-close resolved tickets after N days
     * 0 = disabled (default).
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configtext(
        'local_moodesk/autoclose_resolved_days',
        get_string('setting_autoclose_resolved_days', 'local_moodesk'),
        get_string('setting_autoclose_resolved_days_desc', 'local_moodesk'),
        0,
        PARAM_INT
    ));

    /*
     * -------------------------------------------------------------------------
     * SLA settings heading
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_heading(
        'local_moodesk/sla_heading',
        get_string('settings_sla_heading', 'local_moodesk'),
        get_string('settings_sla_desc', 'local_moodesk')
    ));

    /*
     * -------------------------------------------------------------------------
     * 8–11. First response SLA thresholds (hours per priority).
     * 0 = disabled.
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_response_hours_low',
        get_string('setting_sla_response_hours_low', 'local_moodesk'),
        get_string('setting_sla_response_hours_low_desc', 'local_moodesk'),
        72,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_response_hours_normal',
        get_string('setting_sla_response_hours_normal', 'local_moodesk'),
        get_string('setting_sla_response_hours_normal_desc', 'local_moodesk'),
        48,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_response_hours_high',
        get_string('setting_sla_response_hours_high', 'local_moodesk'),
        get_string('setting_sla_response_hours_high_desc', 'local_moodesk'),
        8,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_response_hours_urgent',
        get_string('setting_sla_response_hours_urgent', 'local_moodesk'),
        get_string('setting_sla_response_hours_urgent_desc', 'local_moodesk'),
        2,
        PARAM_INT
    ));

    /*
     * -------------------------------------------------------------------------
     * 12–15. Resolution SLA thresholds (hours per priority).
     * 0 = disabled.
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_resolve_hours_low',
        get_string('setting_sla_resolve_hours_low', 'local_moodesk'),
        get_string('setting_sla_resolve_hours_low_desc', 'local_moodesk'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_resolve_hours_normal',
        get_string('setting_sla_resolve_hours_normal', 'local_moodesk'),
        get_string('setting_sla_resolve_hours_normal_desc', 'local_moodesk'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_resolve_hours_high',
        get_string('setting_sla_resolve_hours_high', 'local_moodesk'),
        get_string('setting_sla_resolve_hours_high_desc', 'local_moodesk'),
        24,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_resolve_hours_urgent',
        get_string('setting_sla_resolve_hours_urgent', 'local_moodesk'),
        get_string('setting_sla_resolve_hours_urgent_desc', 'local_moodesk'),
        8,
        PARAM_INT
    ));

    /*
     * -------------------------------------------------------------------------
     * 16. SLA compliance target (percentage).
     * Empty = no target set; the dashboard then reports compliance without
     * claiming a goal it was never given.
     * -------------------------------------------------------------------------
     */

    $settings->add(new admin_setting_configtext(
        'local_moodesk/sla_compliance_target',
        get_string('setting_sla_compliance_target', 'local_moodesk'),
        get_string('setting_sla_compliance_target_desc', 'local_moodesk'),
        '',
        PARAM_INT
    ));
}
