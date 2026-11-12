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
 * Dashboard service — turns raw aggregates into an operational panel.
 *
 * RESPONSIBILITIES:
 *  - Decide what a given viewer is allowed to see, and build the filter that
 *    enforces it ({@see self::build_filter()}).
 *  - Fetch the aggregates, comparing each range metric against the equivalent
 *    preceding period.
 *  - Decide, per metric, whether there is a real measurement, nothing measured,
 *    or nothing measurable on this installation at all.
 *  - Format values and labels for the template.
 *
 * SECURITY MODEL: the viewer's role is applied when the filter is *built*, not
 * when results are rendered. An agent's filter carries their own user id before
 * a single query runs, so no downstream layer — including the cache — can widen
 * what they see.
 *
 * CACHING: only the raw numbers are cached, never rendered strings, and the
 * cache key carries the whole filter plus role and edition. See db/caches.php.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\dashboard_filter;
use local_moodesk\domain\dashboard_metric;
use local_moodesk\domain\ticket;
use local_moodesk\repository\dashboard_repository;
use local_moodesk\util\user_fields;

/**
 * Assembles the operational dashboard for a given viewer and filter.
 */
class dashboard_service {
    /** Full operational panel: every department, team and agent. */
    public const VIEW_MANAGER = 'manager';

    /** An agent's own queue: the filter is pinned to their user id. */
    public const VIEW_AGENT = 'agent';

    /** A requester's own tickets, regardless of who works them. */
    public const VIEW_REQUESTER = 'requester';

    /** How many tickets the recent-activity panel shows. */
    private const RECENT_ACTIVITY = 5;

    /** @var dashboard_repository */
    protected dashboard_repository $repo;

    /** @var sla_service Computes overdue live rather than reading the stored flag. */
    protected sla_service $sla;

    /** @var \moodle_database Identity lookups only — no aggregates run here. */
    protected \moodle_database $db;

    /**
     * Constructor.
     *
     * @param dashboard_repository|null $repo
     * @param sla_service|null          $sla
     * @param \moodle_database|null     $db
     */
    public function __construct(
        ?dashboard_repository $repo = null,
        ?sla_service $sla = null,
        ?\moodle_database $db = null
    ) {
        global $DB;
        $this->repo = $repo ?? new dashboard_repository();
        $this->sla  = $sla ?? new sla_service();
        $this->db   = $db ?? $DB;
    }

    /*
     * =========================================================================
     * Filter construction — where the viewer's permissions are applied
     * =========================================================================
     */

    /**
     * Build the filter for a viewer, applying their role before any query runs.
     *
     * A manager's selections are honoured as given. An agent's filter is pinned
     * to their own assigned tickets and a requester's to the tickets they
     * raised, and in both cases the incoming department / team / agent
     * parameters are discarded rather than merged: a URL parameter must not be
     * able to widen the scope its owner was granted.
     *
     * @param string   $viewmode     One of the VIEW_* constants.
     * @param int      $userid       The viewer.
     * @param int      $days         Requested range preset; coerced to a supported value.
     * @param int|null $departmentid Manager only. null = any, 0 = no department.
     * @param int|null $agentid      Manager only. null = any, 0 = unassigned.
     * @param int|null $now          Evaluation point (null = Moodle's clock); injectable for tests.
     * @return dashboard_filter
     */
    public function build_filter(
        string $viewmode,
        int $userid,
        int $days = dashboard_filter::RANGE_30,
        ?int $departmentid = dashboard_filter::ANY,
        ?int $agentid = dashboard_filter::ANY,
        ?int $now = null
    ): dashboard_filter {
        $days = dashboard_filter::validate_days($days);
        // Moodle's clock rather than time(), so a test can freeze it.
        $now  = $now ?? self::now();

        if ($viewmode === self::VIEW_AGENT) {
            return dashboard_filter::from_days($days, null, $userid, $now);
        }

        if ($viewmode === self::VIEW_REQUESTER) {
            return dashboard_filter::from_days($days, null, null, $now)
                ->with_requester($userid);
        }

        return dashboard_filter::from_days($days, $departmentid, $agentid, $now);
    }

    /*
     * =========================================================================
     * Public API
     * =========================================================================
     */

    /**
     * Return the full template context for the dashboard.
     *
     * @param dashboard_filter $filter   Already scoped to the viewer.
     * @param string           $viewmode One of the VIEW_* constants.
     * @param int|null         $now      Evaluation point (null = Moodle's clock).
     * @return array Template-ready context.
     */
    public function get_dashboard(
        dashboard_filter $filter,
        string $viewmode = self::VIEW_MANAGER,
        ?int $now = null
    ): array {
        $now = $now ?? self::now();

        return $this->present($this->gather_cached($filter, $viewmode, $now), $filter, $viewmode);
    }

    /*
     * =========================================================================
     * Gathering (cacheable — numbers only)
     * =========================================================================
     */

    /**
     * Fetch the raw aggregates, going through the MUC cache when available.
     *
     * @param dashboard_filter $filter
     * @param string           $viewmode
     * @param int              $now
     * @return array Raw numeric aggregates.
     */
    private function gather_cached(dashboard_filter $filter, string $viewmode, int $now): array {
        $cache = $this->get_cache();
        if ($cache === null) {
            return $this->gather($filter, $viewmode, $now);
        }

        $key = $this->cache_key($filter, $viewmode);

        $cached = $cache->get($key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $data = $this->gather($filter, $viewmode, $now);
        $cache->set($key, $data);

        return $data;
    }

    /**
     * The MUC cache for dashboard aggregates, or null when unavailable.
     *
     * During install, before cache definitions are registered, cache::make()
     * can fail; the dashboard then runs uncached rather than breaking.
     *
     * @return \cache|null
     */
    protected function get_cache(): ?\cache {
        try {
            return \cache::make('local_moodesk', 'dashboard_metrics');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build the cache key for a filter.
     *
     * Everything that changes the answer goes into the key: the window, every
     * scope criterion, the viewer's role, the active edition and the plugin's
     * configuration.
     *
     * The window is bucketed to the minute, and that detail is what makes the
     * cache work at all. A dashboard's window ends at now, so keying on the
     * raw bounds gives every render a unique key and a guaranteed miss — the
     * cache stores diligently and never once reads back. Rounding both bounds
     * lets renders within the same minute share an entry, and it also ages the
     * snapshot metrics, which ignore the window entirely and would otherwise
     * key identically forever. The cost is that a reader can see a window up to
     * a minute stale, which is well inside the 90-second TTL already accepted.
     *
     * Configuration is folded in wholesale rather than cherry-picked. Several
     * settings change what the dashboard reports (the per-priority SLA thresholds decide what counts as
     * overdue), and an admin who saves a setting expects the panel to reflect
     * it immediately rather than after the TTL expires. Hashing the whole
     * config block is cheap — Moodle already caches it — and cannot go stale by
     * omission the way a hand-maintained list would.
     *
     * @param dashboard_filter $filter
     * @param string           $viewmode
     * @return string
     */
    protected function cache_key(dashboard_filter $filter, string $viewmode): string {
        return md5(implode('|', [
            (int) floor($filter->get_from() / MINSECS),
            (int) floor($filter->get_to() / MINSECS),
            $filter->get_departmentid() ?? 'any',
            $filter->get_agentid() ?? 'any',
            $filter->get_requesterid() ?? 'any',
            json_encode($filter->get_plugin_criteria()),
            $viewmode,
            edition_service::get_edition(),
            md5(serialize(get_config('local_moodesk'))),
        ]));
    }

    /**
     * Fetch every aggregate the dashboard needs, as plain numbers.
     *
     * @param dashboard_filter $filter
     * @param string           $viewmode
     * @param int              $now
     * @return array
     */
    private function gather(dashboard_filter $filter, string $viewmode, int $now): array {
        $previous = $filter->previous_period();

        // The current week runs from Monday to now, so its comparison is the
        // same stretch of last week — not previous_period(), which would
        // measure the few days before Monday. Comparing a partial week against
        // a whole one would read as a collapse every Monday morning.
        $weekstart = $this->start_of_week($now);
        $thisweek  = $filter->with_range($weekstart, $now);
        $lastweek  = $filter->with_range($weekstart - WEEKSECS, $now - WEEKSECS);

        $data = [
            // Snapshot counters — the window does not apply to these.
            'active'       => $this->repo->count_active($filter),
            'unassigned'   => $this->repo->count_unassigned_active($filter),
            'breaches'     => $this->repo->count_sla_breached_active($filter),

            // Range counters, each with its equivalent preceding period.
            'resolvedweek' => $this->repo->count_resolved($thisweek),
            'resolvedprev' => $this->repo->count_resolved($lastweek),

            // Breakdowns.
            'status'       => $this->repo->count_active_by_status($filter),
            'priority'     => $this->repo->count_active_by_priority($filter),
            'departments'  => $this->repo->count_active_by_department_status($filter),
            'aging'        => $this->repo->count_active_by_age($filter, $now),

            // Trends.
            'created'      => $this->repo->count_created_by_day($filter),
            'resolved'     => $this->repo->count_resolved_by_day($filter),

            // Durations.
            'response'     => $this->repo->get_first_response_stats($filter),
            'responseprev' => $this->repo->get_first_response_stats($previous),
            'resolution'   => $this->repo->get_resolution_stats($filter),
            'resolutionprev' => $this->repo->get_resolution_stats($previous),

            // SLA.
            'sla'          => $this->repo->get_sla_compliance($filter),
            'slaprev'      => $this->repo->get_sla_compliance($previous),

            'activity'     => $this->repo->get_recent_activity($filter, self::RECENT_ACTIVITY),
            'now'          => $now,
        ];

        return $data;
    }

    /*
     * =========================================================================
     * Presentation
     * =========================================================================
     */

    /**
     * Turn raw aggregates into the template context.
     *
     * @param array            $data
     * @param dashboard_filter $filter
     * @param string           $viewmode
     * @return array
     */
    private function present(array $data, dashboard_filter $filter, string $viewmode): array {

        $context = [
            'range_days'      => $filter->get_range_days() ?? dashboard_filter::RANGE_30,
            'range_label'     => $this->range_label($filter),
            'view_mode'       => $viewmode,
            'is_manager'      => $viewmode === self::VIEW_MANAGER,
            'is_requester'    => $viewmode === self::VIEW_REQUESTER,
            'has_scope'       => $filter->has_scope(),
            'kpis'            => $this->present_kpis($data, $filter),
            'trend'           => $this->present_trend($data, $filter),
            'status'          => $this->present_status($data['status']),
            'priority'        => $this->present_priority($data['priority']),
            'departments'     => $this->present_departments($data['departments']),
            'aging'           => $this->present_aging($data['aging']),
            'activity'        => $this->present_activity($data['activity']),
        ];

        // Whether any panel found anything at all. When none did, the page says
        // so once rather than repeating "not enough data" nine times over — and
        // the caller, which is the side that knows what was filtered, decides
        // whether to offer a way back out.
        $context['has_any_data'] = $this->any_panel_has_data($context);

        return $context;
    }

    /**
     * Whether at least one panel found something to show.
     *
     * The KPI tiles are deliberately not consulted. A count of zero is a real
     * measurement and they will state it — see docs/dashboard-redesign.md §8.2
     * — so asking them would report data on a dashboard that has none.
     *
     * @param array $context
     * @return bool
     */
    private function any_panel_has_data(array $context): bool {
        $panels = [
            'trend', 'status', 'priority', 'departments', 'aging', 'activity',
        ];

        foreach ($panels as $panel) {
            if (!empty($context[$panel]['has_data'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the KPI row.
     *
     * @param array            $data
     * @param dashboard_filter $filter
     * @return array
     */
    private function present_kpis(array $data, dashboard_filter $filter): array {

        // Active and unassigned are snapshots with no delta: mooDesk keeps no
        // historical snapshots, and reconstructing one is wrong for reopened
        // tickets. See docs/dashboard-redesign.md §5.
        $kpis = [
            $this->kpi(
                'active',
                get_string('dashboard_kpi_active', 'local_moodesk'),
                dashboard_metric::count($data['active']),
                'mhd-kpi-blue',
                'ticket',
                get_string('dashboard_note_snapshot', 'local_moodesk')
            ),
        ];

        // Under an agent criterion the unassigned count is zero by definition,
        // so it is labelled rather than shown as a number that looks like news.
        $unassigned = $filter->is_agent_scoped()
            ? dashboard_metric::unavailable()
            : dashboard_metric::count($data['unassigned']);
        $kpis[] = $this->kpi(
            'unassigned',
            get_string('dashboard_kpi_unassigned', 'local_moodesk'),
            $unassigned,
            'mhd-kpi-amber',
            'unassigned',
            get_string('dashboard_note_snapshot', 'local_moodesk')
        );

        $kpis[] = $this->kpi(
            'breaches',
            get_string('dashboard_kpi_sla_breach', 'local_moodesk'),
            dashboard_metric::count($data['breaches']),
            'mhd-kpi-red',
            'sla',
            get_string('dashboard_note_snapshot', 'local_moodesk')
        );

        $kpis[] = $this->kpi(
            'resolvedweek',
            get_string('dashboard_kpi_resolved_week', 'local_moodesk'),
            dashboard_metric::count($data['resolvedweek'], $data['resolvedprev'], true),
            'mhd-kpi-green',
            'resolved',
            get_string('dashboard_note_this_week', 'local_moodesk')
        );

        $kpis[] = $this->kpi(
            'response',
            get_string('dashboard_kpi_first_response', 'local_moodesk'),
            dashboard_metric::average(
                $data['response']->avg_seconds,
                $data['response']->sample,
                $data['responseprev']->avg_seconds,
                false
            ),
            'mhd-kpi-purple',
            'clock',
            $this->range_label($filter),
            true
        );

        $kpis[] = $this->kpi(
            'resolution',
            get_string('dashboard_kpi_resolution_time', 'local_moodesk'),
            dashboard_metric::average(
                $data['resolution']->avg_seconds,
                $data['resolution']->sample,
                $data['resolutionprev']->avg_seconds,
                false
            ),
            'mhd-kpi-teal',
            'hourglass',
            $this->range_label($filter),
            true
        );

        $kpis[] = $this->kpi(
            'compliance',
            get_string('dashboard_kpi_sla_compliance', 'local_moodesk'),
            $this->compliance_metric($data['sla'], $data['slaprev']),
            'mhd-kpi-indigo',
            'target',
            $this->range_label($filter)
        );

        // Panels this installation cannot produce are dropped, not rendered
        // empty: an upgrade prompt belongs on the page, not inside a KPI tile.
        return array_values(array_filter($kpis, static function (array $kpi): bool {
            return $kpi['is_available'];
        }));
    }

    /**
     * Shape one KPI for the template.
     *
     * @param string           $key
     * @param string           $label
     * @param dashboard_metric $metric
     * @param string           $cssclass
     * @param string           $icon
     * @param string           $note      Clarifies which window the figure covers.
     * @param bool             $isduration Format the value as a duration.
     * @return array
     */
    protected function kpi(
        string $key,
        string $label,
        dashboard_metric $metric,
        string $cssclass,
        string $icon,
        string $note = '',
        bool $isduration = false
    ): array {
        $display = get_string('dashboard_no_data_short', 'local_moodesk');
        if ($metric->has_data()) {
            $value = $metric->get_value();
            if ($isduration) {
                $display = $this->format_duration((float) $value);
            } else if ($key === 'compliance') {
                $display = format_float((float) $value, 1) . '%';
            } else if ($key === 'csat') {
                $display = format_float((float) $value, 1);
            } else {
                $display = (string) (int) $value;
            }
        }

        $delta     = $metric->get_delta_pct();
        $direction = $metric->get_delta_direction();

        // Movement is stated three ways, and each has to survive the loss of the
        // other two. The arrow is decorative, the colour is not available to a
        // screen reader, and neither is any use to a reader who cannot tell red
        // from green — so the direction is also said in words.
        $directionlabels = [
            dashboard_metric::DIRECTION_UP   => 'dashboard_delta_increase',
            dashboard_metric::DIRECTION_DOWN => 'dashboard_delta_decrease',
            dashboard_metric::DIRECTION_FLAT => 'dashboard_delta_flat',
        ];

        return [
            'key'           => $key,
            'label'         => $label,
            'css_class'     => $cssclass,
            'icon'          => $icon,
            'note'          => $note,
            'is_available'  => $metric->is_available(),
            'has_data'      => $metric->has_data(),
            'no_data_label' => get_string('dashboard_no_data', 'local_moodesk'),
            'value'         => $metric->get_value(),
            'display'       => $display,
            'sample'        => $metric->get_sample(),
            'has_delta'     => $metric->has_delta(),
            'delta_display' => ($delta !== null) ? format_float(abs($delta), 1) . '%' : '',
            'delta_up'      => $direction === dashboard_metric::DIRECTION_UP,
            'delta_down'    => $direction === dashboard_metric::DIRECTION_DOWN,
            'delta_label'   => ($direction !== null)
                ? get_string($directionlabels[$direction], 'local_moodesk')
                : '',
            'is_improvement' => $metric->is_improvement(),
            // Good and bad are stated separately because there is a third case:
            // a value that did not move is neither, and a template that
            // inverted is_improvement would paint it as the bad news it is not.
            'delta_good'    => $metric->is_improvement() === true,
            'delta_bad'     => $metric->is_improvement() === false,
            'delta_note'    => ($delta !== null)
                ? get_string('dashboard_vs_previous', 'local_moodesk')
                : '',
        ];
    }

    /**
     * Build the SLA compliance metric.
     *
     * Compliance over zero resolved tickets is not zero per cent — that would
     * be a slander on a team that simply had nothing to resolve. It is no data.
     *
     * @param \stdClass $current
     * @param \stdClass $previous
     * @return dashboard_metric
     */
    protected function compliance_metric(\stdClass $current, \stdClass $previous): dashboard_metric {
        if ($current->total <= 0) {
            return dashboard_metric::no_data(true);
        }

        $pct     = ($current->within_sla / $current->total) * 100;
        $prevpct = ($previous->total > 0)
            ? ($previous->within_sla / $previous->total) * 100
            : null;

        return dashboard_metric::average($pct, $current->total, $prevpct, true);
    }

    /**
     * Build the tickets-over-time series, filling days that have no tickets.
     *
     * SQL returns only days with activity; a line chart needs a point for every
     * day, but the database should not invent rows to provide them.
     *
     * @param array            $data
     * @param dashboard_filter $filter
     * @return array
     */
    private function present_trend(array $data, dashboard_filter $filter): array {
        $created  = $this->index_by_day($data['created']);
        $resolved = $this->index_by_day($data['resolved']);

        // Day buckets are UTC midnights, matching the FLOOR() arithmetic the
        // repository uses to stay portable across MySQL and PostgreSQL.
        $daysecs = DAYSECS;
        $start   = (int) (floor($filter->get_from() / $daysecs) * $daysecs);
        $end     = (int) (floor($filter->get_to() / $daysecs) * $daysecs);

        $points = [];
        $totalcreated = 0;
        $totalresolved = 0;
        $peak = 0;
        for ($day = $start; $day <= $end; $day += $daysecs) {
            $c = $created[$day] ?? 0;
            $r = $resolved[$day] ?? 0;
            $totalcreated  += $c;
            $totalresolved += $r;
            $peak = max($peak, $c, $r);
            $label = userdate($day, get_string('strftimedateshort', 'langconfig'));
            $points[] = [
                'day'      => $day,
                'label'    => $label,
                'created'  => $c,
                'resolved' => $r,
                // The bars carry their figures in their height, which is no use
                // to a screen reader — and once Chart.js replaces them with a
                // canvas, this sentence is the only reading of the day left.
                'sr_label' => get_string('dashboard_trend_point_sr', 'local_moodesk', (object) [
                    'date'     => $label,
                    'created'  => $c,
                    'resolved' => $r,
                ]),
            ];
        }

        // Bar heights for the CSS rendering, scaled against the busiest day so
        // both series share one axis and stay comparable — which is the whole
        // question the panel answers. Chart.js takes the raw counts instead.
        foreach ($points as &$point) {
            $point['created_pct']  = $this->percentage($point['created'], $peak);
            $point['resolved_pct'] = $this->percentage($point['resolved'], $peak);
        }
        unset($point);

        return [
            'is_available'   => true,
            'has_data'       => ($totalcreated + $totalresolved) > 0,
            'no_data_label'  => get_string('dashboard_no_data', 'local_moodesk'),
            'points'         => $points,
            'chart'          => $this->chart_payload([
                'labels'   => array_column($points, 'label'),
                'created'  => array_column($points, 'created'),
                'resolved' => array_column($points, 'resolved'),
                'legend'   => [
                    'created'  => get_string('dashboard_created', 'local_moodesk'),
                    'resolved' => get_string('dashboard_resolved', 'local_moodesk'),
                ],
            ]),
            'total_created'  => $totalcreated,
            'total_resolved' => $totalresolved,
            // A backlog grows when more arrives than leaves. Stating it saves
            // the reader from eyeballing two lines to reach the same conclusion.
            'backlog_delta'  => $totalcreated - $totalresolved,
            'backlog_rising' => $totalcreated > $totalresolved,
        ];
    }

    /**
     * Index day-bucket rows by their timestamp.
     *
     * @param array $rows
     * @return array<int, int>
     */
    private function index_by_day(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->day_bucket] = (int) $row->cnt;
        }
        return $out;
    }

    /**
     * Build the status breakdown with percentages.
     *
     * @param array $rows
     * @return array
     */
    private function present_status(array $rows): array {
        $labels = ticket::status_labels();

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row->cnt;
        }

        $out = [];
        foreach ($rows as $row) {
            $status = (int) $row->status;
            $count  = (int) $row->cnt;
            $out[] = [
                'key'     => $status,
                'label'   => $labels[$status] ?? (string) $status,
                'count'   => $count,
                'pct'     => $this->percentage($count, $total),
                'pct_display' => $this->percentage_display($count, $total),
                'css'     => "mhd-status-dot mhd-status-dot-{$status}",
            ];
        }

        return [
            'is_available'  => true,
            'has_data'      => $total > 0,
            'no_data_label' => get_string('dashboard_no_data', 'local_moodesk'),
            'total'         => $total,
            'rows'          => $out,
            'chart'         => $this->chart_payload([
                'labels' => array_column($out, 'label'),
                'values' => array_column($out, 'count'),
                // Statuses keep their own hue everywhere in the product, so the
                // doughnut reads the same as the pills on the ticket list.
                'keys'   => array_column($out, 'key'),
                'total'  => $total,
                'centre' => get_string('dashboard_active_label', 'local_moodesk'),
            ]),
        ];
    }

    /**
     * Build the priority breakdown with percentages.
     *
     * @param array $rows
     * @return array
     */
    private function present_priority(array $rows): array {
        $labels = ticket::priority_labels();

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row->cnt;
        }

        $out = [];
        foreach ($rows as $row) {
            $priority = (int) $row->priority;
            $count    = (int) $row->cnt;
            $out[] = [
                'key'         => $priority,
                'label'       => $labels[$priority] ?? (string) $priority,
                'count'       => $count,
                'pct'         => $this->percentage($count, $total),
                'pct_display' => $this->percentage_display($count, $total),
                'css'         => "mhd-priority-bar mhd-priority-bar-{$priority}",
            ];
        }

        return [
            'is_available'  => true,
            'has_data'      => $total > 0,
            'no_data_label' => get_string('dashboard_no_data', 'local_moodesk'),
            'total'         => $total,
            'rows'          => $out,
            'chart'         => $this->chart_payload([
                'labels' => array_column($out, 'label'),
                'values' => array_column($out, 'count'),
                // Priorities keep their own hue everywhere in the product, so
                // the pie reads the same as the pills on the ticket list.
                'keys'   => array_column($out, 'key'),
                'total'  => $total,
            ]),
        ];
    }

    /**
     * Build the stacked per-department breakdown.
     *
     * Grouping is keyed on departmentid, never the name: two departments may
     * legitimately share one, and keying on it would merge their tickets.
     *
     * @param array $rows
     * @return array
     */
    private function present_departments(array $rows): array {
        $labels = ticket::status_labels();
        $nodept = get_string('dept_none', 'local_moodesk');

        $depts = [];
        foreach ($rows as $row) {
            $key = ($row->departmentid === null) ? 'none' : (string) (int) $row->departmentid;
            if (!isset($depts[$key])) {
                $depts[$key] = [
                    'label'    => ($row->deptname !== '') ? $row->deptname : $nodept,
                    'total'    => 0,
                    'segments' => [],
                ];
            }
            $status = (int) $row->status;
            $count  = (int) $row->cnt;
            $depts[$key]['total'] += $count;
            $depts[$key]['segments'][] = [
                'key'   => $status,
                'label' => $labels[$status] ?? (string) $status,
                'count' => $count,
                'css'   => "mhd-seg mhd-seg-{$status}",
            ];
        }

        // Segment widths are shares of each department's own bar, so a small
        // department still reads as a full-width stack of its own statuses.
        $total = 0;
        foreach ($depts as &$dept) {
            $total += $dept['total'];
            foreach ($dept['segments'] as &$segment) {
                $segment['pct'] = $this->percentage($segment['count'], $dept['total']);
            }
            unset($segment);
        }
        unset($dept);

        $depts = array_values($depts);
        usort($depts, static function (array $a, array $b): int {
            return $b['total'] <=> $a['total'];
        });

        // Bar length compares departments against the busiest one.
        $max = $depts ? (int) $depts[0]['total'] : 0;
        foreach ($depts as &$dept) {
            $dept['width'] = $this->percentage($dept['total'], $max);
        }
        unset($dept);

        return [
            'is_available'  => true,
            'has_data'      => $total > 0,
            'no_data_label' => get_string('dashboard_no_data', 'local_moodesk'),
            'rows'          => $depts,
            'legend'        => $this->status_legend(),
        ];
    }

    /**
     * The status legend shared by the stacked department bars.
     *
     * @return array
     */
    private function status_legend(): array {
        $out = [];
        foreach (ticket::status_labels() as $status => $label) {
            if (in_array($status, [ticket::STATUS_RESOLVED, ticket::STATUS_CLOSED], true)) {
                continue;
            }
            $out[] = [
                'key'   => $status,
                'label' => $label,
                'css'   => "mhd-seg mhd-seg-{$status}",
            ];
        }
        return $out;
    }

    /**
     * Build the ticket-aging bands.
     *
     * @param array $rows
     * @return array
     */
    private function present_aging(array $rows): array {
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row->cnt;
        }

        $out = [];
        $previous = 0;
        foreach ($rows as $row) {
            $count = (int) $row->cnt;
            $upper = $row->upper_days;

            if ($upper === null) {
                $label = get_string('dashboard_aging_over', 'local_moodesk', $previous);
            } else if ($previous === 0) {
                $label = get_string('dashboard_aging_under', 'local_moodesk', $upper * 24);
            } else {
                $label = get_string('dashboard_aging_between', 'local_moodesk', (object) [
                    'from' => $previous,
                    'to'   => $upper,
                ]);
            }

            $out[] = [
                'key'         => (int) $row->bucket,
                'label'       => $label,
                'count'       => $count,
                'pct'         => $this->percentage($count, $total),
                'pct_display' => $this->percentage_display($count, $total),
                // Older bands carry more visual weight — the whole point of the
                // panel is that age is the problem.
                'css'         => 'mhd-aging-bar mhd-aging-bar-' . (int) $row->bucket,
            ];

            $previous = ($upper === null) ? $previous : $upper;
        }

        return [
            'is_available'  => true,
            'has_data'      => $total > 0,
            'no_data_label' => get_string('dashboard_no_data', 'local_moodesk'),
            'total'         => $total,
            'rows'          => $out,
            'chart'         => $this->chart_payload([
                'labels' => array_column($out, 'label'),
                'values' => array_column($out, 'count'),
                // Bands darken with age everywhere they are drawn, so the pie
                // reads the same as the bars it replaces.
                'keys'   => array_column($out, 'key'),
                'total'  => $total,
            ]),
        ];
    }

    /**
     * Build the recent-activity list.
     *
     * @param array $rows
     * @return array
     */
    private function present_activity(array $rows): array {
        $statuslabels   = ticket::status_labels();
        $prioritylabels = ticket::priority_labels();

        $out = [];
        foreach ($rows as $row) {
            $status   = (int) $row->status;
            $priority = (int) $row->priority;
            $out[] = [
                'id'       => (int) $row->id,
                'ref'      => '#' . (int) $row->id,
                'subject'  => $row->subject,
                'url'      => (new \moodle_url(
                    '/local/moodesk/view.php',
                    ['id' => (int) $row->id]
                ))->out(false),
                'status'         => $statuslabels[$status] ?? (string) $status,
                'status_css'     => "mhd-pill mhd-pill--status-{$status}",
                'priority'       => $prioritylabels[$priority] ?? (string) $priority,
                'priority_css'   => "mhd-pill mhd-pill--priority-{$priority}",
                // Priority earns a badge only when it is worth interrupting for.
                'show_priority'  => in_array(
                    $priority,
                    [ticket::PRIORITY_HIGH, ticket::PRIORITY_URGENT],
                    true
                ),
                'timeago'        => $this->format_time_ago((int) $row->timemodified),
            ];
        }

        return [
            'is_available'  => true,
            'has_data'      => !empty($out),
            'no_data_label' => get_string('dashboard_no_data', 'local_moodesk'),
            'rows'          => $out,
        ];
    }

    /**
     * A panel this installation cannot produce.
     *
     * @return array
     */
    protected function unavailable_panel(): array {
        return [
            'is_available'  => false,
            'has_data'      => false,
            'no_data_label' => get_string('dashboard_no_data', 'local_moodesk'),
            'rows'          => [],
        ];
    }

    /*
     * =========================================================================
     * Formatting helpers
     * =========================================================================
     */

    /**
     * Format a duration compactly: 42m, 8h 32m, 3d 4h.
     *
     * Moodle's format_time() is too verbose for a dense panel ("1 hour 14
     * mins"), so this uses plugin strings that keep the units translatable
     * while staying short enough to sit inside a KPI tile.
     *
     * @param float $seconds
     * @return string
     */
    protected function format_duration(float $seconds): string {
        $seconds = (int) round(max(0, $seconds));

        if ($seconds < MINSECS) {
            return get_string('dashboard_duration_under_minute', 'local_moodesk');
        }

        if ($seconds < HOURSECS) {
            return get_string('dashboard_duration_m', 'local_moodesk', intdiv($seconds, MINSECS));
        }

        if ($seconds < DAYSECS) {
            $hours   = intdiv($seconds, HOURSECS);
            $minutes = intdiv($seconds % HOURSECS, MINSECS);

            return ($minutes === 0)
                ? get_string('dashboard_duration_h', 'local_moodesk', $hours)
                : get_string('dashboard_duration_hm', 'local_moodesk', (object) [
                    'h' => $hours,
                    'm' => $minutes,
                ]);
        }

        $days  = intdiv($seconds, DAYSECS);
        $hours = intdiv($seconds % DAYSECS, HOURSECS);

        return ($hours === 0)
            ? get_string('dashboard_duration_d', 'local_moodesk', $days)
            : get_string('dashboard_duration_dh', 'local_moodesk', (object) [
                'd' => $days,
                'h' => $hours,
            ]);
    }

    /**
     * Format how long ago something happened, as "27m ago".
     *
     * @param int      $timestamp
     * @param int|null $now
     * @return string
     */
    private function format_time_ago(int $timestamp, ?int $now = null): string {
        $elapsed = max(0, ($now ?? self::now()) - $timestamp);

        return get_string('dashboard_time_ago', 'local_moodesk', $this->format_duration((float) $elapsed));
    }

    /**
     * Encode a chart's data for the template.
     *
     * The result is written into a data- attribute, which Mustache escapes, so
     * the browser hands the module back valid JSON without the template ever
     * having to emit unescaped user-supplied text — department and category
     * names reach this payload straight from the database.
     *
     * @param array $data
     * @return string JSON.
     */
    protected function chart_payload(array $data): string {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A share of a total, as an integer percentage. Zero total yields zero.
     *
     * @param int $value
     * @param int $total
     * @return int
     */
    protected function percentage(int $value, int $total): int {
        return ($total > 0) ? (int) round(($value / $total) * 100) : 0;
    }

    /**
     * A share of a total, formatted for display, or empty when there is no base.
     *
     * @param int $value
     * @param int $total
     * @return string
     */
    protected function percentage_display(int $value, int $total): string {
        return ($total > 0) ? $this->percentage($value, $total) . '%' : '';
    }

    /**
     * The active window, as a label the UI can put beside a range metric.
     *
     * @param dashboard_filter $filter
     * @return string
     */
    protected function range_label(dashboard_filter $filter): string {
        $days = $filter->get_range_days();
        if ($days === null) {
            return '';
        }

        return get_string('dashboard_range_last_days', 'local_moodesk', $days);
    }

    /**
     * Resolve display names for a set of user ids.
     *
     * An identity lookup, not an aggregate — which is why it lives here rather
     * than in dashboard_repository, whose contract is queries over the ticket
     * table.
     *
     * @param array $userids
     * @return array<int, string>
     */
    protected function resolve_names(array $userids): array {
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        if (empty($userids)) {
            return [];
        }

        $records = $this->db->get_records_list('user', 'id', $userids, '', user_fields::for_fullname());

        $names = [];
        foreach ($records as $record) {
            $names[(int) $record->id] = fullname($record);
        }

        return $names;
    }

    /**
     * Return the Unix timestamp for the start of the current week (Monday 00:00 local time).
     *
     * @param int|null $now
     * @return int
     */
    private function start_of_week(?int $now = null): int {
        $date = usergetdate($now ?? self::now());
        $dow  = (int) $date['wday']; // 0 = Sunday.
        // Days since Monday (ISO week: Monday = start).
        $dayssincemonday = ($dow === 0) ? 6 : ($dow - 1);

        return mktime(0, 0, 0, $date['mon'], $date['mday'] - $dayssincemonday, $date['year']);
    }

    /**
     * The current time from Moodle's clock, which a test can freeze.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
