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
 * Ticket merged event.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\event;

/**
 * Fired by merge_service::merge() once the merge has committed.
 *
 * Deliberately triggered outside the merge transaction (design doc §9.2): the
 * merge is already durable by the time observers run, so a failing observer
 * cannot undo it.
 *
 * objectid is the source ticket — the one that was merged away — because that
 * is the ticket whose state changed. The target is in other[].
 *
 * other[] payload:
 *   'sourceid'       => int   Ticket that was merged away and closed.
 *   'targetid'       => int   Surviving ticket the source was folded into.
 *   'auditid'        => int   local_moodeskpro_merge_audit row for this merge.
 *   'migrated_items' => array Counts of what the source carried, by kind.
 */
class ticket_merged extends \core\event\base {
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
        return get_string('event_ticket_merged', 'local_moodesk');
    }

    /**
     * Get the description.
     *
     * @return string
     */
    public function get_description(): string {
        $source = $this->other['sourceid'] ?? '?';
        $target = $this->other['targetid'] ?? '?';
        return "User {$this->userid} merged ticket {$source} into ticket {$target}.";
    }

    /**
     * Get the url.
     *
     * The surviving ticket is where the conversation continues, so that is
     * where this event points.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/moodesk/view.php', ['id' => $this->other['targetid'] ?? $this->objectid]);
    }
}
