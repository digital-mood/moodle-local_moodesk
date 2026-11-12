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
 * Ticket replied event.
 *
 * Fired after a public reply or internal note is successfully added to a ticket.
 * Notification observers listen to this event to send messages to the requester
 * (for public replies from agents) or to agents (for requester replies).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\event;

/**
 * Ticket replied event.
 *
 * Expected 'other' keys:
 *   replyid  int  The ID of the new reply record.
 *   type     int  reply::TYPE_PUBLIC_REPLY or reply::TYPE_INTERNAL_NOTE.
 */
class ticket_replied extends \core\event\base {
    /**
     * {@inheritdoc}
     */
    protected function init(): void {
        $this->data['crud']        = 'c';
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_moodesk_ticket';
    }

    /**
     * {@inheritdoc}
     */
    public static function get_name(): string {
        return get_string('event_ticket_replied', 'local_moodesk');
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' added a reply (id '{$this->other['replyid']}') "
             . "to ticket with id '{$this->objectid}'.";
    }

    /**
     * {@inheritdoc}
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/moodesk/view.php', ['id' => $this->objectid]);
    }

    /**
     * Validate the 'other' data required by this event.
     *
     * @throws \coding_exception
     */
    protected function validate_data(): void {
        parent::validate_data();

        if (!isset($this->other['replyid'])) {
            throw new \coding_exception('The \'replyid\' key must be set in $other.');
        }
        if (!isset($this->other['type'])) {
            throw new \coding_exception('The \'type\' key must be set in $other.');
        }
    }
}
