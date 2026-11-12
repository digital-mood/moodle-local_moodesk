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
 * Plugin upgrade steps.
 *
 * Each version block is idempotent: it checks for the existence of
 * tables/columns before adding them so re-running does not cause errors.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute plugin upgrade steps from the old version to the current version.
 *
 * @param int $oldversion Plugin version we are upgrading from.
 * @return bool
 */
function xmldb_local_moodesk_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // Every block before the 2026081000 rename addresses tables by their old
    // local_mhd_ names, and most of them call field_exists()/find_key_name()
    // without first checking the table itself — which throws outright when the
    // table is absent. So they are gated on the schema still being the legacy
    // one. A site installed fresh from install.xml already carries the new
    // names and has no history to replay; a site that has already been renamed
    // has nothing left to apply either. Blocks added *after* the rename must
    // not carry this guard.
    $legacyschema = $dbman->table_exists(new xmldb_table('local_mhd_ticket'));

    /*
     * -------------------------------------------------------------------------
     * Phase 3 — version 2026040702
     * Introduces: departments, department membership, departmentid on tickets
     * and categories.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026040702 && $legacyschema) {
        /* ---- 1. Create local_mhd_department --------------------------------- */

        $table = new xmldb_table('local_mhd_department');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('isactive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ix_isactive', XMLDB_INDEX_NOTUNIQUE, ['isactive']);

            $dbman->create_table($table);
        }

        /* ---- 2. Create local_mhd_dept_member -------------------------------- */

        $table = new xmldb_table('local_mhd_dept_member');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('departmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_dept', XMLDB_KEY_FOREIGN, ['departmentid'], 'local_mhd_department', ['id']);
            $table->add_key('uq_dept_user', XMLDB_KEY_UNIQUE, ['departmentid', 'userid']);
            $table->add_index('ix_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

            $dbman->create_table($table);
        }

        /* ---- 3. Add departmentid to local_mhd_ticket ----------------------- */

        $table = new xmldb_table('local_mhd_ticket');
        $field = new xmldb_field('departmentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'categoryid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add FK: local_mhd_ticket.departmentid → local_mhd_department.id
        // The FK implicitly creates the underlying index; no separate ix_departmentid needed.
        // Safe to add: all existing rows will have NULL for this column.
        $key = new xmldb_key('fk_deptid', XMLDB_KEY_FOREIGN, ['departmentid'], 'local_mhd_department', ['id']);
        $dbman->add_key($table, $key);

        /* ---- 4. Add departmentid to local_mhd_category --------------------- */

        $table = new xmldb_table('local_mhd_category');
        $field = new xmldb_field('departmentid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'parentid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add index on departmentid for category table.
        $index = new xmldb_index('ix_departmentid', XMLDB_INDEX_NOTUNIQUE, ['departmentid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        /* ---- Save the version milestone ------------------------------------ */

        upgrade_plugin_savepoint(true, 2026040702, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 4 — version 2026040901
     * Adds: ix_departmentid_status composite index on local_mhd_ticket.
     * Supports VIEW_DEPARTMENT agent workspace queries at volume.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026040901 && $legacyschema) {
        $table = new xmldb_table('local_mhd_ticket');
        $index = new xmldb_index('ix_departmentid_status', XMLDB_INDEX_NOTUNIQUE, ['departmentid', 'status']);

        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026040901, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 5 — version 2026040902
     * No schema changes. Registers the new check_sla_breaches scheduled task
     * and adds SLA configuration settings. Version bump triggers task re-sync.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026040902 && $legacyschema) {
        upgrade_plugin_savepoint(true, 2026040902, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 6 — version 2026040903
     * No schema changes. Attachments are stored entirely via the Moodle Files API.
     * File areas: reply_attachment (itemid = reply id, covers both initial ticket
     * message and subsequent replies). The legacy ticket_attachment area is still
     * served by pluginfile() for backwards compatibility with any existing data.
     * New setting: max_files_per_attachment (default 5).
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026040903 && $legacyschema) {
        upgrade_plugin_savepoint(true, 2026040903, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 7 — version 2026040904
     * No schema changes. Reporting & Analytics are read-only aggregate queries
     * on existing tables (local_mhd_ticket, local_mhd_department, user).
     * Adds: local/moodesk:viewreports capability (defined in access.php).
     * Version bump triggers Moodle capability re-sync on the next upgrade run.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026040904 && $legacyschema) {
        upgrade_plugin_savepoint(true, 2026040904, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 8 — version 2026040905
     * Introduces: automation rules engine.
     * - local_mhd_rule        : rule definitions (trigger + conditions + actions JSON)
     * - local_mhd_rule_log    : immutable execution log per (rule, ticket) pair
     * No changes to existing tables.
     * Adds: local/moodesk:manageautomations capability (defined in access.php).
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026040905 && $legacyschema) {
        /* ---- 1. Create local_mhd_rule --------------------------------------- */

        $table = new xmldb_table('local_mhd_rule');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('isactive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('trigger_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('trigger_config', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('conditions', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('actions', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('sort_order', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ix_trigger_isactive', XMLDB_INDEX_NOTUNIQUE, ['trigger_type', 'isactive']);
            $table->add_index('ix_sort_order', XMLDB_INDEX_NOTUNIQUE, ['sort_order']);

            $dbman->create_table($table);
        }

        /* ---- 2. Create local_mhd_rule_log ----------------------------------- */

        $table = new xmldb_table('local_mhd_rule_log');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('ruleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('triggered_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('success', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL);
            $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_ruleid', XMLDB_KEY_FOREIGN, ['ruleid'], 'local_mhd_rule', ['id']);
            $table->add_key('fk_ticketid', XMLDB_KEY_FOREIGN, ['ticketid'], 'local_mhd_ticket', ['id']);
            $table->add_index('ix_ruleid_ticketid', XMLDB_INDEX_NOTUNIQUE, ['ruleid', 'ticketid']);
            $table->add_index('ix_triggered_at', XMLDB_INDEX_NOTUNIQUE, ['triggered_at']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026040905, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 9 — version 2026040906
     * Introduces: email ingestion pipeline.
     * - local_mhd_email_message : threading metadata for all processed emails.
     * One row per email. Used for deduplication (uq_message_id), threading
     * (In-Reply-To / References resolution), and audit trail.
     * No changes to existing tables.
     * Adds: process_incoming_email scheduled task (registered via db/tasks.php).
     * New settings: email_ingestion_enabled, IMAP connection params, dedup window.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026040906 && $legacyschema) {
        $table = new xmldb_table('local_mhd_email_message');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('replyid', XMLDB_TYPE_INTEGER, '10', null, null);
            $table->add_field('message_id', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('in_reply_to', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('references_header', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('sender_email', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('sender_name', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('raw_subject', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('role', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_ticketid', XMLDB_KEY_FOREIGN, ['ticketid'], 'local_mhd_ticket', ['id']);

            $table->add_index('uq_message_id', XMLDB_INDEX_UNIQUE, ['message_id']);
            $table->add_index('ix_sender_timecreated', XMLDB_INDEX_NOTUNIQUE, ['sender_email', 'timecreated']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026040906, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 10 — version 2026041001
     * Introduces: REST API layer and outbound webhooks.
     * - local_mhd_webhook     : webhook subscription (url, secret, events JSON)
     * - local_mhd_webhook_log : immutable delivery log per dispatch attempt
     * No changes to existing tables.
     * Adds: local/moodesk:useapi and :managewebhooks capabilities.
     * Registers: moodesk_api external service via db/services.php.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026041001 && $legacyschema) {
        /* ---- 1. Create local_mhd_webhook ------------------------------------ */

        $table = new xmldb_table('local_mhd_webhook');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('url', XMLDB_TYPE_CHAR, '1000', null, XMLDB_NOTNULL);
            $table->add_field('secret', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('events', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('isactive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ix_isactive', XMLDB_INDEX_NOTUNIQUE, ['isactive']);

            $dbman->create_table($table);
        }

        /* ---- 2. Create local_mhd_webhook_log -------------------------------- */

        $table = new xmldb_table('local_mhd_webhook_log');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('webhookid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('event_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('http_status', XMLDB_TYPE_INTEGER, '4', null, null);
            $table->add_field('response_body', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('success', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_webhookid', XMLDB_KEY_FOREIGN, ['webhookid'], 'local_mhd_webhook', ['id']);
            $table->add_index('ix_webhookid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['webhookid', 'timecreated']);
            $table->add_index('ix_ticketid', XMLDB_INDEX_NOTUNIQUE, ['ticketid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041001, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 11.A — version 2026041102
     * Introduces: per-department SLA overrides.
     * Adds 8 nullable integer columns to local_mhd_department:
     * sla_response_hours_{low,normal,high,urgent}
     * sla_resolve_hours_{low,normal,high,urgent}
     * NULL = inherit from the global plugin SLA settings.
     * A numeric value (including 0 to disable) overrides the global threshold
     * for tickets belonging to that department.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026041102 && $legacyschema) {
        $table = new xmldb_table('local_mhd_department');

        $slafields = [
            'sla_response_hours_low'    => 'First-response SLA override for Low priority (hours). NULL=inherit global.',
            'sla_response_hours_normal' => 'First-response SLA override for Normal priority (hours). NULL=inherit global.',
            'sla_response_hours_high'   => 'First-response SLA override for High priority (hours). NULL=inherit global.',
            'sla_response_hours_urgent' => 'First-response SLA override for Urgent priority (hours). NULL=inherit global.',
            'sla_resolve_hours_low'     => 'Resolution SLA override for Low priority (hours). NULL=inherit global.',
            'sla_resolve_hours_normal'  => 'Resolution SLA override for Normal priority (hours). NULL=inherit global.',
            'sla_resolve_hours_high'    => 'Resolution SLA override for High priority (hours). NULL=inherit global.',
            'sla_resolve_hours_urgent'  => 'Resolution SLA override for Urgent priority (hours). NULL=inherit global.',
        ];

        foreach ($slafields as $fieldname => $comment) {
            $field = new xmldb_field($fieldname, XMLDB_TYPE_INTEGER, '4', null, null, null, null);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026041102, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 11.C — version 2026041104
     * Introduces: custom ticket fields.
     * - local_mhd_custom_field       : field definitions (type, options, visibility)
     * - local_mhd_custom_field_value : per-ticket field values (upsert via uq_ticket_field)
     * No changes to existing tables.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026041104 && $legacyschema) {
        /* ---- 1. Create local_mhd_custom_field -------------------------------- */

        $table = new xmldb_table('local_mhd_custom_field');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('description', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('fieldtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
            $table->add_field('options', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('visibility', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'all');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('isactive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ix_isactive_sortorder', XMLDB_INDEX_NOTUNIQUE, ['isactive', 'sortorder']);

            $dbman->create_table($table);
        }

        /* ---- 2. Create local_mhd_custom_field_value -------------------------- */

        $table = new xmldb_table('local_mhd_custom_field_value');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('fieldid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('value', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_ticketid', XMLDB_KEY_FOREIGN, ['ticketid'], 'local_mhd_ticket', ['id']);
            $table->add_key('fk_fieldid', XMLDB_KEY_FOREIGN, ['fieldid'], 'local_mhd_custom_field', ['id']);
            $table->add_key('uq_ticket_field', XMLDB_KEY_UNIQUE, ['ticketid', 'fieldid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041104, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 11.D — version 2026041105
     * Introduces: CSAT satisfaction surveys.
     * - local_mhd_csat_rating : one row per ticket, stores invitation token,
     * rating (1-5), optional comment, and submission timestamp.
     * NULL rating = survey sent but requester has not yet responded.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026041105 && $legacyschema) {
        $table = new xmldb_table('local_mhd_csat_rating');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('rating', XMLDB_TYPE_INTEGER, '1', null, null);                          // NULL until submitted.
            $table->add_field('comment', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $table->add_field('token_expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            // NULL until submitted.
            $table->add_field('submitted_at', XMLDB_TYPE_INTEGER, '10', null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_ticketid', XMLDB_KEY_FOREIGN, ['ticketid'], 'local_mhd_ticket', ['id']);
            $table->add_key('uq_ticketid', XMLDB_KEY_UNIQUE, ['ticketid']);

            $table->add_index('uq_token', XMLDB_INDEX_UNIQUE, ['token']);
            $table->add_index('ix_submitted_at', XMLDB_INDEX_NOTUNIQUE, ['submitted_at']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026041105, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 11.E — version 2026041106
     * Introduces: agent teams.
     * - local_mhd_team        : team definitions (name, description, isactive)
     * - local_mhd_team_member : agent-to-team membership (teamid, userid)
     * - local_mhd_ticket.teamid : nullable FK — ticket can be assigned to a team
     * All team members gain visibility of tickets assigned to their team.
     * NULL teamid on existing tickets = no team assigned (backward compatible).
     * Adds: local/moodesk:manageteam capability (defined in access.php).
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026041106 && $legacyschema) {
        /* ---- 1. Create local_mhd_team ---------------------------------------- */

        $table = new xmldb_table('local_mhd_team');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('isactive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ix_isactive', XMLDB_INDEX_NOTUNIQUE, ['isactive']);

            $dbman->create_table($table);
        }

        /* ---- 2. Create local_mhd_team_member --------------------------------- */

        $table = new xmldb_table('local_mhd_team_member');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('teamid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_teamid', XMLDB_KEY_FOREIGN, ['teamid'], 'local_mhd_team', ['id']);
            $table->add_key('uq_team_user', XMLDB_KEY_UNIQUE, ['teamid', 'userid']);
            $table->add_index('ix_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

            $dbman->create_table($table);
        }

        /* ---- 3. Add teamid column to local_mhd_ticket ----------------------- */

        $table = new xmldb_table('local_mhd_ticket');
        $field = new xmldb_field('teamid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add FK after the column exists.
        $key = new xmldb_key('fk_teamid', XMLDB_KEY_FOREIGN, ['teamid'], 'local_mhd_team', ['id']);
        if (!$dbman->find_key_name($table, $key)) {
            $dbman->add_key($table, $key);
        }

        // Add supporting index.
        $index = new xmldb_index('ix_teamid', XMLDB_INDEX_NOTUNIQUE, ['teamid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026041106, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 1.5c — version 2026051004
     * Introduces: local_mhd_webhook_queue (async dispatch queue + retry state).
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026051004 && $legacyschema) {
        $table = new xmldb_table('local_mhd_webhook_queue');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('webhookid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('event_type', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
            $table->add_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('payload', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('next_retry_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('last_error', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_webhookid', XMLDB_KEY_FOREIGN, ['webhookid'], 'local_mhd_webhook', ['id']);
            $table->add_index('ix_status_next_retry', XMLDB_INDEX_NOTUNIQUE, ['status', 'next_retry_at']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026051004, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Stage 3 (email) — version 2026053002
     * Unknown-sender strict reject policy: an inbound email whose sender is not
     * an active Moodle user is rejected and recorded as an audit-only row.
     * Such rows carry no ticket, so local_mhd_email_message.ticketid becomes
     * nullable and a reject_reason column records why the email was rejected.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026053002 && $legacyschema) {
        $table = new xmldb_table('local_mhd_email_message');

        // Step 1: make ticketid nullable (drop/re-add the FK around the change).
        $field = new xmldb_field('ticketid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if ($dbman->field_exists($table, $field)) {
            $key = new xmldb_key('fk_ticketid', XMLDB_KEY_FOREIGN, ['ticketid'], 'local_mhd_ticket', ['id']);
            $dbman->drop_key($table, $key);
            $dbman->change_field_notnull($table, $field);
            $dbman->add_key($table, $key);
        }

        // Step 2: add reject_reason.
        $field = new xmldb_field('reject_reason', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'role');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026053002, 'local', 'moodesk');
    }

    // Phase 10 (Enterprise) — version 2026060100.
    // REST API: companion metadata table for mooDesk-issued native web service
    // tokens. Auth rides on {external_tokens}; this table only stores the
    // friendly label, granted scopes and ownership/audit data.
    if ($oldversion < 2026060100 && $legacyschema) {
        $table = new xmldb_table('local_mhd_api_token');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('externaltokenid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            $table->add_field('scopes', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('uq_externaltokenid', XMLDB_KEY_UNIQUE, ['externaltokenid']);
            $table->add_index('ix_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026060100, 'local', 'moodesk');
    }

    // JWT Gate #3 — version 2026060300.
    // Exit COMPATIBILITY_MODE: the legacy `local_moodesk/edition` selector is
    // gone and its config row no longer influences edition resolution. Drop the
    // orphaned row so the admin DB does not keep dead config around. Idempotent:
    // unset_config is a no-op when the row is already absent.
    //
    // No $legacyschema guard here — this touches config, not tables, so it has
    // to run on a renamed site too. The guard belongs only to blocks that
    // address tables by their old names.
    if ($oldversion < 2026060300) {
        unset_config('edition', 'local_moodesk');

        upgrade_plugin_savepoint(true, 2026060300, 'local', 'moodesk');
    }

    // Scope enforcement (D2) — version 2026080700.
    //
    // Until 2.7.0 a token's scope list was stored but never consulted:
    // authorization came only from the owner's capabilities, so a token issued
    // with no scopes ticked (the api_tokens.php form defaults to an empty
    // array) worked exactly like a fully-scoped one.
    //
    // Enforcement changes that: an empty list now denies every scope. Left
    // alone, those pre-existing tokens would silently start returning
    // authorization failures on an upgrade the admin had no reason to link to
    // their integrations breaking.
    //
    // Backfill them with the full scope set, which is the access they
    // effectively had before. Tokens that already carry an explicit scope list
    // are untouched, and tokens issued from here on keep the strict semantics.
    if ($oldversion < 2026080700 && $legacyschema) {
        $legacy = $DB->get_records_select(
            'local_mhd_api_token',
            $DB->sql_compare_text('scopes') . ' = ? OR scopes IS NULL',
            ['[]'],
            '',
            'id, scopes'
        );

        if ($legacy) {
            // Written out: the list left for local_moodeskpro with the API.
            $full = json_encode([
                'tickets:read',
                'tickets:write',
                'webhooks:read',
                'webhooks:write',
                'kb:read',
                'kb:write',
                'kb:analytics',
            ]);
            foreach ($legacy as $row) {
                $row->scopes = $full;
                $DB->update_record('local_mhd_api_token', $row);
            }
            // Surfaced in the upgrade output so the admin can see the grant
            // rather than discovering it by reading the tokens page later.
            upgrade_log(
                UPGRADE_LOG_NORMAL,
                'local_moodesk',
                'Backfilled ' . count($legacy) . ' unscoped API token(s) with the full scope set'
            );
        }

        upgrade_plugin_savepoint(true, 2026080700, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Table prefix — version 2026081000
     * Renames every table from the historical local_mhd_ prefix to the plugin's
     * own component name. Moodle expects a plugin's tables to start with its
     * frankenstyle component, and moodle-plugin-ci's `validate` step enforces
     * it; local_mhd_ predates the rename to mooDesk.
     *
     * Note that every step above this one still refers to the old names on
     * purpose: they ran before this rename, and rewriting them would make an
     * upgrade from an older release address tables that do not carry the new
     * name yet.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026081000) {
        // The custom-field pair is shortened rather than prefixed verbatim:
        // local_moodesk_custom_field_value would be 32 characters and Moodle
        // caps table names at 28. Both halves move together to stay symmetric.
        $renames = [
            'local_mhd_department'         => 'local_moodesk_department',
            'local_mhd_dept_member'        => 'local_moodesk_dept_member',
            'local_mhd_category'           => 'local_moodesk_category',
            'local_mhd_ticket'             => 'local_moodesk_ticket',
            'local_mhd_reply'              => 'local_moodesk_reply',
            'local_mhd_ticket_history'     => 'local_moodesk_ticket_history',
            'local_mhd_rule'               => 'local_moodesk_rule',
            'local_mhd_rule_log'           => 'local_moodesk_rule_log',
            'local_mhd_email_message'      => 'local_moodesk_email_message',
            'local_mhd_webhook'            => 'local_moodesk_webhook',
            'local_mhd_webhook_log'        => 'local_moodesk_webhook_log',
            'local_mhd_webhook_queue'      => 'local_moodesk_webhook_queue',
            'local_mhd_custom_field'       => 'local_moodesk_cfield',
            'local_mhd_custom_field_value' => 'local_moodesk_cfield_value',
            'local_mhd_csat_rating'        => 'local_moodesk_csat_rating',
            'local_mhd_team'               => 'local_moodesk_team',
            'local_mhd_team_member'        => 'local_moodesk_team_member',
            'local_mhd_api_token'          => 'local_moodesk_api_token',
        ];

        $renamed = 0;
        foreach ($renames as $oldname => $newname) {
            $old = new xmldb_table($oldname);
            $new = new xmldb_table($newname);

            // Idempotent, like every other step here: skip when the source is
            // gone or the destination already exists, so a re-run is a no-op.
            if ($dbman->table_exists($old) && !$dbman->table_exists($new)) {
                $dbman->rename_table($old, $newname);
                $renamed++;
            }
        }

        // Surfaced in the upgrade output because this touches every table the
        // plugin owns — an admin should see it happen, not infer it.
        upgrade_log(
            UPGRADE_LOG_NORMAL,
            'local_moodesk',
            'Renamed ' . $renamed . ' table(s) from the local_mhd_ prefix to local_moodesk_'
        );

        upgrade_plugin_savepoint(true, 2026081000, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Phase 12 — version 2026081200
     * Ticket merge (Enterprise), F0: data foundations only. No service, no UI.
     * - local_moodesk_merge_audit : one row per merge, holding the snapshot that
     *   revert reads back. Never hard-deleted; the TTL only flips a column.
     * - local_moodesk_ticket gains merged_status + merged_audit_id.
     *
     * mergedinto is deliberately NOT dropped. It survives one compat release so
     * external readers keep working, and goes in the release after this one.
     *
     * This step carries no $legacyschema guard, and neither should the ones
     * after it: that guard belongs to the blocks written before the local_mhd_
     * rename, and copying it here would stop the migration from ever running on
     * a site that is already renamed.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026081200) {
        /* ---- 1. Create local_moodesk_merge_audit ---------------------------- */

        $table = new xmldb_table('local_moodesk_merge_audit');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('sourceticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('targetticketid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('mergedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $table->add_field('reason', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('source_snapshot', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('migrated_items', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $table->add_field('conflicts', XMLDB_TYPE_TEXT, null, null, null);
            $table->add_field('revertedat', XMLDB_TYPE_INTEGER, '10', null, null);
            $table->add_field('reverted_actorid', XMLDB_TYPE_INTEGER, '10', null, null);
            $table->add_field('ttl_archivedat', XMLDB_TYPE_INTEGER, '10', null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_source', XMLDB_KEY_FOREIGN, ['sourceticketid'], 'local_moodesk_ticket', ['id']);
            $table->add_key('fk_target', XMLDB_KEY_FOREIGN, ['targetticketid'], 'local_moodesk_ticket', ['id']);

            $table->add_index('ix_mergedat', XMLDB_INDEX_NOTUNIQUE, ['mergedat']);
            $table->add_index('ix_actorid', XMLDB_INDEX_NOTUNIQUE, ['actorid']);

            $dbman->create_table($table);
        }

        /* ---- 2. Add merged_status + merged_audit_id to the ticket table ----- */

        $tickettable = new xmldb_table('local_moodesk_ticket');

        $mergedstatus = new xmldb_field(
            'merged_status',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'active',
            'mergedinto'
        );

        if (!$dbman->field_exists($tickettable, $mergedstatus)) {
            $dbman->add_field($tickettable, $mergedstatus);
        }

        $mergedauditid = new xmldb_field(
            'merged_audit_id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'merged_status'
        );

        if (!$dbman->field_exists($tickettable, $mergedauditid)) {
            $dbman->add_field($tickettable, $mergedauditid);
        }

        /* ---- 3. Backfill merged_status from the legacy mergedinto column ---- */

        // The enum values are written as literals, not as ticket:: constants.
        // An upgrade step has to keep meaning what it meant the day it shipped;
        // reading a constant would let a later rename rewrite history.
        // They mirror ticket::MERGED_STATUS_* as of this version.

        // Sources first: a ticket carrying mergedinto was merged away.
        $DB->execute(
            "UPDATE {local_moodesk_ticket}
                SET merged_status = ?
              WHERE mergedinto IS NOT NULL
                AND merged_status = ?",
            ['merged_into', 'active']
        );

        // Then the tickets those rows pointed at. Two statements on purpose:
        // MySQL refuses a subquery that reads the table being updated. The
        // 'active' filter keeps a ticket that is both source and target of a
        // legacy chain marked merged_into, which is the read-only state.
        $legacytargets = $DB->get_fieldset_sql(
            "SELECT DISTINCT mergedinto
               FROM {local_moodesk_ticket}
              WHERE mergedinto IS NOT NULL"
        );

        if ($legacytargets) {
            [$insql, $inparams] = $DB->get_in_or_equal($legacytargets, SQL_PARAMS_NAMED, 'tid');
            $DB->execute(
                "UPDATE {local_moodesk_ticket}
                    SET merged_status = :merged
                  WHERE id $insql
                    AND merged_status = :active",
                $inparams + ['merged' => 'merged_target', 'active' => 'active']
            );
        }

        upgrade_plugin_savepoint(true, 2026081200, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * SLA resolution pause — version 2026081400
     * The resolution clock used to run from timecreated to now with nothing
     * able to stop it, so a ticket waiting weeks on the requester breached by
     * arithmetic alone. It now stops while the ticket sits in pending, which
     * needs two columns of state on the ticket:
     *   - sla_paused_seconds : every closed pause interval, summed.
     *   - sla_paused_since   : the open interval, or NULL when not paused.
     *
     * Existing rows carry no such state, but local_moodesk_ticket_history has
     * recorded every status change all along, so the totals are rebuilt from
     * that trail rather than written off. Tickets whose trail is missing or
     * incomplete fall back to timemodified, which is documented in
     * docs/sla-resolution-pause.md and is the one place this step guesses.
     *
     * Status and action values are written as literals, not as ticket::
     * constants: an upgrade step has to keep meaning what it meant the day it
     * shipped, and reading a constant would let a later rename rewrite history.
     * They mirror ticket::STATUS_* as of this version — 0=new, 1=open,
     * 2=pending, 3=on_hold, 4=resolved, 5=closed, 6=in_progress.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026081400) {
        $tickettable = new xmldb_table('local_moodesk_ticket');

        /* ---- 1. Add the two pause columns ----------------------------------- */

        $pausedseconds = new xmldb_field(
            'sla_paused_seconds',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'sla_breached'
        );

        if (!$dbman->field_exists($tickettable, $pausedseconds)) {
            $dbman->add_field($tickettable, $pausedseconds);
        }

        $pausedsince = new xmldb_field(
            'sla_paused_since',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'sla_paused_seconds'
        );

        if (!$dbman->field_exists($tickettable, $pausedsince)) {
            $dbman->add_field($tickettable, $pausedsince);
        }

        /* ---- 2. Rebuild the pause totals from ticket history ----------------- */

        // Keyset pagination over tickets, pulling each batch's history in one
        // query. Ordering the trail by (ticketid, timecreated, id) keeps two
        // events sharing a timestamp in a deterministic order, so a re-run
        // folds them the same way round.
        $lastid    = 0;
        $batchsize = 500;

        while (true) {
            $tickets = $DB->get_records_sql(
                "SELECT id, status, timemodified, sla_paused_seconds, sla_paused_since
                   FROM {local_moodesk_ticket}
                  WHERE id > :lastid
               ORDER BY id ASC",
                ['lastid' => $lastid],
                0,
                $batchsize
            );

            if (!$tickets) {
                break;
            }

            $ids    = array_keys($tickets);
            $lastid = (int) end($ids);

            [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'tid');

            $events = $DB->get_records_sql(
                "SELECT id, ticketid, newvalue, timecreated
                   FROM {local_moodesk_ticket_history}
                  WHERE ticketid {$insql}
                    AND action = :action
               ORDER BY ticketid ASC, timecreated ASC, id ASC",
                $inparams + ['action' => 'status_changed']
            );

            $totals = [];  // Ticket id => seconds accumulated across closed pauses.
            $openat = [];  // Ticket id => start of a pause the trail never closed.

            foreach ($events as $event) {
                $decoded = json_decode((string) $event->newvalue, true);

                if (!is_array($decoded) || !array_key_exists('status', $decoded)) {
                    continue;
                }

                $ticketid = (int) $event->ticketid;
                $status   = (int) $decoded['status'];
                $at       = (int) $event->timecreated;

                if ($status === 2) {
                    // Entering pending. Two consecutive entries do not restart the
                    // clock — the first one already opened the interval, and the
                    // second is a duplicate the state machine should have refused.
                    if (!isset($openat[$ticketid])) {
                        $openat[$ticketid] = $at;
                    }
                    continue;
                }

                if (isset($openat[$ticketid])) {
                    $totals[$ticketid] = ($totals[$ticketid] ?? 0) + max(0, $at - $openat[$ticketid]);
                    unset($openat[$ticketid]);
                }
            }

            foreach ($tickets as $ticket) {
                $ticketid  = (int) $ticket->id;
                $total     = $totals[$ticketid] ?? 0;
                $since     = null;
                $ispending = ((int) $ticket->status === 2);

                if (isset($openat[$ticketid])) {
                    if ($ispending) {
                        $since = $openat[$ticketid];
                    } else {
                        // The trail opened a pause nothing closed, yet the ticket is
                        // not pending any more: the closing transition never reached
                        // history. timemodified is the last moment the row is known
                        // to have changed, so the pause is capped there.
                        $total += max(0, (int) $ticket->timemodified - $openat[$ticketid]);
                    }
                } else if ($ispending) {
                    // Pending with nothing in history to account for it — a ticket
                    // older than the history table, or one written by a path that
                    // skipped it. Assume the pause started at timemodified.
                    $since = (int) $ticket->timemodified;
                }

                // Assignment, never accumulation: running this step twice has to
                // land on the same numbers as running it once.
                $currentseconds = (int) $ticket->sla_paused_seconds;
                $currentsince   = $ticket->sla_paused_since !== null ? (int) $ticket->sla_paused_since : null;

                if ($currentseconds === $total && $currentsince === $since) {
                    continue;
                }

                $update                     = new stdClass();
                $update->id                 = $ticketid;
                $update->sla_paused_seconds = $total;
                $update->sla_paused_since   = $since;

                $DB->update_record('local_moodesk_ticket', $update);
            }
        }

        /* ---- 3. Re-evaluate sla_breached on tickets that are still active ---- */

        // The scheduled task only ever set this flag and never cleared it, so
        // every ticket the old arithmetic condemned is still flagged. Clear the
        // ones the paused clock no longer puts past their deadline. Terminal
        // tickets are left alone: their flag is history, not current state.
        //
        // The threshold arithmetic is spelled out here rather than delegated to
        // sla_service for the same reason the literals above are: this step must
        // not follow the service into whatever it becomes in a later release.
        $suffixes         = [0 => 'low', 1 => 'normal', 2 => 'high', 3 => 'urgent'];
        $globalthresholds = [];

        foreach ($suffixes as $priority => $suffix) {
            $globalthresholds[$priority] = [
                'response' => (int) get_config('local_moodesk', "sla_response_hours_{$suffix}") * 3600,
                'resolve'  => (int) get_config('local_moodesk', "sla_resolve_hours_{$suffix}") * 3600,
            ];
        }

        // NULL in a department column means "inherit the global value"; a stored
        // integer, 0 included, overrides it.
        $departments   = $DB->get_records('local_moodesk_department');
        $deptoverrides = [];

        foreach ($departments as $department) {
            foreach ($suffixes as $priority => $suffix) {
                $respfield = "sla_response_hours_{$suffix}";
                $resfield  = "sla_resolve_hours_{$suffix}";

                $deptoverrides[(int) $department->id][$priority] = [
                    'response' => isset($department->$respfield) && $department->$respfield !== null
                        ? (int) $department->$respfield * 3600
                        : $globalthresholds[$priority]['response'],
                    'resolve'  => isset($department->$resfield) && $department->$resfield !== null
                        ? (int) $department->$resfield * 3600
                        : $globalthresholds[$priority]['resolve'],
                ];
            }
        }

        $now    = time();
        $lastid = 0;

        // Active statuses, listed rather than compared: STATUS_INPROGRESS is 6,
        // above STATUS_RESOLVED at 4, so "status < 4" would silently drop every
        // in-progress ticket.
        [$activesql, $activeparams] = $DB->get_in_or_equal([0, 1, 2, 3, 6], SQL_PARAMS_NAMED, 'act');

        while (true) {
            $flagged = $DB->get_records_sql(
                "SELECT id, priority, departmentid, timecreated, timefirstresponse, timeresolved,
                        status, sla_paused_seconds, sla_paused_since
                   FROM {local_moodesk_ticket}
                  WHERE id > :lastid
                    AND sla_breached = 1
                    AND status {$activesql}
               ORDER BY id ASC",
                $activeparams + ['lastid' => $lastid],
                0,
                $batchsize
            );

            if (!$flagged) {
                break;
            }

            $ids    = array_keys($flagged);
            $lastid = (int) end($ids);

            foreach ($flagged as $ticket) {
                $priority     = (int) $ticket->priority;
                $departmentid = $ticket->departmentid !== null ? (int) $ticket->departmentid : null;

                $thresholds = ($departmentid !== null && isset($deptoverrides[$departmentid][$priority]))
                    ? $deptoverrides[$departmentid][$priority]
                    : ($globalthresholds[$priority] ?? $globalthresholds[1]);

                $responsebreached = $thresholds['response'] > 0
                    && empty($ticket->timefirstresponse)
                    && $now >= ((int) $ticket->timecreated + $thresholds['response']);

                $paused = (int) $ticket->sla_paused_seconds;

                if ((int) $ticket->status === 2 && $ticket->sla_paused_since !== null) {
                    $paused += max(0, $now - (int) $ticket->sla_paused_since);
                }

                $resolvebreached = $thresholds['resolve'] > 0
                    && empty($ticket->timeresolved)
                    && $now >= ((int) $ticket->timecreated + $thresholds['resolve'] + $paused);

                if (!$responsebreached && !$resolvebreached) {
                    $DB->set_field('local_moodesk_ticket', 'sla_breached', 0, ['id' => (int) $ticket->id]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026081400, 'local', 'moodesk');
    }

    if ($oldversion < 2026081401) {
        // The dashboard's recent-activity panel orders the whole ticket table by
        // timemodified and keeps the top few rows. Without an index that is a
        // filesort over every ticket on the site, on a panel that renders on
        // every dashboard load.
        $table = new xmldb_table('local_moodesk_ticket');
        $index = new xmldb_index('ix_timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026081401, 'local', 'moodesk');
    }

    if ($oldversion < 2026082100) {
        // Operational observability (OBS-01): one row per class of absorbed
        // failure. The unique fingerprint index is load-bearing, not a
        // nicety — the service upserts on it, so without it a concurrent
        // double-insert would split one incident into two rows and the
        // occurrence counter would start lying.
        $table = new xmldb_table('local_moodesk_incident');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('fingerprint', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('source', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('code', XMLDB_TYPE_CHAR, '60', null, XMLDB_NOTNULL, null, null);
        $table->add_field('severity', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('message', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('context', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('occurrences', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('status', XMLDB_TYPE_CHAR, '15', null, XMLDB_NOTNULL, null, 'open');
        $table->add_field('timefirstseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timelastseen', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timelastalerted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('acknowledgedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timeacknowledged', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('resolvedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timeresolved', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('ix_fingerprint', XMLDB_INDEX_UNIQUE, ['fingerprint']);
        $table->add_index('ix_status_lastseen', XMLDB_INDEX_NOTUNIQUE, ['status', 'timelastseen']);
        $table->add_index('ix_source', XMLDB_INDEX_NOTUNIQUE, ['source']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082100, 'local', 'moodesk');
    }

    if ($oldversion < 2026082101) {
        // Operational alerts (OBS-03). Records the severity an incident was
        // last alerted at, so an escalation can alert again immediately while a
        // steady-state critical still respects the cooldown. Without it,
        // "critical bypasses the cooldown" would mean alerting on every cron
        // tick for as long as the failure lasts.
        $table = new xmldb_table('local_moodesk_incident');
        $field = new xmldb_field('severityalerted', XMLDB_TYPE_CHAR, '10', null, null, null, null, 'timelastalerted');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082101, 'local', 'moodesk');
    }

    if ($oldversion < 2026090501) {
        // SEC-04A: authenticated email replies.
        //
        // One column on the ticket carries the generation of its reply tokens,
        // so a leaked reply address is revoked by raising an integer rather than
        // by keeping a table of live tokens. Five on the email audit row keep
        // the facts a refusal has to distinguish separate: what the sender
        // declared (already in sender_email), who that resolved to, who the
        // token was issued to, what the token verification concluded, and — left
        // null here, reserved for SEC-04B — what the receiving MTA concluded
        // about the sender. They agree on almost every message; collapsing them
        // for that reason is the conceptual merge this work exists to prevent.
        $table = new xmldb_table('local_moodesk_ticket');
        $field = new xmldb_field('tokenepoch', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'merged_audit_id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table  = new xmldb_table('local_moodesk_email_message');
        $fields = [
            new xmldb_field('auth_source', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'reject_reason'),
            new xmldb_field('token_status', XMLDB_TYPE_CHAR, '24', null, null, null, null, 'auth_source'),
            new xmldb_field('token_userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'token_status'),
            new xmldb_field('resolved_userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'token_userid'),
            new xmldb_field('sender_auth', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'resolved_userid'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // An existing site lands on prefer_token, not legacy: it refuses
        // nothing, so it is safe on the day of the upgrade, and it starts
        // producing the counts an operator needs before choosing require_token.
        // A fresh install gets require_token from settings.php instead — there
        // are no pre-token threads there to break.
        if (get_config('local_moodesk', 'email_reply_auth_mode') === false) {
            set_config('email_reply_auth_mode', 'prefer_token', 'local_moodesk');
        }

        upgrade_plugin_savepoint(true, 2026090501, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * DATA-01 — version 2026090900
     * Retention for refused inbound mail. One marker column on
     * local_moodesk_email_message recording when a sweep blanked a row's
     * identifying values, so the sweep can tell a row it has already handled
     * from one it has not, and System Health can say when it last ran.
     *
     * Existing rows land on NULL, which is the truth about them: none has been
     * swept. Nothing is purged by the upgrade itself — the scheduled task does
     * that on its own clock, against whatever retention the site has set.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026090900) {
        $table = new xmldb_table('local_moodesk_email_message');
        $field = new xmldb_field('ttl_purgedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090900, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * KB-01 — version 2026091200
     * Knowledge base foundation: the article table. One row per article in one
     * language, with the identity (groupid), language and derived search text
     * that later units build on, so the table takes one migration rather than
     * three.
     *
     * No role is touched here on purpose. local/moodesk:editkb reaches existing
     * roles through `clonepermissionsfrom` in db/access.php — core copies every
     * role_capabilities row of local/moodesk:reply, permission included, the
     * moment the new capability is registered — and reaches the seeded agent
     * role on a fresh site through db/install.php. See the capability's
     * comment in db/access.php and tests/db/capability_clone_test.php.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026091200) {
        $table = new xmldb_table('local_moodesk_kb_article');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lang', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('body', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('bodyformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('searchtext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('audience', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timepublished', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_categoryid', XMLDB_KEY_FOREIGN, ['categoryid'], 'local_moodesk_category', ['id']);

        $table->add_index('ix_status_lang_category', XMLDB_INDEX_NOTUNIQUE, ['status', 'lang', 'categoryid']);
        $table->add_index('ix_groupid', XMLDB_INDEX_NOTUNIQUE, ['groupid']);
        $table->add_index('ix_createdby', XMLDB_INDEX_NOTUNIQUE, ['createdby']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091200, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * KB-06 — version 2026091500
     * Translations by group: (groupid, lang) becomes unique, so the database
     * holds the one invariant the multilingual model cannot survive without —
     * one row per language per group. The plain groupid index is dropped as
     * redundant. No column is added and no row's lang is rewritten: every
     * existing row already carries a valid code and is a one-row group.
     *
     * The step lives in db/upgradelib.php so tests/db/kb_multilingual_upgrade_test.php
     * can run it against fixtures on every supported database.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026091500) {
        require_once(__DIR__ . '/upgradelib.php');
        local_moodesk_upgrade_kb_unique_group_lang($DB);

        upgrade_plugin_savepoint(true, 2026091500, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * KB-07 — version 2026091600
     * Feedback and analytics: two tables, no change to the article row.
     *
     * kb_feedback holds one reader's current answer to "was this helpful?" per
     * translation row — a person's opinion, exported and deleted with them.
     * kb_event is the anonymous, typed analytics stream — no user id, no
     * group, no language, no JSON — purged by retention. Neither copies
     * groupid or lang from the article: every group or language aggregate
     * joins kb_article at query time, so a re-parented group or a corrected
     * language never needs a migration here. Nothing exists to migrate.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026091600) {
        $table = new xmldb_table('local_moodesk_kb_feedback');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('articleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('helpful', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_articleid', XMLDB_KEY_FOREIGN, ['articleid'], 'local_moodesk_kb_article', ['id']);

        $table->add_index('ux_article_user', XMLDB_INDEX_UNIQUE, ['articleid', 'userid']);
        $table->add_index('ix_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        $table->add_index('ix_timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('local_moodesk_kb_event');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('type', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('articleid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('surface', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('actorrole', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, null);
        $table->add_field('resultcount', XMLDB_TYPE_INTEGER, '5', null, null, null, null);
        $table->add_field('querynorm', XMLDB_TYPE_CHAR, '120', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_articleid', XMLDB_KEY_FOREIGN, ['articleid'], 'local_moodesk_kb_article', ['id']);

        $table->add_index('ix_type_time_article', XMLDB_INDEX_NOTUNIQUE, ['type', 'timecreated', 'articleid']);
        $table->add_index('ix_type_result_time', XMLDB_INDEX_NOTUNIQUE, ['type', 'resultcount', 'timecreated']);
        $table->add_index('ix_article_type_time', XMLDB_INDEX_NOTUNIQUE, ['articleid', 'type', 'timecreated']);
        $table->add_index('ix_timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091600, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * F1 — version 2026091603
     * Legacy ticket_attachment files (written by the email ingestion between
     * 1.4.0 and 2.14.2, itemid = ticket id) move to reply_attachment on the
     * ticket's opening reply, where the ticket page has always looked. The
     * reply is resolved from the ingestion's own ROLE_TICKET_ORIGIN record,
     * or, failing that, from the requester's unmistakable opening message;
     * anything ambiguous stays where it is, and the upgrade goes on. No
     * schema change. Rules and counts: db/upgradelib.php.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026091603) {
        require_once(__DIR__ . '/upgradelib.php');
        $moved = local_moodesk_upgrade_legacy_ticket_attachments($DB);

        // Surfaced in the upgrade output — and kept in the upgrade log table —
        // so an administrator can see what moved and what was left alone
        // without a new place to record it. The untouched tickets are named:
        // those are the ones somebody may want to look at.
        $summary = 'Legacy ticket attachments: ' . $moved['migrated'] . ' file(s) moved to the opening reply, '
            . $moved['already'] . ' already there, ' . $moved['ambiguous'] . ' left in place';
        if (!empty($moved['ambiguoustickets'])) {
            $summary .= ' (ticket ids ' . implode(', ', $moved['ambiguoustickets']) . ')';
        }
        upgrade_log(UPGRADE_LOG_NORMAL, 'local_moodesk', $summary);

        upgrade_plugin_savepoint(true, 2026091603, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Licence host — version 2026091604
     * The default revocation-list URL moved from licenses.digitalmood.io (a
     * host that never resolved) to licenses.moodesk.io. The setting is an
     * admin_setting_configtext, so Moodle wrote the OLD default into
     * config_plugins on every existing site: changing the constant alone
     * would leave them polling a dead host forever. A row holding exactly the
     * old default is rewritten; anything else (a mirror an operator chose, or
     * no row at all) is left alone. Both URLs are literals on purpose — this
     * block must keep meaning the same thing if the constant moves again.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026091604) {
        $oldcrlurl = 'https://licenses.digitalmood.io/crl/revocations.json';
        $newcrlurl = 'https://licenses.moodesk.io/crl/revocations.json';

        $current = get_config('local_moodesk', 'revocation_crl_url');
        if (is_string($current) && trim($current) === $oldcrlurl) {
            set_config('revocation_crl_url', $newcrlurl, 'local_moodesk');
            $note = 'Revocation list URL moved from the retired default ' . $oldcrlurl . ' to ' . $newcrlurl;
            upgrade_log(UPGRADE_LOG_NORMAL, 'local_moodesk', $note);
        }

        upgrade_plugin_savepoint(true, 2026091604, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * IMAP transport — version 2026100101
     * The email_imap_ssl checkbox becomes email_imap_security, a choice of
     * none / ssl / starttls (audit H4). The stored value is carried over as the
     * transport it already meant — 1 to ssl, 0 to none — so no mailbox changes
     * how it is reached by upgrading; a site in the clear keeps working and is
     * warned about it on the settings and System health pages. A site with no
     * row is left without one: the new setting's default, ssl, applies. Then
     * the old row goes, so nothing can read it by mistake.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026100101) {
        $ssl = get_config('local_moodesk', 'email_imap_ssl');
        if ($ssl !== false && get_config('local_moodesk', 'email_imap_security') === false) {
            set_config('email_imap_security', (bool) $ssl ? 'ssl' : 'none', 'local_moodesk');
        }
        unset_config('email_imap_ssl', 'local_moodesk');

        upgrade_plugin_savepoint(true, 2026100101, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Mailbox cursor — version 2026100103
     * The IMAP poller stops deciding what is new from flags and reads by UID
     * instead (E5). The cursor starts empty on purpose: with no row for a
     * folder, the first poll after this upgrade re-scans it once and relies on
     * Message-ID deduplication, as decided on 2026-10-01. Nothing is seeded
     * from the MoodeskProcessed keyword, and there is nothing to migrate.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026100103) {
        $table = new xmldb_table('local_moodesk_mailbox_cursor');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('accountkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('folder', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('uidvalidity', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lastuid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('retryuids', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('failuid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('failcount', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rescanuntil', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('rescansince', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        $table->add_index('ux_account_folder', XMLDB_INDEX_UNIQUE, ['accountkey', 'folder']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026100103, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Licence key rotation — version 2026100104
     * The licence signing key was rotated in 2026-10 after the old private key
     * was exposed. This version trusts only the new key, by `kid`, for licences
     * and for the revocation list, and the list moved to /crl/v2/.
     *
     * Two things stored on the site were verified with the old key and so can
     * no longer be relied on: the revocation list itself, and its updated_at,
     * which the anti-rollback check compares against. A list signed with the
     * leaked key could carry an updated_at far in the future and make the site
     * refuse every legitimate list after it. Both go; the next refresh fetches
     * the v2 list. A URL row holding exactly the old default is moved to the
     * new one; anything else an operator chose is left alone. The URLs are
     * literals on purpose, as in 2026091604.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026100104) {
        $retiredcrlurl = 'https://licenses.moodesk.io/crl/revocations.json';
        $v2crlurl      = 'https://licenses.moodesk.io/crl/v2/revocations.json';

        $current = get_config('local_moodesk', 'revocation_crl_url');
        if (is_string($current) && trim($current) === $retiredcrlurl) {
            set_config('revocation_crl_url', $v2crlurl, 'local_moodesk');
        }
        unset_config('revocation_list', 'local_moodesk');
        unset_config('revocation_updated_at', 'local_moodesk');
        upgrade_log(
            UPGRADE_LOG_NORMAL,
            'local_moodesk',
            'Licence signing key rotated: revocation list reset; licences signed with the retired key must be re-issued.'
        );

        upgrade_plugin_savepoint(true, 2026100104, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Reply-token key for sites installed fresh — version 2026100800
     * The key that signs the reply address in notifications was created only by
     * the upgrade step of 2.23.0 (2026090501), never by install.php, so a site
     * installed fresh from 2.23.0 to 2.38.0 holds none: its notifications carry
     * the no-reply address and an emailed reply opens a new ticket. install.php now
     * creates it; this step gives one to the sites already installed.
     * ensure_key_material() keeps an existing key: rotating it would invalidate
     * every reply address already sent out.
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026100800) {
        // The key is local_moodeskpro's since 3.0.0: the add-on creates its own.
        upgrade_plugin_savepoint(true, 2026100800, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * email_ingestion_alert moves to local_moodeskpro — version 2026111001 (3.0.2)
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026111001) {
        // The add-on owns email_ingestion_alert from 3.0.2. The core deletes this
        // plugin's copy of the provider's settings and user preferences as soon as
        // this upgrade finishes, before the add-on's own upgrade runs, so they are
        // copied to the add-on's names here. A Starter-only site has no add-on to
        // receive them: the provider is simply retired.
        if (get_config('local_moodeskpro', 'version') !== false) {
            require_once(__DIR__ . '/upgradelib.php');
            $copied = local_moodesk_move_message_provider('email_ingestion_alert', 'local_moodeskpro');
            upgrade_log(
                UPGRADE_LOG_NORMAL,
                'local_moodesk',
                "Message provider email_ingestion_alert moved to the add-on: {$copied} setting(s) and preference(s) copied."
            );
        }
        // The core deletes every other setting of the retired provider, but not
        // the switch that turns it off.
        unset_config('local_moodesk_email_ingestion_alert_disable', 'message');
        upgrade_plugin_savepoint(true, 2026111001, 'local', 'moodesk');
    }

    /*
     * -------------------------------------------------------------------------
     * Leftover revocation rows — version 2026111002 (3.0.2)
     * -------------------------------------------------------------------------
     */

    if ($oldversion < 2026111002) {
        // Licence revocation belongs to local_moodeskpro since 3.0.0, which keeps
        // its own settings and list. These rows are leftovers of 2.x that nothing
        // reads.
        foreach (['revocation_crl_url', 'revocation_enabled', 'revocation_list', 'revocation_updated_at'] as $name) {
            unset_config($name, 'local_moodesk');
        }
        upgrade_plugin_savepoint(true, 2026111002, 'local', 'moodesk');
    }

    return true;
}
