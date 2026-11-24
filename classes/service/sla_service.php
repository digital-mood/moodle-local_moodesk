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
 * SLA service — SLA evaluation logic.
 *
 * This service never writes: it receives a ticket stdClass and reads SLA
 * thresholds from plugin config. The scheduled task check_sla_breaches is
 * responsible for persistence.
 *
 * It is no longer purely stateless for the resolution dimension. That clock
 * stops while a ticket waits on the requester, and a stopped clock cannot be
 * recomputed from timestamps alone, so the ticket carries how long it has been
 * paused (sla_paused_seconds, sla_paused_since). Callers must select those two
 * columns along with timecreated/timefirstresponse/timeresolved; a record
 * without them evaluates as never paused, which is what every ticket looked
 * like before this existed.
 *
 * SLA STATUS VALUES:
 *   'ok'      — No thresholds breached, no imminent risk.
 *   'warning' — Within the warning window (20% of threshold remaining).
 *   'breach'  — Deadline has passed without response or resolution.
 *   'none'    — SLA is disabled for this priority (threshold = 0).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\ticket;

/**
 * Stateless SLA evaluation service.
 */
class sla_service {
    /**
     * Statuses the SLA no longer applies to: get_status() answers 'ok' for them
     * without looking at any deadline.
     */
    public const TERMINAL_STATUSES = [ticket::STATUS_RESOLVED, ticket::STATUS_CLOSED];

    /** Warning window as a fraction of the total SLA threshold. */
    private const WARNING_FRACTION = 0.20;

    /**
     * In-process cache of plugin overrides, by department.
     * Avoids asking again when evaluating SLA for a batch of tickets that
     * belong to the same department (e.g. in check_sla_breaches task).
     *
     * @var array<int, array<string, int>>
     */
    private array $deptcache = [];

    /**
     * Ask plugins once for the overrides of a set of departments.
     *
     * The per-department cache above bounds the cost of a batch by the number
     * of distinct departments in it, not by tickets — which on a 20-row list
     * page still meant one read per department on the page (scale bench
     * 2026-09-16 §7.1). A caller that knows the departments it is about to
     * evaluate primes them here, and get_thresholds() then never touches the
     * database for them. A department with no override is remembered too.
     *
     * @param int[] $departmentids Ids to load; unknown, duplicate and already
     *                             cached ids are ignored.
     */
    public function prime_departments(array $departmentids): void {
        $missing = [];
        foreach ($departmentids as $id) {
            $id = (int) $id;
            if ($id > 0 && !array_key_exists($id, $this->deptcache)) {
                $missing[$id] = $id;
            }
        }
        if (empty($missing)) {
            return;
        }

        $hook = new \local_moodesk\hook\sla_overrides(array_values($missing));
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        foreach ($missing as $id) {
            $this->deptcache[$id] = $hook->get_hours($id);
        }
    }

    /**
     * Return the SLA thresholds (in seconds) for a given priority.
     *
     * When $departmentid is provided and a plugin overrides this priority for it
     * (hook sla_overrides), that value takes precedence over the global config.
     *
     * Returns an array with:
     *   'response' => int  (0 = disabled)
     *   'resolve'  => int  (0 = disabled)
     *
     * @param int      $priority      One of ticket::PRIORITY_* constants.
     * @param int|null $departmentid  Department ID, or null for global thresholds.
     * @return array{response: int, resolve: int}
     */
    public function get_thresholds(int $priority, ?int $departmentid = null): array {
        $suffix = $this->priority_suffix($priority);

        $overrides = [];
        if ($departmentid !== null) {
            $this->prime_departments([$departmentid]);
            $overrides = $this->deptcache[$departmentid] ?? [];
        }

        // An override, including 0 to disable, replaces the global threshold.
        $responsehours = $overrides["response_{$suffix}"] ?? (int) get_config('local_moodesk', "sla_response_hours_{$suffix}");
        $resolvehours  = $overrides["resolve_{$suffix}"] ?? (int) get_config('local_moodesk', "sla_resolve_hours_{$suffix}");

        return [
            'response' => $responsehours * HOURSECS,
            'resolve'  => $resolvehours * HOURSECS,
        ];
    }

    /**
     * Compute the overall SLA status for a ticket at a given point in time.
     *
     * Priority is given to the worst status found across response and resolve
     * checks: breach > warning > ok > none.
     *
     * Closed or already-resolved tickets always return 'ok' — SLA stops when
     * the ticket reaches a terminal state.
     *
     * When the ticket has a departmentid, per-department SLA overrides are
     * automatically applied via get_thresholds().
     *
     * @param \stdClass $ticket  Raw ticket record (must have priority, status,
     *                           timecreated, timefirstresponse, timeresolved).
     * @param int|null  $now     Unix timestamp to evaluate against (null = time()).
     * @return string  'ok' | 'warning' | 'breach' | 'none'
     */
    public function get_status(\stdClass $ticket, ?int $now = null): string {
        $now = $now ?? time();

        // Terminal statuses — SLA no longer applies.
        // STATUS_INPROGRESS = 6 > STATUS_RESOLVED = 4, so ">=" is wrong here.
        // Check explicitly against the terminal values.
        if (in_array((int) $ticket->status, self::TERMINAL_STATUSES, true)) {
            return 'ok';
        }

        $departmentid = !empty($ticket->departmentid) ? (int) $ticket->departmentid : null;
        $thresholds   = $this->get_thresholds((int) $ticket->priority, $departmentid);

        $responsestatus = $this->check_response($ticket, $thresholds['response'], $now);
        $resolvestatus  = $this->check_resolve($ticket, $thresholds['resolve'], $now);

        return $this->worst_status($responsestatus, $resolvestatus);
    }

    /**
     * The statuses whose tickets the SLA is evaluated for: every status but the
     * terminal ones. A caller looking for tickets in warning or breach can ask
     * the database for these first instead of evaluating closed history.
     *
     * @return int[]
     */
    public static function evaluated_statuses(): array {
        $all = [
            ticket::STATUS_NEW,
            ticket::STATUS_OPEN,
            ticket::STATUS_PENDING,
            ticket::STATUS_ON_HOLD,
            ticket::STATUS_RESOLVED,
            ticket::STATUS_CLOSED,
            ticket::STATUS_INPROGRESS,
        ];
        return array_values(array_diff($all, self::TERMINAL_STATUSES));
    }

    /**
     * Return true if the ticket has a confirmed SLA breach (response or resolve).
     *
     * @param \stdClass $ticket
     * @param int|null  $now
     * @return bool
     */
    public function is_breached(\stdClass $ticket, ?int $now = null): bool {
        return $this->get_status($ticket, $now) === 'breach';
    }

    /**
     * Return a short formatted time label for the ticket list SLA badge.
     *
     * Returns:
     *   ''                  — SLA disabled or ticket is ok (no badge needed).
     *   'OVERDUE Xh ago'    — One or more thresholds exceeded; shows overdue duration.
     *   '2h 15m'            — Time remaining until the nearest deadline (warning window).
     *
     * @param \stdClass $ticket
     * @param int|null  $now  Unix timestamp (null = current time).
     * @return string
     */
    public function get_time_label(\stdClass $ticket, ?int $now = null): string {
        $now    = $now ?? time();
        $status = $this->get_status($ticket, $now);

        if ($status === 'none' || $status === 'ok') {
            return '';
        }

        $departmentid = !empty($ticket->departmentid) ? (int) $ticket->departmentid : null;
        $thresholds   = $this->get_thresholds((int) $ticket->priority, $departmentid);

        if ($status === 'breach') {
            // Find the earliest breached deadline to show how long overdue.
            $earliestbreach = PHP_INT_MAX;
            if ($thresholds['response'] > 0 && empty($ticket->timefirstresponse)) {
                $dl = (int) $ticket->timecreated + $thresholds['response'];
                if ($now >= $dl) {
                    $earliestbreach = min($earliestbreach, $dl);
                }
            }
            if ($thresholds['resolve'] > 0 && empty($ticket->timeresolved)) {
                $dl = $this->resolve_deadline($ticket, $thresholds['resolve'], $now);
                if ($now >= $dl) {
                    $earliestbreach = min($earliestbreach, $dl);
                }
            }
            if ($earliestbreach < PHP_INT_MAX) {
                return 'OVERDUE ' . $this->format_duration($now - $earliestbreach) . ' ago';
            }
            return 'OVERDUE';
        }

        // Warning — find the nearest upcoming deadline.
        $deadlines = [];
        if ($thresholds['response'] > 0 && empty($ticket->timefirstresponse)) {
            $deadlines[] = (int) $ticket->timecreated + $thresholds['response'];
        }
        if ($thresholds['resolve'] > 0 && empty($ticket->timeresolved)) {
            $deadlines[] = $this->resolve_deadline($ticket, $thresholds['resolve'], $now);
        }

        if (empty($deadlines)) {
            return '';
        }

        $remaining = min($deadlines) - $now;
        return $remaining > 0 ? $this->format_duration_precise($remaining) : 'OVERDUE';
    }

    /**
     * Return a rich SLA context array for the ticket detail sidebar panel.
     *
     * Computes real-time progress and status for both first-response and
     * resolution SLA dimensions. Intended for the ticket_detail_sidebar template.
     *
     * Returns:
     *   'has_sla'    bool    True when at least one SLA threshold is configured.
     *   'status'     string  Overall SLA status: 'ok'|'warning'|'breach'|'none'.
     *   'is_breach'  bool
     *   'is_warning' bool
     *   'response'   array   Per-dimension SLA item (see build_sla_item).
     *   'resolve'    array   Per-dimension SLA item.
     *
     * @param \stdClass $ticket  Raw ticket DB record.
     * @param int|null  $now     Unix timestamp (null = time()).
     * @return array
     */
    public function get_sla_context(\stdClass $ticket, ?int $now = null): array {
        $now = $now ?? time();

        $departmentid = !empty($ticket->departmentid) ? (int) $ticket->departmentid : null;
        $thresholds   = $this->get_thresholds((int) $ticket->priority, $departmentid);

        $responseachieved = !empty($ticket->timefirstresponse) ? (int) $ticket->timefirstresponse : null;
        $resolveachieved  = !empty($ticket->timeresolved) ? (int) $ticket->timeresolved : null;

        $responseitem = $this->build_sla_item(
            $thresholds['response'],
            (int) $ticket->timecreated,
            $responseachieved,
            $now
        );
        // Only the resolution dimension pauses. First response stops on its own
        // the moment an agent replies, so it never needs the offset.
        $resolveitem = $this->build_sla_item(
            $thresholds['resolve'],
            (int) $ticket->timecreated,
            $resolveachieved,
            $now,
            $this->paused_seconds($ticket, $now),
            $this->is_paused_now($ticket)
        );

        $hassla = ($thresholds['response'] > 0 || $thresholds['resolve'] > 0);
        $status  = $this->get_status($ticket, $now);

        return [
            'has_sla'    => $hassla,
            'status'     => $status,
            'is_breach'  => ($status === 'breach'),
            'is_warning' => ($status === 'warning'),
            'response'   => $responseitem,
            'resolve'    => $resolveitem,
        ];
    }

    /*
     * -------------------------------------------------------------------------
     * Private helpers
     * -------------------------------------------------------------------------
     */

    /**
     * Check first-response SLA.
     *
     * @param \stdClass $ticket
     * @param int       $thresholdseconds  0 = disabled.
     * @param int       $now
     * @return string  'ok' | 'warning' | 'breach' | 'none'
     */
    private function check_response(\stdClass $ticket, int $thresholdseconds, int $now): string {
        if ($thresholdseconds <= 0) {
            return 'none';
        }

        // Already responded — SLA met.
        if (!empty($ticket->timefirstresponse)) {
            return 'ok';
        }

        $deadline = (int) $ticket->timecreated + $thresholdseconds;

        if ($now >= $deadline) {
            return 'breach';
        }

        $warningwindow = (int) ($thresholdseconds * self::WARNING_FRACTION);
        if ($now >= ($deadline - $warningwindow)) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Check resolution SLA.
     *
     * @param \stdClass $ticket
     * @param int       $thresholdseconds  0 = disabled.
     * @param int       $now
     * @return string  'ok' | 'warning' | 'breach' | 'none'
     */
    private function check_resolve(\stdClass $ticket, int $thresholdseconds, int $now): string {
        if ($thresholdseconds <= 0) {
            return 'none';
        }

        // Already resolved or closed — SLA met.
        if (!empty($ticket->timeresolved)) {
            return 'ok';
        }

        $deadline = $this->resolve_deadline($ticket, $thresholdseconds, $now);

        if ($now >= $deadline) {
            return 'breach';
        }

        $warningwindow = (int) ($thresholdseconds * self::WARNING_FRACTION);
        if ($now >= ($deadline - $warningwindow)) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Return true when the resolution clock is stopped right now.
     *
     * Both halves have to agree: the ticket is in pending and carries an open
     * marker. A stale marker on a ticket that has moved on grants nothing —
     * better to keep the clock running than to hand out budget on the strength
     * of a column nobody closed.
     *
     * @param \stdClass $ticket
     * @return bool
     */
    private function is_paused_now(\stdClass $ticket): bool {
        return (int) $ticket->status === ticket::STATUS_PENDING
            && ($ticket->sla_paused_since ?? null) !== null;
    }

    /**
     * Return how many seconds of this ticket's life do not count against the
     * resolution SLA.
     *
     * That is every closed pause interval, plus the one still open when the
     * ticket is sitting in pending right now. The columns are written by
     * ticket::sla_pause_fields() on each status change; a ticket record that
     * predates them, or that a caller selected without them, reads as zero and
     * behaves exactly as it did before pausing existed.
     *
     * @param \stdClass $ticket
     * @param int       $now
     * @return int
     */
    private function paused_seconds(\stdClass $ticket, int $now): int {
        $paused = isset($ticket->sla_paused_seconds) ? (int) $ticket->sla_paused_seconds : 0;

        if ($this->is_paused_now($ticket)) {
            $paused += max(0, $now - (int) $ticket->sla_paused_since);
        }

        return $paused;
    }

    /**
     * Return the resolution deadline for a ticket, pause included.
     *
     * The single place this arithmetic lives. get_status, get_time_label and
     * get_sla_context all read it from here: three copies of "timecreated +
     * threshold" is exactly how the list and the panel came to disagree.
     *
     * @param \stdClass $ticket
     * @param int       $thresholdseconds
     * @param int       $now
     * @return int  Unix timestamp.
     */
    private function resolve_deadline(\stdClass $ticket, int $thresholdseconds, int $now): int {
        return (int) $ticket->timecreated + $thresholdseconds + $this->paused_seconds($ticket, $now);
    }

    /**
     * Format a duration in seconds as a compact human-readable string.
     * Examples: '<1m', '45m', '3h', '2d'.
     *
     * @param int $seconds
     * @return string
     */
    private function format_duration(int $seconds): string {
        if ($seconds < MINSECS) {
            return '<1m';
        }
        // Pick the unit after rounding, so 59.5 minutes reads '1h', not '60m'.
        $minutes = (int) round($seconds / MINSECS);
        if ($minutes < 60) {
            return $minutes . 'm';
        }
        $hours = (int) round($seconds / HOURSECS);
        if ($hours < 24) {
            return $hours . 'h';
        }
        return (int) round($seconds / DAYSECS) . 'd';
    }

    /**
     * Format a duration in seconds as a precise "Xh Ym" string.
     * Examples: '<1m', '45m', '2h 15m', '3h', '2d'.
     *
     * Used for the ticket list SLA badge (warning state) and the sidebar panel.
     *
     * @param int $seconds
     * @return string
     */
    private function format_duration_precise(int $seconds): string {
        if ($seconds < MINSECS) {
            return '<1m';
        }
        // Round once, to whole minutes, and split afterwards: flooring the hours
        // and rounding the leftover gave '7h 60m' for 7 h 59.5 min.
        $minutes = (int) round($seconds / MINSECS);
        if ($minutes < 60) {
            return $minutes . 'm';
        }
        if ($minutes < 24 * 60) {
            $hours = intdiv($minutes, 60);
            $mins  = $minutes % 60;
            return $mins > 0 ? $hours . 'h ' . $mins . 'm' : $hours . 'h';
        }
        return (int) round($seconds / DAYSECS) . 'd';
    }

    /**
     * Build the SLA context array for one dimension (response or resolve).
     *
     * Returns an array consumed by the ticket_detail_sidebar Mustache template:
     *   'enabled'     bool    False when threshold_seconds = 0 (SLA disabled).
     *   'met'         bool    True when timeachieved is set.
     *   'pct'         int     0-100 elapsed percentage.
     *   'color_class' string  sla-ok | sla-warning | sla-danger | sla-breach | sla-none.
     *   'remaining'   string  Human-readable label ('2h 15m', 'OVERDUE 3h ago', 'Met', …).
     *
     * @param int      $thresholdseconds  0 = disabled.
     * @param int      $timecreated
     * @param int|null $timeachieved   Unix ts when SLA was met; null = not yet achieved.
     * @param int      $now
     * @param int      $pausedseconds  Time that does not count against this dimension,
     *                                 pushing the deadline out by as much. Always 0
     *                                 for first response, which never pauses.
     * @param bool     $ispaused       True when the clock is stopped right now.
     * @return array
     */
    private function build_sla_item(
        int $thresholdseconds,
        int $timecreated,
        ?int $timeachieved,
        int $now,
        int $pausedseconds = 0,
        bool $ispaused = false
    ): array {
        if ($thresholdseconds <= 0) {
            return [
                'enabled'     => false,
                'met'         => false,
                'pct'         => 0,
                'color_class' => 'sla-none',
                'remaining'   => '—',
            ];
        }

        $deadline = $timecreated + $thresholdseconds + $pausedseconds;

        // Already achieved — show how much of the window was used.
        if ($timeachieved !== null) {
            $metontime    = ($timeachieved <= $deadline);
            $usedseconds  = max(0, $timeachieved - $timecreated - $pausedseconds);
            $pctatmeeting = min(100, (int) round($usedseconds * 100 / $thresholdseconds));
            return [
                'enabled'     => true,
                'met'         => true,
                'pct'         => $pctatmeeting,
                'color_class' => $metontime ? 'sla-ok' : 'sla-breach',
                'remaining'   => $metontime
                    ? get_string('sla_met', 'local_moodesk')
                    : get_string('sla_met_late', 'local_moodesk'),
            ];
        }

        // Active ticket — compute elapsed percentage and choose color. Paused
        // time is not elapsed time: the bar holds still while the ticket waits.
        $elapsed = max(0, $now - $timecreated - $pausedseconds);
        $pct     = min(100, (int) round($elapsed * 100 / $thresholdseconds));

        // A frozen bar with a countdown that never moves reads as a bug. Say
        // why it is not moving instead, and let the deadline speak once the
        // requester answers.
        if ($ispaused) {
            return [
                'enabled'     => true,
                'met'         => false,
                'pct'         => $pct,
                'color_class' => 'sla-paused',
                'remaining'   => get_string('sla_paused', 'local_moodesk'),
            ];
        }

        if ($now >= $deadline) {
            $overdue = $now - $deadline;
            return [
                'enabled'     => true,
                'met'         => false,
                'pct'         => 100,
                'color_class' => 'sla-breach',
                'remaining'   => 'OVERDUE ' . $this->format_duration($overdue) . ' ago',
            ];
        }

        if ($pct < 50) {
            $colorclass = 'sla-ok';
        } else if ($pct < 80) {
            $colorclass = 'sla-warning';
        } else {
            $colorclass = 'sla-danger';
        }

        return [
            'enabled'     => true,
            'met'         => false,
            'pct'         => $pct,
            'color_class' => $colorclass,
            'remaining'   => $this->format_duration_precise($deadline - $now),
        ];
    }

    /**
     * Return the more severe of two SLA status values.
     *
     * Severity order: breach > warning > ok > none.
     *
     * @param string $a
     * @param string $b
     * @return string
     */
    private function worst_status(string $a, string $b): string {
        $order = ['none' => 0, 'ok' => 1, 'warning' => 2, 'breach' => 3];
        $scorea = $order[$a] ?? 0;
        $scoreb = $order[$b] ?? 0;
        return $scorea >= $scoreb ? $a : $b;
    }

    /**
     * Map a priority constant to the config key suffix.
     *
     * @param int $priority
     * @return string
     */
    private function priority_suffix(int $priority): string {
        $map = [
            ticket::PRIORITY_LOW    => 'low',
            ticket::PRIORITY_NORMAL => 'normal',
            ticket::PRIORITY_HIGH   => 'high',
            ticket::PRIORITY_URGENT => 'urgent',
        ];
        return $map[$priority] ?? 'normal';
    }
}
