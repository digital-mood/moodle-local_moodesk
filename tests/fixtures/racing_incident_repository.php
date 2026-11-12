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
 * Test seam that opens the retention race deliberately.
 *
 * Lives in its own file so incident_repository_test.php declares a single
 * class, which is what the coding standard expects.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

/**
 * Deletes the row inside the window between the lookup and the update.
 *
 * The race this reproduces is real and narrow: record_occurrence() reads a row
 * by fingerprint, and purge_incidents can delete that row before the UPDATE
 * lands. Waiting for it to happen by chance is not a test, and neither is
 * asserting that the code looks careful. This subclass makes the window
 * deterministic by doing the deletion itself, at the one instant it matters —
 * after the parent has been handed the row, before the parent writes.
 *
 * Fires once. The second pass through the upsert must find nothing and insert,
 * which is the recovery under test; a seam that deleted on every call would
 * only prove the retry limit works.
 */
class racing_incident_repository extends incident_repository {
    /** @var int How many times the delete window was opened. */
    public int $deletions = 0;

    /** @var int|null Id of the row this seam deleted, for the caller to assert on. */
    public ?int $deletedid = null;

    /**
     * Delete the row, then let the parent try to update it.
     *
     * @param \stdClass $existing Row as stored.
     * @param \stdClass $incoming The occurrence being recorded.
     * @return int|null
     */
    protected function bump(\stdClass $existing, \stdClass $incoming): ?int {
        global $DB;

        if ($this->deletions === 0) {
            $this->deletions++;
            $this->deletedid = (int) $existing->id;
            $DB->delete_records('local_moodesk_incident', ['id' => $existing->id]);
        }

        return parent::bump($existing, $incoming);
    }
}
