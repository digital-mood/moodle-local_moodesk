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
 * Asks plugins which groups, beyond departments, some agents belong to.
 *
 * Dispatched by {@see \local_moodesk\service\agent_service} for a roster. A
 * callback names each agent's groups, and with the values of its filters
 * ({@see agent_directory_filters}) may narrow the roster. An agent in no
 * department and no group is flagged as unable to receive routed work.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to report the groups mooDesk agents belong to and narrow the agent directory.')]
#[\core\attribute\tags('local_moodesk')]
final class agent_groups {
    /** @var array<int, string[]> Group names, already formatted, by user id. */
    private array $groups = [];

    /** @var int[]|null The agents kept, null when nobody narrowed the roster. */
    private ?array $kept = null;

    /** @var string|null What a group is called, null when no plugin said. */
    private ?string $grouptype = null;

    /**
     * Create the hook.
     *
     * @param int[] $userids The agents in the roster.
     * @param array $values  Plugin filter values the request carries, name => value.
     */
    public function __construct(
        /** @var int[] */
        public readonly array $userids,
        /** @var array */
        public readonly array $values = [],
    ) {
    }

    /**
     * Say which groups an agent belongs to.
     *
     * @param int      $userid
     * @param string[] $names  Group names, already formatted.
     */
    public function add_groups(int $userid, array $names): void {
        $this->groups[$userid] = array_merge($this->groups[$userid] ?? [], array_values($names));
    }

    /**
     * The groups of every agent, by user id.
     *
     * @return array<int, string[]>
     */
    public function get_groups(): array {
        return $this->groups;
    }

    /**
     * Narrow the roster to these agents.
     *
     * @param int[] $userids
     */
    public function keep_only(array $userids): void {
        $this->kept = $this->kept === null ? $userids : array_values(array_intersect($this->kept, $userids));
    }

    /**
     * The agents kept.
     *
     * @return int[]|null Null when nobody narrowed the roster.
     */
    public function get_kept(): ?array {
        return $this->kept;
    }

    /**
     * Say what the groups are called, so the base's texts about membership
     * name them ("no department or team"). Optional, since 3.0.2: without it
     * those texts speak of departments only.
     *
     * @param string $name A bare singular noun in the current language, as it
     *                     reads after "no department or" ("team"), already
     *                     formatted.
     */
    public function set_group_type(string $name): void {
        $this->grouptype = $name;
    }

    /**
     * What the groups are called.
     *
     * @return string|null Null when no plugin said.
     */
    public function get_group_type(): ?string {
        return $this->grouptype;
    }
}
