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
 * Lets a plugin refuse a write to a ticket.
 *
 * Dispatched by {@see self::check()}, which ticket_service and reply_service
 * call on the writes a locked ticket refuses: status,
 * priority, assignment, category, department, team, a public reply and a reply
 * by email. The ticket has been loaded and the actor authorised by then.
 *
 * A callback that refuses calls veto() with a language string; check() then
 * throws it as a moodle_exception, so the caller sees an ordinary refusal
 * (design rule c of docs/two-plugin-split-plan.md §8.2).
 * The first veto wins. With no veto the write goes ahead.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to refuse a write to a mooDesk ticket.')]
#[\core\attribute\tags('local_moodesk')]
final class before_ticket_write {
    /** Status transition. */
    const OP_STATUS = 'status';

    /** Priority change. */
    const OP_PRIORITY = 'priority';

    /** Assignment to an agent, or unassignment. */
    const OP_ASSIGN = 'assign';

    /** Category change. */
    const OP_CATEGORY = 'category';

    /** Department change. */
    const OP_DEPARTMENT = 'department';

    /** Team change. */
    const OP_TEAM = 'team';

    /** A public reply from the ticket screen. */
    const OP_PUBLIC_REPLY = 'public_reply';

    /** A reply that arrived by email. */
    const OP_EMAIL_REPLY = 'email_reply';

    /** @var array{0: string, 1: string, 2: mixed}|null The veto: string id, component, `$a`. */
    private ?array $veto = null;

    /**
     * Create the hook for one write.
     *
     * @param \stdClass $ticket    The ticket record, as loaded before the write. Read it; do not change it.
     * @param string    $operation One of the OP_* constants.
     * @param int       $actorid   Who is writing.
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $ticket,
        /** @var string */
        public readonly string $operation,
        /** @var int */
        public readonly int $actorid,
    ) {
    }

    /**
     * Refuse the write.
     *
     * @param string $stringid  The language string of the refusal.
     * @param string $component Its component.
     * @param mixed  $a         Its `$a`, if any.
     */
    public function veto(string $stringid, string $component, $a = null): void {
        if ($this->veto === null) {
            $this->veto = [$stringid, $component, $a];
        }
    }

    /**
     * Whether a callback refused the write.
     *
     * @return bool
     */
    public function is_vetoed(): bool {
        return $this->veto !== null;
    }

    /**
     * Offer a write to plugins and throw their refusal, if any.
     *
     * @param \stdClass $ticket    The ticket record.
     * @param string    $operation One of the OP_* constants.
     * @param int       $actorid   Who is writing.
     * @throws \moodle_exception The first veto.
     */
    public static function check(\stdClass $ticket, string $operation, int $actorid): void {
        $hook = new self($ticket, $operation, $actorid);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        if ($hook->veto !== null) {
            [$stringid, $component, $a] = $hook->veto;
            throw new \moodle_exception($stringid, $component, '', $a);
        }
    }
}
