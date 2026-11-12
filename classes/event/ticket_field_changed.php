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
 * Event: a ticket field value changed.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\event;

/**
 * Fired by ticket_service whenever a scalar ticket field is updated.
 *
 * other[] payload:
 *   'field'    => string  (e.g. 'priority', 'assigneeid', 'departmentid')
 *   'oldvalue' => mixed   (previous value, cast to string for storage)
 *   'newvalue' => mixed   (new value, cast to string for storage)
 */
class ticket_field_changed extends \core\event\base {
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
        return get_string('event_ticket_field_changed', 'local_moodesk');
    }

    /**
     * Get the description.
     *
     * @return string
     */
    public function get_description(): string {
        $field = $this->other['field'] ?? '?';
        $old   = $this->other['oldvalue'] ?? '?';
        $new   = $this->other['newvalue'] ?? '?';
        return "User {$this->userid} changed field '{$field}' of ticket {$this->objectid} from '{$old}' to '{$new}'.";
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
