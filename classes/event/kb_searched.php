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
 * Event: knowledge base searched.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\event;

/**
 * Fired by kb.php for the first page of a search that has searchable tokens.
 *
 * Fired on every edition, next to the KB-07 analytics recorder. The standard
 * log stores an event with the user's id, so it never carries what was typed:
 * searches are anonymous, and the words of an empty search live only in
 * local_moodeskpro_kb_event, which names nobody. A plugin that needs them gets them
 * from a hook, not from here (docs/two-plugin-split-plan.md §4).
 *
 * other[] payload:
 *   'resultcount' => int
 */
class kb_searched extends \core\event\base {
    /**
     * The event for one search, from what it found and nothing else.
     *
     * @param int $resultcount Articles found; negative counts as none.
     * @return self
     */
    public static function from_result_count(int $resultcount): self {
        return self::create([
            'context' => \context_system::instance(),
            'other'   => ['resultcount' => max(0, $resultcount)],
        ]);
    }

    /**
     * Initialise the event metadata.
     */
    protected function init(): void {
        $this->data['crud']     = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Get the name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_kb_searched', 'local_moodesk');
    }

    /**
     * Get the description.
     *
     * @return string
     */
    public function get_description(): string {
        $count = (int) ($this->other['resultcount'] ?? 0);
        return "User {$this->userid} searched the knowledge base and found {$count} articles.";
    }

    /**
     * Get the url.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/moodesk/kb.php');
    }
}
