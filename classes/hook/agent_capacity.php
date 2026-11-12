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
 * Lets a plugin set the active-agent capacity and whether it is enforced.
 *
 * Dispatched by {@see \local_moodesk\service\entitlement_service} every time
 * it computes the capacity of this install, so the limit shown on System Health
 * and the add-agent screen and the one
 * {@see \local_moodesk\service\agent_service::grant_agent()} enforces cannot
 * disagree. It carries Starter's limit and `refuse = true`: at the limit, a grant
 * that would add an agent is refused. A callback may change either.
 * `local_moodeskpro` sets the limit from the licence and never refuses
 * (docs/two-plugin-split-plan.md §2, decision 2).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to set the mooDesk agent capacity and whether grants over it are refused.')]
#[\core\attribute\tags('local_moodesk')]
final class agent_capacity {
    /** @var int The active-agent capacity. */
    private int $limit;

    /** @var bool Whether a grant that would go over the capacity is refused. */
    private bool $refuse = true;

    /**
     * Create the hook with the base's capacity.
     *
     * @param string $edition The effective edition the capacity is computed for.
     * @param int    $limit   The capacity the base computed.
     */
    public function __construct(
        /** @var string */
        public readonly string $edition,
        int $limit,
    ) {
        $this->limit = $limit;
    }

    /**
     * The capacity as it stands now.
     *
     * @return int Never negative.
     */
    public function get_limit(): int {
        return $this->limit;
    }

    /**
     * Set the capacity.
     *
     * @param int $limit Active agents allowed; nothing is unlimited.
     * @throws \coding_exception On a negative limit.
     */
    public function set_limit(int $limit): void {
        if ($limit < 0) {
            throw new \coding_exception('The agent capacity cannot be negative');
        }
        $this->limit = $limit;
    }

    /**
     * Whether a grant that would go over the capacity is refused.
     *
     * @return bool
     */
    public function get_refuse(): bool {
        return $this->refuse;
    }

    /**
     * Set whether a grant that would go over the capacity is refused.
     *
     * @param bool $refuse
     */
    public function set_refuse(bool $refuse): void {
        $this->refuse = $refuse;
    }
}
