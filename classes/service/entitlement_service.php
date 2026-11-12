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
 * Agent entitlement: how many active agents this site may have.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * The one place that answers "how many agents".
 *
 * edition_service answers "which edition is this site running" and "is this
 * feature available"; this service answers "how much of it". Enforcement
 * (agent_service::grant_agent), System Health and the add-agent screen all read
 * it from here, so no consumer works the number out on its own.
 *
 * On its own the base is Starter: 2 agents, and a grant over that is refused. A
 * plugin may change both through the agent_capacity hook; local_moodeskpro does:
 * the capacity comes from the licence, and nothing is refused.
 *
 * What counts as an active agent is agent_service's business
 * ({@see agent_service::count_active_agents()}); this service only compares
 * that count with the capacity. Over capacity nobody is removed.
 */
class entitlement_service {
    /** Capacity of a site without a plugin that says otherwise (Starter). */
    const STARTER_CAPACITY = 2;

    /**
     * The active-agent capacity this install has now.
     *
     * @return int Never null: nothing is unlimited.
     */
    public static function agent_limit(): int {
        return self::capacity(edition_service::get_edition())->get_limit();
    }

    /**
     * Whether this install may add one more active agent.
     *
     * True under the capacity, and always when a plugin has said grants over
     * it are not refused (hook agent_capacity).
     *
     * @param int $activeagents The current active-agent count.
     * @return bool
     */
    public static function can_add_agent(int $activeagents): bool {
        $capacity = self::capacity(edition_service::get_edition());
        return !$capacity->get_refuse() || $activeagents < $capacity->get_limit();
    }

    /**
     * The capacity of this install for an edition, after the agent_capacity hook.
     *
     * Every reader of this install's capacity goes through here, so the figure
     * shown and the one enforced cannot disagree.
     *
     * @param string $edition The effective edition.
     * @return \local_moodesk\hook\agent_capacity
     */
    private static function capacity(string $edition): \local_moodesk\hook\agent_capacity {
        $hook = new \local_moodesk\hook\agent_capacity($edition, self::STARTER_CAPACITY);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return $hook;
    }

    /**
     * The note about a restricting edition source, or '' when there is none.
     *
     * @param \stdClass $source An edition_service::edition_source() record.
     * @return string
     */
    public static function source_note(\stdClass $source): string {
        if (!in_array($source->source, ['dev_override', 'dist_pin'], true)) {
            return '';
        }
        if ($source->lowered) {
            $label = get_string('edition_' . $source->effective, 'local_moodesk');
            return get_string('edition_source_lowered_' . $source->source, 'local_moodesk', $label);
        }
        if ($source->ignored) {
            $label = get_string('edition_' . $source->requested, 'local_moodesk');
            return get_string('edition_source_ignored_' . $source->source, 'local_moodesk', $label);
        }
        return '';
    }

    /**
     * The agent entitlement, for the pages that show it.
     *
     * One call so System Health, the add-agent screen and the grant refusal
     * cannot disagree about the capacity, the count or whether the site is over it.
     *
     * @return \stdClass {
     *   edition: string,   the effective edition
     *   limit: int,        the capacity
     *   active: int,       active agents right now
     *   exceeded: bool,    active > limit
     *   atlimit: bool,     no room for one more (exceeded or equal)
     *   remaining: int,    seats left
     *   source: \stdClass  edition_service::edition_source()
     * }
     */
    public static function agent_entitlement(): \stdClass {
        $source  = edition_service::edition_source();
        $edition = $source->effective;
        $limit   = self::capacity($edition)->get_limit();
        $active  = agent_service::create()->count_active_agents();

        return (object) [
            'edition'   => $edition,
            'limit'     => $limit,
            'active'    => $active,
            'exceeded'  => $active > $limit,
            'atlimit'   => $active >= $limit,
            'remaining' => max(0, $limit - $active),
            'source'    => $source,
        ];
    }

    /**
     * The agent entitlement as one row, for System Health and the add-agent screen.
     *
     * @return array
     */
    public static function export_entitlement(): array {
        $ent = self::agent_entitlement();
        $sourcenote = self::source_note($ent->source);
        $refuses = self::capacity($ent->edition)->get_refuse();

        return [
            'active'        => $ent->active,
            'limit'         => $ent->limit,
            'usage'         => get_string('agents_usage', 'local_moodesk', (object) [
                'active'   => $ent->active,
                'capacity' => $ent->limit,
            ]),
            'exceeded'      => $ent->exceeded,
            'atlimit'       => $ent->atlimit,
            'refuses'       => $refuses,
            'editionlabel'  => get_string('edition_' . $ent->edition, 'local_moodesk'),
            'hassourcenote' => $sourcenote !== '',
            'sourcenote'    => $sourcenote,
        ];
    }
}
