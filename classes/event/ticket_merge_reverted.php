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
 * Ticket merge reverted event.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\event;

/**
 * Fired by merge_service::revert() once the undo has committed.
 *
 * Like its counterpart it is triggered outside the transaction (design doc
 * §9.2). objectid is the restored source ticket.
 *
 * other[] payload:
 *   'sourceid'         => int Ticket restored out of the merge.
 *   'targetid'         => int Ticket it had been merged into.
 *   'auditid'          => int Audit row that was reverted.
 *   'reverted_actorid' => int Agent who performed the revert.
 */
class ticket_merge_reverted extends \core\event\base {
    /**
     * Initialise the event metadata.
     */
    protected function init(): void {
        $this->data['crud']        = 'u';
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_moodesk_ticket';
    }

    /**
     * Get the name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_ticket_merge_reverted', 'local_moodesk');
    }

    /**
     * Get the description.
     *
     * @return string
     */
    public function get_description(): string {
        $source  = $this->other['sourceid'] ?? '?';
        $auditid = $this->other['auditid'] ?? '?';
        return "User {$this->userid} reverted merge {$auditid}, restoring ticket {$source}.";
    }

    /**
     * Get the url.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/moodesk/view.php', ['id' => $this->objectid]);
    }
}
