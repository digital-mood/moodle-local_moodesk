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
 * Template context for the System Health page.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\incident;
use local_moodesk\service\incident_service;
use local_moodesk\service\incident_source_registry;

/**
 * Turns live incidents into what the health page shows.
 *
 * All of it is presentation: which of the sources are healthy, what the
 * overall state is, and how each incident reads. The rules about what counts as
 * live belong to {@see incident_service} and are not restated here.
 *
 * The page answers five questions and deliberately no more — is mooDesk healthy
 * now, what broke, when did it first and last happen, is it still happening,
 * and has anyone picked it up. No charts, no history, no trends: those turn a
 * health page into a monitoring product, which this stream is explicitly not.
 */
class health_page {
    /** Overall state when nothing is wrong. */
    const STATE_HEALTHY = 'healthy';

    /** Overall state when something is wrong but nothing is down. */
    const STATE_DEGRADED = 'degraded';

    /** Overall state when a whole source is down. */
    const STATE_DOWN = 'down';

    /** @var incident_service */
    private incident_service $service;

    /**
     * Build the renderable.
     *
     * @param incident_service|null $service Injectable for tests.
     */
    public function __construct(?incident_service $service = null) {
        $this->service = $service ?? incident_service::create();
    }

    /**
     * The full template context.
     *
     * @param string $pageurl Where the acknowledge/resolve forms post back to.
     * @return array
     */
    public function export(string $pageurl): array {
        global $OUTPUT;

        $incidents = $this->service->get_active();
        $counts    = $this->service->count_active_by_source();

        $worst = $this->worst_severity($incidents);
        $canmanage = has_capability('local/moodesk:managesettings', \context_system::instance());

        // Sections plugins add (hook health_panels); not part of the overall state.
        $pluginsections = (new \local_moodesk\hook\health_panels($canmanage))->dispatch_and_render($OUTPUT);

        return [
            'pageurl'      => $pageurl,
            'sesskey'      => sesskey(),
            'state'        => $this->overall_state($worst),
            'statevariant' => $this->overall_state_variant($worst),
            'statelabel'   => $this->overall_state_label($worst),
            'windowhours'  => (int) round(incident_service::active_window_seconds() / 3600),
            'sources'      => $this->export_sources($counts),
            'incidents'    => $this->export_incidents($incidents),
            'hasincidents' => !empty($incidents),
            'canmanage'    => $canmanage,
            'entitlement'  => $this->export_entitlement(),
            'pluginsections'    => $pluginsections,
            'haspluginsections' => !empty($pluginsections),
        ];
    }

    /**
     * The agent entitlement (D15, contract v2 §7): active agents against the
     * enforced capacity, derived live and never stored.
     *
     * Deliberately NOT an incident and NOT part of the overall state. A site
     * with more agents than its capacity is commercially over-limit and
     * technically fine; folding that into healthy/degraded/down would make the
     * banner lie in one direction or the other. So this is its own section,
     * with its own pill: `soft-warning` "Agent limit exceeded" when the count
     * is over the cap, and nothing alarming otherwise. Operational health and
     * licensing state stay two different questions on one page.
     *
     * The numbers come from entitlement_service::export_entitlement().
     *
     * @return array
     */
    private function export_entitlement(): array {
        $row = \local_moodesk\service\entitlement_service::export_entitlement();

        $row['statusvariant'] = $row['exceeded'] ? 'soft-warning' : 'soft-success';
        $row['exceedednote']  = get_string(
            $row['refuses'] ? 'license_agents_exceeded_note' : 'agents_exceeded_note_not_refused',
            'local_moodesk'
        );
        $row['statuslabel']   = $row['exceeded']
            ? get_string('health_entitlement_exceeded', 'local_moodesk')
            : get_string('health_entitlement_ok', 'local_moodesk');

        return $row;
    }

    /**
     * One card per registered source, always every one of them.
     *
     * Sources with nothing wrong are shown rather than hidden: "scheduled tasks
     * are fine" is an answer, and a page that only lists problems cannot
     * distinguish a healthy source from one that was never checked. A source no
     * installed plugin declares has no card ({@see incident_source_registry}).
     *
     * @param array $counts Active incident count per source (source string => int).
     * @return array
     */
    private function export_sources(array $counts): array {
        $sources = [];

        foreach (incident_source_registry::sources() as $source) {
            $count = $counts[$source] ?? 0;
            $sources[] = [
                'key'     => $source,
                'label'   => incident_source_registry::label($source),
                'count'   => $count,
                'healthy' => $count === 0,
                'status'  => $count === 0
                    ? get_string('health_source_ok', 'local_moodesk')
                    : get_string('health_source_issues', 'local_moodesk', $count),
            ];
        }

        return $sources;
    }

    /**
     * One card per live incident, most severe first.
     *
     * Cards rather than table rows because an incident is not a homogeneous row
     * — it carries a severity, a code, a free-text message of unpredictable
     * length, four metadata values and two actions. Squeezing that into seven
     * columns reads badly at every width and collapses at small ones; and there
     * are few of them by construction, since the table holds one row per class
     * of failure rather than per occurrence.
     *
     * @param \stdClass[] $incidents
     * @return array
     */
    private function export_incidents(array $incidents): array {
        $rows = [];

        foreach ($this->sort_by_severity($incidents) as $incident) {
            $acknowledged = $incident->status === incident::STATUS_ACKNOWLEDGED;

            $rows[] = [
                'id'            => (int) $incident->id,
                'code'          => $incident->code,
                'message'       => $incident->message,
                'severity'      => $incident->severity,
                'severitylabel' => get_string('incident_severity_' . $incident->severity, 'local_moodesk'),
                'sourcelabel'   => incident_source_registry::label($incident->source),
                'occurrences'   => (int) $incident->occurrences,
                'firstseen'     => userdate((int) $incident->timefirstseen),
                'lastseen'      => userdate((int) $incident->timelastseen),
                'lastseenago'   => format_time(time() - (int) $incident->timelastseen),
                'acknowledged'  => $acknowledged,
                'ackedlabel'    => $acknowledged
                    ? get_string('health_acknowledged_note', 'local_moodesk')
                    : '',
            ];
        }

        return $rows;
    }

    /**
     * Most severe first, then most recent — the order an operator triages in.
     *
     * @param \stdClass[] $incidents
     * @return \stdClass[]
     */
    private function sort_by_severity(array $incidents): array {
        $sorted = array_values($incidents);

        usort($sorted, static function (\stdClass $a, \stdClass $b): int {
            $rank = incident::severity_rank($b->severity) <=> incident::severity_rank($a->severity);
            if ($rank !== 0) {
                return $rank;
            }
            return (int) $b->timelastseen <=> (int) $a->timelastseen;
        });

        return $sorted;
    }

    /**
     * The worst severity among live incidents, or null when there are none.
     *
     * Acknowledged incidents still count. Someone looking at a problem does not
     * make the site healthy, and a page that said otherwise would be lying to
     * everyone except the person who clicked.
     *
     * @param \stdClass[] $incidents
     * @return string|null
     */
    private function worst_severity(array $incidents): ?string {
        $worst = null;

        foreach ($incidents as $incident) {
            $isworse = $worst === null
                || incident::severity_rank($incident->severity) > incident::severity_rank($worst);
            if ($isworse) {
                $worst = $incident->severity;
            }
        }

        return $worst;
    }

    /**
     * Map the worst live severity to the banner state.
     *
     * @param string|null $worst
     * @return string
     */
    private function overall_state(?string $worst): string {
        if ($worst === null) {
            return self::STATE_HEALTHY;
        }
        return $worst === incident::SEVERITY_CRITICAL ? self::STATE_DOWN : self::STATE_DEGRADED;
    }

    /**
     * The Pill colour variant that carries the overall state.
     *
     * The mapping lives here rather than in the template because the pill's
     * variants are a property of the design system, not of the health domain:
     * the page knows it is degraded, the component knows what degraded looks
     * like. See components/_pill.scss.
     *
     * @param string|null $worst
     * @return string
     */
    private function overall_state_variant(?string $worst): string {
        return match ($this->overall_state($worst)) {
            self::STATE_DOWN     => 'soft-danger',
            self::STATE_DEGRADED => 'soft-warning',
            default              => 'soft-success',
        };
    }

    /**
     * The banner text for the overall state.
     *
     * @param string|null $worst
     * @return string
     */
    private function overall_state_label(?string $worst): string {
        return get_string('health_state_' . $this->overall_state($worst), 'local_moodesk');
    }
}
