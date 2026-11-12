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
 * Dashboard filter value object.
 *
 * The dashboard runs every metric through one immutable filter so that a single
 * user selection ("last 30 days, Development, agent X") reaches all queries in
 * exactly the same shape. This class holds no database access and no Moodle API
 * calls beyond time(): it is a validated bag of criteria.
 *
 * TWO KINDS OF METRIC — the distinction matters and is deliberate:
 *
 *  - SNAPSHOT metrics answer "what is true right now" (active tickets,
 *    unassigned, ticket aging, agent workload). They apply the scope criteria
 *    (department / team / agent) but IGNORE the date range. Filtering "tickets
 *    open right now" by creation date would hide exactly the tickets an
 *    operations panel exists to surface — the ones that have been open longest.
 *    Repository methods that ignore the range say so in their docblock.
 *
 *  - RANGE metrics answer "what happened during this window" (created/resolved
 *    over time, response and resolution times, SLA compliance, top categories).
 *    They apply both the range and the scope criteria.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\domain;

/**
 * Immutable set of criteria shared by every dashboard metric.
 */
class dashboard_filter {
    /** Sentinel for "no department / team / agent restriction". */
    public const ANY = null;

    /** Sentinel for department and agent criteria meaning "records with none set". */
    public const NONE = 0;

    /** Selectable range: the last 7 days. */
    public const RANGE_7 = 7;

    /** Selectable range: the last 30 days. This is the dashboard default. */
    public const RANGE_30 = 30;

    /** Selectable range: the last 90 days. */
    public const RANGE_90 = 90;

    /** @var int Start of the window (Unix timestamp, inclusive). */
    private int $from;

    /** @var int End of the window (Unix timestamp, inclusive). */
    private int $to;

    /** @var int|null Department criterion: null = any, 0 = no department, >0 = that department. */
    private ?int $departmentid;

    /** @var int|null Agent criterion: null = any, 0 = unassigned, >0 = that agent. */
    private ?int $agentid;

    /** @var int|null The range preset in days, when this filter came from one. */
    private ?int $rangedays;

    /** @var int|null Requester criterion: null = any, >0 = tickets raised by that user. */
    private ?int $requesterid;

    /** @var array Plugin criteria (hook dashboard_filters), name => value. */
    private array $plugincriteria = [];

    /**
     * Constructor. Prefer {@see self::from_days()} for the common case.
     *
     * @param int      $from         Start of the window (Unix timestamp, inclusive).
     * @param int      $to           End of the window (Unix timestamp, inclusive).
     * @param int|null $departmentid null = any, 0 = no department, >0 = that department.
     * @param int|null $agentid      null = any, 0 = unassigned, >0 = that agent.
     * @param int|null $rangedays    Range preset in days, when one produced this filter.
     * @param int|null $requesterid  null = any, >0 = tickets raised by that user.
     */
    public function __construct(
        int $from,
        int $to,
        ?int $departmentid = self::ANY,
        ?int $agentid = self::ANY,
        ?int $rangedays = null,
        ?int $requesterid = self::ANY
    ) {
        // A reversed window would silently return zero rows everywhere rather
        // than failing, so normalise it here instead of in fifteen queries.
        $this->from = min($from, $to);
        $this->to   = max($from, $to);

        $this->departmentid = $departmentid;
        $this->agentid      = $agentid;
        $this->rangedays    = $rangedays;
        // Every ticket has a requester, so zero selects nothing rather than
        // "tickets with none" — it folds to "any".
        $this->requesterid  = ($requesterid === self::NONE) ? self::ANY : $requesterid;
    }

    /**
     * Build a filter covering the last N days, ending now.
     *
     * @param int      $days         One of the RANGE_* presets.
     * @param int|null $departmentid null = any, 0 = no department, >0 = that department.
     * @param int|null $agentid      null = any, 0 = unassigned, >0 = that agent.
     * @param int|null $now          Evaluation point (null = time()); injectable for tests.
     * @return self
     */
    public static function from_days(
        int $days,
        ?int $departmentid = self::ANY,
        ?int $agentid = self::ANY,
        ?int $now = null
    ): self {
        $days = self::validate_days($days);
        $now  = $now ?? time();

        return new self(
            $now - ($days * DAYSECS),
            $now,
            $departmentid,
            $agentid,
            $days
        );
    }

    /**
     * Return the accepted range presets, in display order.
     *
     * @return int[]
     */
    public static function valid_ranges(): array {
        return [self::RANGE_7, self::RANGE_30, self::RANGE_90];
    }

    /**
     * Coerce an arbitrary day count to a supported preset.
     *
     * Anything unrecognised falls back to the 30-day default rather than
     * throwing: the range arrives from a URL parameter, and a hand-edited URL
     * should land the user on the default dashboard, not on an error page.
     *
     * @param int $days
     * @return int
     */
    public static function validate_days(int $days): int {
        return in_array($days, self::valid_ranges(), true) ? $days : self::RANGE_30;
    }

    /*
     * =========================================================================
     * Derived filters
     * =========================================================================
     */

    /**
     * Return a copy of this filter covering a different window, same scope.
     *
     * Used wherever a metric needs a window of its own — the previous period
     * for a delta, or the fixed current week behind "resolved this week" — while
     * still honouring the department / team / agent the user selected.
     *
     * @param int $from Start of the new window (Unix timestamp, inclusive).
     * @param int $to   End of the new window (Unix timestamp, inclusive).
     * @return self
     */
    public function with_range(int $from, int $to): self {
        $copy = new self(
            $from,
            $to,
            $this->departmentid,
            $this->agentid,
            null,
            $this->requesterid
        );
        $copy->plugincriteria = $this->plugincriteria;
        return $copy;
    }

    /**
     * Return a copy of this filter restricted to one requester's own tickets.
     *
     * This is the scope behind the reduced dashboard a plain requester sees:
     * tickets they raised, regardless of who is working them.
     *
     * @param int $userid The requester.
     * @return self
     */
    public function with_requester(int $userid): self {
        $copy = new self(
            $this->from,
            $this->to,
            $this->departmentid,
            $this->agentid,
            $this->rangedays,
            $userid
        );
        $copy->plugincriteria = $this->plugincriteria;
        return $copy;
    }

    /**
     * Return a copy of this filter carrying plugin criteria (hook dashboard_filters).
     *
     * They travel with the filter, including into the previous period, and are
     * offered back to plugins as SQL by dashboard_repository.
     *
     * @param array $criteria Name => value; only values other than their default.
     * @return self
     */
    public function with_plugin_criteria(array $criteria): self {
        $copy = clone $this;
        $copy->plugincriteria = $criteria;
        return $copy;
    }

    /**
     * Return the equivalent window immediately preceding this one.
     *
     * The previous period is the same length as the current one and ends one
     * second before it starts, so the two never overlap and no event is counted
     * on both sides of a delta.
     *
     * @return self
     */
    public function previous_period(): self {
        $length = $this->duration();

        return $this->with_range($this->from - $length, $this->from - 1);
    }

    /*
     * =========================================================================
     * Accessors
     * =========================================================================
     */

    /**
     * Start of the window (Unix timestamp, inclusive).
     *
     * @return int
     */
    public function get_from(): int {
        return $this->from;
    }

    /**
     * End of the window (Unix timestamp, inclusive).
     *
     * @return int
     */
    public function get_to(): int {
        return $this->to;
    }

    /**
     * Length of the window in seconds, both ends inclusive.
     *
     * @return int
     */
    public function duration(): int {
        return ($this->to - $this->from) + 1;
    }

    /**
     * The range preset in days, or null when the window was set explicitly.
     *
     * @return int|null
     */
    public function get_range_days(): ?int {
        return $this->rangedays;
    }

    /**
     * Department criterion: null = any, 0 = no department, >0 = that department.
     *
     * @return int|null
     */
    public function get_departmentid(): ?int {
        return $this->departmentid;
    }

    /**
     * Agent criterion: null = any, 0 = unassigned, >0 = that agent.
     *
     * @return int|null
     */
    public function get_agentid(): ?int {
        return $this->agentid;
    }

    /**
     * Requester criterion: null = any, >0 = tickets raised by that user.
     *
     * @return int|null
     */
    public function get_requesterid(): ?int {
        return $this->requesterid;
    }

    /*
     * =========================================================================
     * Predicates
     * =========================================================================
     */

    /**
     * True when any of the department / agent / requester / plugin criteria is set.
     *
     * @return bool
     */
    public function has_scope(): bool {
        return $this->departmentid !== self::ANY
            || $this->agentid !== self::ANY
            || $this->requesterid !== self::ANY
            || !empty($this->plugincriteria);
    }

    /**
     * Plugin criteria, name => value (hook dashboard_filters).
     *
     * @return array
     */
    public function get_plugin_criteria(): array {
        return $this->plugincriteria;
    }

    /**
     * True when the filter narrows to a single agent (not to "unassigned").
     *
     * The unassigned KPI is empty by definition under an agent criterion, and
     * agent workload collapses to one row. The presentation layer reads this to
     * label those panels instead of showing a zero that looks like real data.
     *
     * @return bool
     */
    public function is_agent_scoped(): bool {
        return $this->agentid !== self::ANY && $this->agentid > self::NONE;
    }
}
