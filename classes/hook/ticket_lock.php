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

namespace local_moodesk\hook;

/**
 * Asks plugins whether a ticket is locked against writes.
 *
 * Dispatched by {@see \local_moodesk\output\ticket_detail}, so the screen shows
 * values instead of controls a write would refuse. The refusal itself is
 * {@see before_ticket_write}'s: this only says what to render.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to mark a mooDesk ticket as locked against writes.')]
#[\core\attribute\tags('local_moodesk')]
final class ticket_lock {
    /** @var bool Whether a callback locked the ticket. */
    private bool $locked = false;

    /**
     * Create the hook.
     *
     * @param \stdClass $ticket The ticket record. Read it; do not change it.
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $ticket,
    ) {
    }

    /**
     * Mark the ticket as locked.
     */
    public function lock(): void {
        $this->locked = true;
    }

    /**
     * Whether the ticket is locked.
     *
     * @return bool
     */
    public function is_locked(): bool {
        return $this->locked;
    }
}
