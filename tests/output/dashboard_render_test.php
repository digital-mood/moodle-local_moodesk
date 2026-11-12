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
 * Renders the dashboard template against real service output.
 *
 * The seam between dashboard_service and dashboard.mustache is where this
 * screen breaks quietly: a renamed context key leaves a blank panel, and a
 * missing language string renders as [[key]] — neither raises an error, and
 * both look like "no data" to a reader. These tests render the real template
 * with a real context and assert on the markup that comes out.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\dashboard_filter;
use local_moodesk\domain\ticket;
use local_moodesk\service\dashboard_service;
use local_moodesk\service\edition_service;

/**
 * Integration tests for the dashboard template.
 *
 * @coversNothing
 */
final class dashboard_render_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->user = $this->getDataGenerator()->create_user();
        edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
    }

    protected function tearDown(): void {
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * With data present, every panel reaches the markup.
     */
    public function test_populated_dashboard_renders_every_panel(): void {
        $agent = $this->getDataGenerator()->create_user();
        $dept  = $this->make_department('Support');

        $this->make_ticket(['departmentid' => $dept, 'assigneeid' => $agent->id]);
        $this->make_ticket(['departmentid' => $dept, 'status' => ticket::STATUS_ON_HOLD,
            'assigneeid' => $agent->id, 'timefirstresponse' => time() - HOURSECS]);
        $this->make_ticket([
            'status'       => ticket::STATUS_RESOLVED,
            'timeresolved' => time() - HOURSECS,
        ]);

        $html = $this->render();

        $this->assertStringContainsString('mhd-kpi-card', $html);
        $this->assertStringContainsString('data-mhd-chart="status"', $html);
        $this->assertStringContainsString('mhd-priority-bar-', $html);
        $this->assertStringContainsString('mhd-dept-row', $html);
        $this->assertStringContainsString('mhd-aging-bar-', $html);
        $this->assertStringContainsString('mhd-activity-row', $html);
        $this->assertStringContainsString('mhd-trend-col', $html);
    }

    /**
     * Chart payloads travel to the browser through an HTML attribute, which
     * Mustache escapes. Department and category names reach that payload
     * straight from the database, so this is both a correctness check and the
     * reason the template can afford to escape rather than emit raw JSON.
     */
    public function test_chart_payloads_survive_attribute_escaping(): void {
        // A name carrying the characters that would break either the JSON or
        // the attribute if the escaping were wrong.
        $this->make_ticket(['departmentid' => $this->make_department('R&D "Tools" <beta>')]);

        $html = $this->render();

        $found = preg_match_all(
            '/data-mhd-chart="([a-z]+)" data-chart-data="([^"]*)"/',
            $html,
            $matches,
            PREG_SET_ORDER
        );
        $this->assertGreaterThan(0, $found, 'no chart payload reached the markup');

        foreach ($matches as [, $name, $raw]) {
            $decoded = json_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5), true);
            $this->assertIsArray($decoded, "the {$name} payload is not valid JSON once decoded");
        }
    }

    /**
     * Only the panels whose shape CSS cannot draw carry a chart marker. The
     * rest are CSS by design, and the attribute has to mean what it says.
     *
     * The SLA gauge is absent here for a different reason: this fixture has no
     * resolved tickets, so the panel has no data and renders no host at all.
     */
    public function test_only_the_intended_panels_mount_a_chart(): void {
        $this->make_ticket();

        preg_match_all('/data-mhd-chart="([a-z]+)"/', $this->render(), $matches);

        $this->assertSame(['aging', 'priority', 'status', 'trend'], $this->sorted_unique($matches[1]));
    }

    /**
     * A filter that matches nothing still renders: the KPIs report a real zero,
     * and the panels give way to one empty state instead of nine panels each
     * repeating that they have nothing.
     */
    public function test_empty_result_renders_one_empty_state(): void {
        $empty = $this->make_department('Nobody uses this');
        $this->make_ticket();

        $html = $this->render($empty);

        $this->assertStringContainsString('mhd-empty-state', $html);
        // The KPI row survives — "zero active tickets" is an answer worth showing.
        $this->assertStringContainsString('mhd-kpi-card', $html);
        $this->assertStringNotContainsString('data-mhd-chart="status"', $html);
        // The panels are gone rather than present-and-apologising.
        $this->assertStringNotContainsString('mhd-panel-empty', $html);
        $this->assertStringNotContainsString('mhd-workload-table', $html);
    }

    /**
     * A criterion whose chip could not be labelled — an archived department, a
     * departed agent — still narrowed the results, so the reader still needs
     * the way out. The empty state follows the filter, not the chip.
     */
    public function test_empty_state_follows_the_filter_even_with_no_chip(): void {
        $empty = $this->make_department('Nobody uses this');
        $this->make_ticket();

        $html = $this->render($empty, false, true);

        $this->assertStringContainsString(
            get_string('dashboard_empty_filtered_title', 'local_moodesk'),
            $html,
            'a filtered empty result was reported as a quiet period'
        );
        $this->assertStringContainsString(get_string('filter_clear_all', 'local_moodesk'), $html);
    }

    /**
     * The chips are the ticket list's component, down to the class names its
     * stylesheet targets — now the Pill, in its input variant. An applied
     * filter is one of the few chips in mooDesk that genuinely does something
     * when you activate it, so it is a link and it carries the state layer.
     *
     * And a link whose accessible name is a department name says nothing about
     * what following it does.
     */
    public function test_filter_chips_are_the_shared_component(): void {
        $this->make_ticket();

        $html = $this->render(null, true);

        $this->assertStringContainsString('class="mhd-pill__label"', $html);
        $this->assertStringContainsString('class="mhd-pill__icon"', $html);
        $this->assertMatchesRegularExpression(
            '/<a href="[^"]*"\s+class="mhd-pill mhd-pill--input mhd-pill--interactive '
                . 'mhd-pill--trailing-icon"\s+aria-label="[^"]+"/',
            $html,
            'a filter chip carries no accessible name'
        );
    }

    /**
     * An empty result reached through filters is recoverable, and says how. An
     * empty period is not the same claim and does not offer to clear anything.
     */
    public function test_empty_state_offers_a_way_out_only_when_filtered(): void {
        $empty = $this->make_department('Nobody uses this');
        $this->make_ticket();

        $filtered = $this->render($empty, true);
        $this->assertStringContainsString(
            get_string('dashboard_empty_filtered_title', 'local_moodesk'),
            $filtered
        );
        $this->assertStringContainsString(
            get_string('filter_clear_all', 'local_moodesk'),
            $filtered
        );

        $unfiltered = $this->render($empty);
        $this->assertStringContainsString(
            get_string('dashboard_empty_period_title', 'local_moodesk'),
            $unfiltered
        );
    }

    /**
     * The trend bars carry their figures in their height, which is no use to a
     * screen reader — and once Chart.js hides the markup behind a canvas, the
     * text beside them is the only reading left. It has to be there.
     */
    public function test_trend_columns_state_their_figures_in_words(): void {
        $this->make_ticket();

        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<div class="mhd-trend-col"[^>]*>\s*<span class="mhd-sr-only">[^<]+<\/span>/',
            $html,
            'the trend columns render no text alternative'
        );
    }

    /**
     * Every panel is a region a screen reader can jump to, which needs a name:
     * a bare <section> is announced as nothing at all.
     */
    public function test_every_panel_is_a_named_region(): void {
        $this->make_ticket();

        $html = $this->render();

        preg_match_all('/aria-labelledby="(mhd-title-[a-z]+)"/', $html, $labelled);
        $this->assertNotEmpty($labelled[1], 'no panel carries a region name');

        foreach (array_unique($labelled[1]) as $id) {
            $this->assertStringContainsString(
                'id="' . $id . '"',
                $html,
                "panel labelled by {$id} points at a heading that is not there"
            );
        }
    }

    /**
     * Sort and deduplicate, for asserting on a set rather than an order.
     *
     * @param array $values
     * @return array
     */
    private function sorted_unique(array $values): array {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }

    /**
     * No context key may go unresolved, in either state.
     */
    public function test_no_unresolved_template_tags(): void {
        $this->make_ticket();

        foreach ([$this->render(), $this->render($this->make_department('Empty'))] as $html) {
            $this->assertDoesNotMatchRegularExpression(
                '/\{\{[^}]+\}\}/',
                $html,
                'the template left a tag unresolved'
            );
        }
    }

    /**
     * A template comment ends at the first `}}`, so a comment that quotes a tag
     * closes early and spills the rest of itself onto the page as prose.
     *
     * Nothing else catches this: the leaked text carries no unresolved tags by
     * the time it is rendered, so it passes every other check here while
     * sitting in plain sight on the panel. The rule is simply that a comment
     * body may not contain `{{`.
     */
    public function test_no_template_comment_closes_early(): void {
        $source = file_get_contents(__DIR__ . '/../../templates/dashboard.mustache');

        preg_match_all('/\{\{!(.*?)\}\}/s', $source, $comments);

        foreach ($comments[1] as $body) {
            $this->assertStringNotContainsString(
                '{{',
                $body,
                'a template comment quotes a tag and therefore ends early: ' .
                    substr(trim($body), 0, 60)
            );
        }
    }

    /**
     * No language string may be missing, in either state. Moodle renders those
     * as [[key]], which reads as a bug to a customer and as nothing to a test
     * that only checks for an exception.
     */
    public function test_no_missing_language_strings(): void {
        $this->make_ticket();

        foreach ([$this->render(), $this->render($this->make_department('Empty'))] as $html) {
            $this->assertDoesNotMatchRegularExpression(
                '/\[\[[a-z0-9_]+\]\]/',
                $html,
                'the template asked for a language string that does not exist'
            );
        }
    }

    /**
     * A delta reaches its reader three ways — arrow, colour and words — and the
     * first two are unavailable to a screen reader. The words are not optional.
     */
    public function test_kpi_deltas_state_their_direction_in_words(): void {
        // The "resolved this week" KPI compares a partial week against the same
        // stretch of the previous one: [Monday 00:00, now] against that window
        // shifted back exactly WEEKSECS. Both are anchored to the calendar, so a
        // fixture placed at `time() - N * DAYSECS` lands inside them only on
        // some days — at 8 days back, never on a Monday, which is how this test
        // used to fail one day in seven.
        //
        // Anchor to the window instead. The midpoint of the current week sits
        // inside it whatever the hour, including the degenerate Monday-midnight
        // case where the window is seconds wide, and subtracting one week maps
        // it onto the equivalent point of the comparison window.
        $now         = time();
        $weekstart   = $this->start_of_week($now);
        $inthisweek  = $weekstart + intdiv($now - $weekstart, 2);
        $inlastweek  = $inthisweek - WEEKSECS;

        // Two resolved this week against one last week: a rise of 100%, so the
        // KPI carries a delta with a direction rather than a flat reading,
        // which states nothing to spell out. A previous period of zero would
        // yield no delta at all — get_delta_pct() returns null on a zero base.
        foreach ([$inthisweek, $inthisweek, $inlastweek] as $resolvedat) {
            $this->make_ticket([
                'status'       => ticket::STATUS_RESOLVED,
                'timeresolved' => $resolvedat,
            ]);
        }

        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<span class="mhd-kpi-delta[^"]*">.*?<span class="mhd-sr-only">[^<]+<\/span>/s',
            $html,
            'a KPI delta states its direction only in colour and an aria-hidden arrow'
        );
    }

    /**
     * Starter renders a coherent page without the Pro panels — no empty shells
     * where the analytics would be.
     */
    public function test_starter_renders_without_the_pro_panels(): void {
        edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);
        $this->make_ticket();

        $html = $this->render();

        $this->assertStringContainsString('data-mhd-chart="status"', $html);
        $this->assertStringNotContainsString('data-mhd-chart="sla"', $html);
        $this->assertStringNotContainsString('mhd-workload-table', $html);
        $this->assertStringNotContainsString('data-mhd-chart="categories"', $html);
        $this->assertDoesNotMatchRegularExpression('/\[\[[a-z0-9_]+\]\]/', $html);
    }

    /*
     * =========================================================================
     * Fixtures
     * =========================================================================
     */

    /**
     * Render the dashboard template the way dashboard.php does.
     *
     * @param int|null  $departmentid Optional department criterion.
     * @param bool      $haschips     Whether the page shows dismissable filter chips.
     * @param bool|null $hasfilters   Whether a criterion is applied at all; defaults
     *                                to whatever the chips say, which is the normal
     *                                case. The two differ when a chip's label
     *                                cannot be resolved and it is dropped.
     * @return string Rendered markup.
     */
    private function render(
        ?int $departmentid = null,
        bool $haschips = false,
        ?bool $hasfilters = null
    ): string {
        global $OUTPUT, $PAGE;

        $PAGE->set_url(new \moodle_url('/local/moodesk/dashboard.php'));

        // Two renders inside one test would otherwise share a cache entry: the
        // key carries the configuration and the minute, neither of which moves
        // when the fixture gains a ticket.
        \cache::make('local_moodesk', 'dashboard_metrics')->purge();

        $service = new dashboard_service();
        $filter  = $service->build_filter(
            dashboard_service::VIEW_MANAGER,
            (int) $this->user->id,
            dashboard_filter::RANGE_30,
            $departmentid
        );

        $context = array_merge(
            $service->get_dashboard($filter, dashboard_service::VIEW_MANAGER),
            [
                'is_manager'  => true,
                'can_filter'  => true,
                'cansubmit'   => true,
                'listurl'     => '/local/moodesk/index.php',
                'submiturl'   => '/local/moodesk/submit.php',
                'formaction'  => '/local/moodesk/dashboard.php',
                'clearallurl' => '/local/moodesk/dashboard.php',
                'rangeoptions' => [
                    ['value' => 30, 'label' => 'Last 30 days', 'isselected' => true],
                ],
                'departmentoptions'    => [],
                'hasdepartmentoptions' => false,
                'teamoptions'          => [],
                'hasteamoptions'       => false,
                'agentoptions'         => [],
                'hasagentoptions'      => false,
                'chips'                => $haschips
                    ? [['label' => 'Nobody uses this', 'dismissurl' => '/local/moodesk/dashboard.php']]
                    : [],
                'haschips'             => $haschips,
                'hasfilters'           => $hasfilters ?? $haschips,
            ]
        );

        return $OUTPUT->render_from_template('local_moodesk/dashboard', $context);
    }

    /**
     * Start of the ISO week (Monday 00:00) containing the given moment.
     *
     * Mirrors dashboard_service::start_of_week(), which is private. A fixture
     * that has to land inside a calendar-anchored window has to compute that
     * window the same way the service does; the alternative is guessing with a
     * day offset, which is precisely the bug this duplication exists to stop.
     *
     * @param int $now
     * @return int
     */
    private function start_of_week(int $now): int {
        $date = usergetdate($now);
        $dow  = (int) $date['wday']; // 0 = Sunday.
        $dayssincemonday = ($dow === 0) ? 6 : ($dow - 1);

        return mktime(0, 0, 0, $date['mon'], $date['mday'] - $dayssincemonday, $date['year']);
    }

    /**
     * Insert a department and return its id.
     *
     * @param string $name
     * @return int
     */
    private function make_department(string $name): int {
        global $DB;

        return (int) $DB->insert_record('local_moodesk_department', (object) [
            'name'         => $name,
            'description'  => '',
            'isactive'     => 1,
            'timecreated'  => time(),
            'timemodified' => time(),
            'usermodified' => $this->user->id,
        ]);
    }

    /**
     * Insert a ticket, overriding any of the defaults.
     *
     * @param array $overrides
     * @return int
     */
    private function make_ticket(array $overrides = []): int {
        global $DB;

        $now = time();
        $base = [
            'userid'       => $this->user->id,
            'createdby'    => $this->user->id,
            'subject'      => 'Render fixture',
            'status'       => ticket::STATUS_OPEN,
            'priority'     => ticket::PRIORITY_NORMAL,
            'source'       => 0,
            'sla_breached' => 0,
            'timecreated'  => $now - (2 * HOURSECS),
            'timemodified' => $now - HOURSECS,
            'usermodified' => $this->user->id,
        ];

        return (int) $DB->insert_record('local_moodesk_ticket', (object) array_merge($base, $overrides));
    }
}
