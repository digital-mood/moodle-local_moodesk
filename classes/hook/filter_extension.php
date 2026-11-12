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
 * What the two filter hooks share: a plugin filter is a request parameter, a
 * control in the filter bar and an SQL condition.
 *
 * A filter hook is dispatched in three phases, each with its own named
 * constructor, and a callback checks get_phase():
 *
 *  - params: declare_param() names the request parameters the plugin reads,
 *    with their PARAM_* type and their "no filter" default. The page reads
 *    them and keeps them across pagination and links, as it does its own.
 *  - options: get_value() gives what the request carries; add_select() puts a
 *    control in the filter bar.
 *  - sql: get_value() again, and add_condition() narrows the query. A
 *    condition is a single `EXISTS (...)` or `NOT EXISTS (...)` against the
 *    ticket aliased `t`, never a join, so it keeps working whatever table its
 *    data lives in (design rule b of docs/two-plugin-split-plan.md §8.2). Its
 *    parameters are named through param(), which prefixes them per query.
 *    The base ANDs the conditions.
 *
 * The sql phase is dispatched only when the request carries a value other
 * than a declared default, so a page without plugin filters runs the query it
 * always has.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class filter_extension {
    /** Declaring request parameters. */
    const PHASE_PARAMS = 'params';

    /** Adding controls to the filter bar. */
    const PHASE_OPTIONS = 'options';

    /** Narrowing the query. */
    const PHASE_SQL = 'sql';

    /** @var array<string, array{type: string, default: mixed}> Declared parameters. */
    private array $declared = [];

    /** @var array<int, array> Controls for the filter bar. */
    private array $selects = [];

    /** @var string[] SQL conditions. */
    private array $conditions = [];

    /** @var array Parameters of the SQL conditions. */
    private array $sqlparams = [];

    /**
     * Use the named constructors.
     *
     * @param string   $phase       One of the PHASE_* constants.
     * @param string[] $reserved    Parameter names the page already uses (params phase).
     * @param array    $values      Plugin parameter values the request carries (options and sql phases).
     * @param string   $paramprefix Prefix for SQL parameter names (sql phase).
     */
    final protected function __construct(
        /** @var string */
        private string $phase,
        /** @var string[] */
        private array $reserved = [],
        /** @var array */
        private array $values = [],
        /** @var string */
        private string $paramprefix = '',
    ) {
    }

    /**
     * The hook for the params phase.
     *
     * @param string[] $reserved The page's own parameter names, which a plugin may not take.
     * @return static
     */
    public static function for_params(array $reserved): static {
        return new static(self::PHASE_PARAMS, $reserved);
    }

    /**
     * The hook for the options phase.
     *
     * @param array $values The plugin parameter values the request carries.
     * @return static
     */
    public static function for_options(array $values): static {
        return new static(self::PHASE_OPTIONS, [], $values);
    }

    /**
     * The hook for the sql phase.
     *
     * @param array  $values      The plugin parameter values the request carries.
     * @param string $paramprefix A prefix unique to the query, for parameter names.
     * @return static
     */
    public static function for_sql(array $values, string $paramprefix): static {
        return new static(self::PHASE_SQL, [], $values, $paramprefix);
    }

    /**
     * The phase this dispatch is for.
     *
     * @return string One of the PHASE_* constants.
     */
    public function get_phase(): string {
        return $this->phase;
    }

    /**
     * Declare a request parameter.
     *
     * @param string $name    Lowercase letters, digits and underscores, starting with a letter.
     * @param string $type    A PARAM_* type for optional_param().
     * @param mixed  $default The value meaning "no filter".
     * @throws \coding_exception Outside the params phase, on a bad name, or on a name already taken.
     */
    public function declare_param(string $name, string $type, $default): void {
        $this->require_phase(self::PHASE_PARAMS);
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \coding_exception("Invalid filter parameter name: {$name}");
        }
        if (in_array($name, $this->reserved, true) || array_key_exists($name, $this->declared)) {
            throw new \coding_exception("Filter parameter name already taken: {$name}");
        }
        $this->declared[$name] = ['type' => $type, 'default' => $default];
    }

    /**
     * The parameters declared so far.
     *
     * @return array<string, array{type: string, default: mixed}>
     */
    public function get_declared(): array {
        return $this->declared;
    }

    /**
     * The value the request carries for a plugin parameter.
     *
     * @param string $name
     * @return mixed Null when the request carries none (or only its default).
     * @throws \coding_exception In the params phase.
     */
    public function get_value(string $name) {
        if ($this->phase === self::PHASE_PARAMS) {
            throw new \coding_exception('No values in the params phase');
        }
        return $this->values[$name] ?? null;
    }

    /**
     * Add a select control to the filter bar.
     *
     * @param string $name     The declared parameter it sets.
     * @param string $label    Its accessible label.
     * @param array  $options  Value => label; the first one should mean "no filter".
     * @param mixed  $selected The value currently selected.
     * @throws \coding_exception Outside the options phase.
     */
    public function add_select(string $name, string $label, array $options, $selected): void {
        $this->require_phase(self::PHASE_OPTIONS);
        $opts = [];
        foreach ($options as $value => $optionlabel) {
            $opts[] = [
                'value' => (string) $value,
                'label' => (string) $optionlabel,
                'isselected' => (string) $value === (string) $selected,
            ];
        }
        $this->selects[] = ['name' => $name, 'label' => $label, 'options' => $opts];
    }

    /**
     * The controls added so far, ready for the filter bar template.
     *
     * @return array<int, array{name: string, label: string, options: array}>
     */
    public function get_selects(): array {
        return $this->selects;
    }

    /**
     * The controls of a filter bar that are set to something, for its chips.
     *
     * The first option of a control means "no filter".
     *
     * @param array $selects What get_selects() returned.
     * @return array<int, array{name: string, label: string, value: string}> Value is the selected option's label.
     */
    public static function active_selects(array $selects): array {
        $active = [];
        foreach ($selects as $select) {
            foreach (array_slice($select['options'], 1) as $option) {
                if ($option['isselected']) {
                    $active[] = ['name' => $select['name'], 'label' => $select['label'], 'value' => $option['label']];
                }
            }
        }
        return $active;
    }

    /**
     * The name to use for an SQL parameter, unique to this query.
     *
     * @param string $name Lowercase letters and digits.
     * @return string Use it as `:name` in the condition and as the key in its params.
     * @throws \coding_exception Outside the sql phase, or on a bad name.
     */
    public function param(string $name): string {
        $this->require_phase(self::PHASE_SQL);
        if (!preg_match('/^[a-z][a-z0-9]*$/', $name)) {
            throw new \coding_exception("Invalid SQL parameter name: {$name}");
        }
        return $this->paramprefix . 'plg' . $name;
    }

    /**
     * Narrow the query with one condition.
     *
     * @param string $sql    `EXISTS (...)` or `NOT EXISTS (...)`, correlated on `t.id`.
     * @param array  $params Its parameters, every key from param().
     * @throws \coding_exception Outside the sql phase, on another shape, or on a parameter not from param().
     */
    public function add_condition(string $sql, array $params = []): void {
        $this->require_phase(self::PHASE_SQL);
        if (!preg_match('/^\s*(NOT\s+)?EXISTS\s*\(.*\)\s*$/is', $sql)) {
            throw new \coding_exception('A filter condition must be a single EXISTS (...) or NOT EXISTS (...)');
        }
        foreach (array_keys($params) as $key) {
            if (strpos((string) $key, $this->paramprefix . 'plg') !== 0 || array_key_exists($key, $this->sqlparams)) {
                throw new \coding_exception("Filter condition parameter not named through param(), or reused: {$key}");
            }
        }
        $this->conditions[] = $sql;
        $this->sqlparams += $params;
    }

    /**
     * The conditions added, ANDed, and their parameters.
     *
     * @return array{0: string, 1: array} SQL ('' when none) and params.
     */
    public function get_sql(): array {
        if (empty($this->conditions)) {
            return ['', []];
        }
        return ['(' . implode(') AND (', $this->conditions) . ')', $this->sqlparams];
    }

    /**
     * Read the declared parameters from the request, keeping only values other than the default.
     *
     * @param array    $declared What get_declared() returned: name => {type, default}.
     * @param callable $reader fn(string $name, string $type, mixed $default): mixed, e.g. optional_param().
     * @return array Name => value.
     */
    public static function read_values(array $declared, callable $reader): array {
        $values = [];
        foreach ($declared as $name => $spec) {
            $value = $reader($name, $spec['type'], $spec['default']);
            if ($value !== null && $value !== $spec['default']) {
                $values[$name] = $value;
            }
        }
        return $values;
    }

    /**
     * Fail unless the hook is in the given phase.
     *
     * @param string $phase
     * @throws \coding_exception
     */
    private function require_phase(string $phase): void {
        if ($this->phase !== $phase) {
            throw new \coding_exception("Only available in the {$phase} phase, not in {$this->phase}");
        }
    }
}
