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
 * Event: knowledge base article linked in a reply.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\event;

/**
 * Fired by reply_service once per distinct article an agent's saved reply or
 * internal note links to, read from the body as stored.
 *
 * Fired on every edition, next to the KB-07 analytics recorder, for the same
 * replies: an agent's public reply, or an internal note.
 *
 * other[] payload:
 *   'replyid'  => int
 *   'ticketid' => int
 *   'internal' => bool  true for an internal note
 */
class kb_article_linked extends \core\event\base {
    /**
     * Initialise the event metadata.
     */
    protected function init(): void {
        $this->data['crud']        = 'c';
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_moodesk_kb_article';
    }

    /**
     * Get the name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_kb_article_linked', 'local_moodesk');
    }

    /**
     * Get the description.
     *
     * @return string
     */
    public function get_description(): string {
        $ticketid = (int) ($this->other['ticketid'] ?? 0);
        return "User {$this->userid} linked knowledge base article {$this->objectid} in a reply on ticket {$ticketid}.";
    }

    /**
     * Get the url.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/moodesk/kb_article.php', ['id' => $this->objectid]);
    }
}
