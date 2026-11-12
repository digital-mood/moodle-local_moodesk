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
 * Lets a plugin decide which other tickets a ticket's conversation reads.
 *
 * Dispatched by {@see \local_moodesk\service\reply_service::get_thread_with_linked()}
 * once the viewer may read the ticket. A callback adds sources, as a map of
 * ticket id to subject, or removes them. The replies of every source
 * left are read with the same visibility rule as the ticket's own: all types
 * for agents, the requester-visible types for a requester. Which sources a
 * requester may read is the callback's to decide.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add tickets whose replies a mooDesk conversation includes.')]
#[\core\attribute\tags('local_moodesk')]
final class thread_sources {
    /** @var array<int, string> Source ticket id => subject. */
    private array $sources;

    /**
     * Create the hook with the base's sources.
     *
     * @param int                $ticketid The ticket whose conversation is read.
     * @param int                $actorid  Who reads it.
     * @param bool               $isagent  Whether they read as an agent (local/moodesk:viewall).
     * @param array              $sources  The base's sources, ticket id => subject (array<int, string>).
     */
    public function __construct(
        /** @var int */
        public readonly int $ticketid,
        /** @var int */
        public readonly int $actorid,
        /** @var bool */
        public readonly bool $isagent,
        array $sources,
    ) {
        $this->sources = $sources;
    }

    /**
     * Add a source, or rename it if it is already there.
     *
     * @param int    $ticketid The source ticket.
     * @param string $subject  Its subject, for the label on its replies.
     * @throws \coding_exception For the ticket itself or a non-positive id.
     */
    public function add_source(int $ticketid, string $subject): void {
        if ($ticketid <= 0 || $ticketid === $this->ticketid) {
            throw new \coding_exception("Invalid thread source: {$ticketid}");
        }
        $this->sources[$ticketid] = $subject;
    }

    /**
     * Remove a source.
     *
     * @param int $ticketid
     */
    public function remove_source(int $ticketid): void {
        unset($this->sources[$ticketid]);
    }

    /**
     * The sources as they stand now.
     *
     * @return array<int, string> Ticket id => subject.
     */
    public function get_sources(): array {
        return $this->sources;
    }
}
