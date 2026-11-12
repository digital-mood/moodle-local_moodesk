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
 * Tests for the System Health page context (OBS-04).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\incident;
use local_moodesk\hook\health_sources;
use local_moodesk\service\edition_service;
use local_moodesk\service\incident_service;
use local_moodesk\service\incident_source_registry;

/**
 * What the health page says, given what is wrong.
 *
 * The five questions the page exists to answer are what these cases pin: is
 * mooDesk healthy now, what broke, when did it first and last happen, is it
 * still happening, and has anyone picked it up.
 *
 * @covers \local_moodesk\output\health_page
 */
final class health_page_test extends \advanced_testcase {
    /** Where the action forms post to; irrelevant to the assertions. */
    private const PAGE_URL = '/local/moodesk/health.php';

    /**
     * Every case reads and writes incidents, with the base's sources only:
     * whether local_moodeskpro is installed must not change what these pin.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        incident_service::reset_deferred_for_testing();
        incident_source_registry::reset_for_testing();
        $this->redirectHook(health_sources::class, static function (health_sources $hook): void {
        });
        $this->setAdminUser();
    }

    /**
     * Static state outlives the per-test database reset.
     */
    protected function tearDown(): void {
        incident_service::reset_deferred_for_testing();
        incident_source_registry::reset_for_testing();
        edition_service::set_edition_for_testing(null);
        edition_service::set_dev_override_for_testing(null);
        parent::tearDown();
    }

    /**
     * Record an incident and settle it to storage.
     *
     * @param string $source
     * @param string $code
     * @param string $severity
     * @return int Incident id.
     */
    private function record(string $source, string $code, string $severity): int {
        $id = incident_service::create()->record($source, $code, $severity, 'something broke');
        incident_service::flush_deferred();

        return $id;
    }

    /**
     * The page context as the template would receive it.
     *
     * @return array
     */
    private function context(): array {
        return (new health_page())->export(self::PAGE_URL);
    }

    /*
     * -------------------------------------------------------------------------
     * Question 1: is mooDesk healthy now?
     * -------------------------------------------------------------------------
     */

    /**
     * With nothing wrong, the page says so — and says it through a real empty
     * state, because a blank page reads like something failed to load.
     */
    public function test_a_healthy_site_reports_healthy(): void {
        $ctx = $this->context();

        $this->assertSame(health_page::STATE_HEALTHY, $ctx['state']);
        $this->assertFalse($ctx['hasincidents']);
        $this->assertSame([], $ctx['incidents']);
    }

    /**
     * An error degrades the site; a critical takes it down. The distinction is
     * the difference between "something is failing" and "a whole source is
     * gone", which is what an operator triages on.
     */
    public function test_the_worst_live_severity_sets_the_overall_state(): void {
        $this->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR);
        $this->assertSame(health_page::STATE_DEGRADED, $this->context()['state']);

        $this->record(incident::SOURCE_KNOWLEDGE_BASE, 'x.y.z', incident::SEVERITY_CRITICAL);
        $this->assertSame(health_page::STATE_DOWN, $this->context()['state']);
    }

    /**
     * A warning alone is still a degraded site, not a healthy one. It does not
     * alert by default, but the page is where an operator goes to look.
     */
    public function test_a_warning_alone_is_not_healthy(): void {
        $this->record(incident::SOURCE_KNOWLEDGE_BASE, 'p.q.r', incident::SEVERITY_WARNING);

        $this->assertSame(health_page::STATE_DEGRADED, $this->context()['state']);
    }

    /**
     * Acknowledging does not make the site healthy. Someone looking at a
     * problem is not the same as the problem being gone, and a page that said
     * otherwise would be lying to everyone except the person who clicked.
     */
    public function test_acknowledging_does_not_make_the_site_healthy(): void {
        $user = $this->getDataGenerator()->create_user();
        $id   = $this->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR);

        incident_service::create()->acknowledge($id, (int) $user->id);

        $ctx = $this->context();
        $this->assertSame(health_page::STATE_DEGRADED, $ctx['state']);
        $this->assertTrue($ctx['hasincidents']);
    }

    /**
     * Resolving does. That is what resolving means.
     */
    public function test_resolving_returns_the_site_to_healthy(): void {
        $user = $this->getDataGenerator()->create_user();
        $id   = $this->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR);

        incident_service::create()->resolve($id, (int) $user->id);

        $this->assertSame(health_page::STATE_HEALTHY, $this->context()['state']);
    }

    /**
     * The page hands the template a pill variant, not a colour. Every state has
     * to map to one: a state the design system has no variant for would render
     * as an unstyled pill, which reads as "no state at all" — the one thing a
     * health page must never say by accident.
     */
    public function test_every_overall_state_carries_a_pill_variant(): void {
        $this->assertSame('soft-success', $this->context()['statevariant']);

        $this->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR);
        $ctx = $this->context();
        $this->assertSame(health_page::STATE_DEGRADED, $ctx['state']);
        $this->assertSame('soft-warning', $ctx['statevariant']);

        $this->record(incident::SOURCE_KNOWLEDGE_BASE, 'x.y.z', incident::SEVERITY_CRITICAL);
        $ctx = $this->context();
        $this->assertSame(health_page::STATE_DOWN, $ctx['state']);
        $this->assertSame('soft-danger', $ctx['statevariant']);
    }

    /*
     * -------------------------------------------------------------------------
     * Question 2: what broke?
     * -------------------------------------------------------------------------
     */

    /**
     * Every registered source always appears, healthy ones included. A page
     * that only lists problems cannot tell a working source from one nobody
     * checked. With the base alone, that is the base's four.
     */
    public function test_every_source_is_listed_even_when_healthy(): void {
        $this->record(incident::SOURCE_UI, 'w.1', incident::SEVERITY_ERROR);

        $sources = $this->context()['sources'];
        $this->assertCount(4, $sources);
        $this->assertSame(incident::base_sources(), array_column($sources, 'key'));

        $bykey = array_column($sources, null, 'key');
        $this->assertSame(array_keys($bykey), incident::sources());

        $this->assertFalse($bykey[incident::SOURCE_UI]['healthy']);
        $this->assertSame(1, $bykey[incident::SOURCE_UI]['count']);
        $this->assertTrue($bykey[incident::SOURCE_OBSERVER]['healthy']);
        $this->assertSame(0, $bykey[incident::SOURCE_OBSERVER]['count']);
    }

    /**
     * Incidents are ordered most severe first, then most recent — the order an
     * operator triages in, not the order they were written.
     */
    public function test_incidents_are_ordered_by_severity_then_recency(): void {
        global $DB;

        $warning  = $this->record(incident::SOURCE_OBSERVER, 'a.warning', incident::SEVERITY_WARNING);
        $critical = $this->record(incident::SOURCE_UI, 'b.critical', incident::SEVERITY_CRITICAL);
        $error    = $this->record(incident::SOURCE_SCHEDULED_TASK, 'c.error', incident::SEVERITY_ERROR);

        // Make the warning the most recent, so recency alone would float it up.
        $DB->set_field('local_moodesk_incident', 'timelastseen', time() + 60, ['id' => $warning]);

        $ids = array_column($this->context()['incidents'], 'id');
        $this->assertSame([$critical, $error, $warning], $ids);
    }

    /*
     * -------------------------------------------------------------------------
     * Questions 3-5: when, still happening, picked up?
     * -------------------------------------------------------------------------
     */

    /**
     * Each incident carries everything the five questions need, and the code
     * that identifies it for a support conversation.
     */
    public function test_an_incident_carries_the_five_answers(): void {
        $this->record(incident::SOURCE_UI, 'webhook.delivery.abandoned', incident::SEVERITY_ERROR);

        $incident = $this->context()['incidents'][0];

        $this->assertSame('webhook.delivery.abandoned', $incident['code']);
        $this->assertSame('something broke', $incident['message']);
        $this->assertSame(incident::SEVERITY_ERROR, $incident['severity']);
        $this->assertSame(1, $incident['occurrences']);
        $this->assertNotEmpty($incident['firstseen']);
        $this->assertNotEmpty($incident['lastseen']);
        $this->assertNotEmpty($incident['lastseenago']);
        $this->assertFalse($incident['acknowledged']);
    }

    /**
     * A repeated failure shows its count, which is the difference between "this
     * happened" and "this is still happening".
     */
    public function test_a_repeated_failure_shows_its_count(): void {
        $svc = incident_service::create();
        for ($i = 0; $i < 4; $i++) {
            $svc->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR, 'again');
        }
        incident_service::flush_deferred();

        $this->assertSame(4, $this->context()['incidents'][0]['occurrences']);
    }

    /**
     * An acknowledged incident stays listed and says who has it — silencing
     * the alert is not the same as hiding the problem.
     */
    public function test_an_acknowledged_incident_stays_listed_and_flagged(): void {
        $user = $this->getDataGenerator()->create_user();
        $id   = $this->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR);

        incident_service::create()->acknowledge($id, (int) $user->id);

        $incident = $this->context()['incidents'][0];
        $this->assertTrue($incident['acknowledged']);
        $this->assertNotEmpty($incident['ackedlabel']);
    }

    /**
     * An incident last seen outside the active window drops off the page. It
     * stays on record; it is no longer a current problem.
     */
    public function test_an_incident_outside_the_window_is_not_shown(): void {
        global $DB;

        $id = $this->record(incident::SOURCE_OBSERVER, 'a.b.c', incident::SEVERITY_ERROR);
        $this->assertTrue($this->context()['hasincidents']);

        $DB->set_field('local_moodesk_incident', 'timelastseen', time() - (25 * 3600), ['id' => $id]);

        $ctx = $this->context();
        $this->assertFalse($ctx['hasincidents']);
        $this->assertSame(health_page::STATE_HEALTHY, $ctx['state']);
    }

    /**
     * The window is reported in the context, so the page can explain why
     * yesterday's failure is gone rather than leaving an operator to guess.
     */
    public function test_the_window_is_exposed_for_the_explanatory_line(): void {
        $this->assertSame(24, $this->context()['windowhours']);

        set_config('incident_active_window_hours', 6, 'local_moodesk');
        $this->assertSame(6, $this->context()['windowhours']);
    }

    /*
     * -------------------------------------------------------------------------
     * Access.
     * -------------------------------------------------------------------------
     */

    /**
     * The action controls are offered only to someone who may use them. The
     * page itself re-checks on POST — this just keeps buttons off screens
     * where they would only produce an error.
     */
    public function test_action_controls_follow_the_manage_capability(): void {
        $this->assertTrue($this->context()['canmanage']);

        $viewer = $this->getDataGenerator()->create_user();
        $this->setUser($viewer);

        $this->assertFalse($this->context()['canmanage']);
    }

    /**
     * Starter loads the page. Observability is not edition-gated: the base's
     * sources list, healthy.
     */
    public function test_starter_sees_the_page_with_every_source_healthy(): void {
        edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);

        $ctx = $this->context();

        $this->assertSame(health_page::STATE_HEALTHY, $ctx['state']);
        $this->assertCount(4, $ctx['sources']);
        foreach ($ctx['sources'] as $source) {
            $this->assertTrue($source['healthy']);
        }
    }

    /**
     * Render the real template with the real context.
     *
     * The placeholder defect these cases exist for lived between the language
     * pack and the template, which is exactly the gap an export()-only
     * assertion cannot see: the context carried the right number the whole
     * time, and the page still printed the placeholder.
     *
     * Takes the context rather than building its own. export() stamps
     * time() — `lastseenago` is a relative interval — so a test that exported
     * once to read a value and let this method export again to render it was
     * comparing two different instants. It agreed whenever both landed in the
     * same second and disagreed when they did not, which is a coin flip
     * weighted by how fast the runner is.
     *
     * @param array|null $context Context to render, or null to export a fresh one.
     * @return string The page HTML.
     */
    private function render_page(?array $context = null): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template(
            'local_moodesk/admin_health',
            $context ?? (new health_page())->export(self::PAGE_URL)
        );
    }

    /**
     * The window note states the configured window as a number.
     *
     * Shipped reading "Showing failures recorded in the last {$a} hours"
     * because the language pack escaped the placeholder as {\$a}, which
     * Moodle's substitution never matches. Nothing in CI could see it: the
     * string was well-formed PHP, both packs agreed, and the parity check
     * compares keys rather than contents.
     */
    public function test_the_window_note_states_the_configured_window(): void {
        set_config('incident_active_window_hours', 6, 'local_moodesk');

        $html = $this->render_page();

        $this->assertStringContainsString('last 6 hours', $html);
        $this->assertStringNotContainsString('{$a}', $html);
    }

    /**
     * The empty state states it too, and the healthy site is where a reader
     * meets this sentence first.
     */
    public function test_the_empty_state_states_the_configured_window(): void {
        set_config('incident_active_window_hours', 6, 'local_moodesk');

        $html = $this->render_page();

        $this->assertStringContainsString('Nothing to report', $html);
        $this->assertStringContainsString('recorded in the last 6 hours', $html);
        $this->assertStringNotContainsString('{$a}', $html);
    }

    /**
     * The default window reaches the page as 24, not as a placeholder and not
     * as the raw seconds the service works in.
     */
    public function test_the_default_window_renders_as_twenty_four(): void {
        $html = $this->render_page();

        $this->assertStringContainsString('last 24 hours', $html);
        $this->assertStringNotContainsString('{$a}', $html);
        $this->assertStringNotContainsString('86400', $html);
    }

    /**
     * No placeholder survives anywhere on a populated page.
     *
     * Two of the four broken strings — the source counter and the relative
     * time — only appear once something is wrong, so a page rendered on a
     * healthy site would have shown neither. This one has incidents.
     */
    public function test_no_placeholder_reaches_a_populated_page(): void {
        $this->record(incident::SOURCE_UI, 'webhook.deliver.failed', incident::SEVERITY_ERROR);
        $this->record(incident::SOURCE_OBSERVER, 'observer.notify.failed', incident::SEVERITY_WARNING);

        // One export, rendered and asserted against. Two would be two instants.
        $context = (new health_page())->export(self::PAGE_URL);
        $html    = $this->render_page($context);

        $this->assertStringNotContainsString('{$a}', $html);
        $this->assertStringNotContainsString('{\$a}', $html);

        // The source counter substituted its number rather than printing the
        // placeholder: one incident recorded against each of two sources.
        $this->assertStringContainsString('1 live incident(s)', $html);

        // And the relative time carries the value export() computed, so this
        // holds whatever format_time() decides to call the interval.
        $this->assertStringContainsString(
            $context['incidents'][0]['lastseenago'] . ' ago',
            $html
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Entitlement (D15)
     * -------------------------------------------------------------------------
     */

    /**
     * Make $n users agents through Moodle's own role assignment.
     *
     * @param int $n
     */
    private function assign_agents(int $n): void {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        for ($i = 0; $i < $n; $i++) {
            $user = $this->getDataGenerator()->create_user();
            role_assign($roleid, $user->id, \context_system::instance()->id);
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The section states the live count against the enforced capacity, and
     * within it says so quietly (S02). No seam decides on any CI leg.
     */
    public function test_the_entitlement_section_states_the_count_against_the_cap(): void {
        edition_service::set_dev_override_for_testing(false);
        $this->assign_agents(1);

        $ent = $this->context()['entitlement'];

        $this->assertSame('1 / 2 agents', $ent['usage']);
        $this->assertSame('Starter', $ent['editionlabel']);
        $this->assertFalse($ent['exceeded']);
        $this->assertSame('soft-success', $ent['statusvariant']);
        $this->assertFalse($ent['hassourcenote']);
    }

    /**
     * Over the cap is a soft warning in its own section — and NOT a change of
     * the overall state. A site with too many agents is commercially over
     * its capacity and technically healthy; the banner must keep saying so (§7).
     */
    public function test_an_over_limit_site_is_flagged_without_changing_the_overall_state(): void {
        edition_service::set_dev_override_for_testing(false);
        $this->assign_agents(3);

        $ctx = $this->context();

        $this->assertSame(health_page::STATE_HEALTHY, $ctx['state']);
        $this->assertFalse($ctx['hasincidents']);

        $this->assertSame('3 / 2 agents', $ctx['entitlement']['usage']);
        $this->assertTrue($ctx['entitlement']['exceeded']);
        $this->assertSame('soft-warning', $ctx['entitlement']['statusvariant']);
        $this->assertSame(get_string('health_entitlement_exceeded', 'local_moodesk'), $ctx['entitlement']['statuslabel']);

        $html = $this->render_page($ctx);
        $this->assertStringContainsString('Agent limit exceeded', $html);
        $this->assertStringContainsString('3 / 2 agents', $html);
        $this->assertStringContainsString('No agent has been removed.', $html);
        $this->assertStringNotContainsString('licence to add more agents', $html);
    }

    /**
     * A development override above the licence is reported, not obeyed (S22).
     */
    public function test_an_override_that_cannot_raise_the_plan_is_reported(): void {
        edition_service::set_dev_override_for_testing('enterprise');

        $ctx = $this->context();

        $this->assertSame('Starter', $ctx['entitlement']['editionlabel']);
        $this->assertSame('0 / 2 agents', $ctx['entitlement']['usage']);
        $this->assertTrue($ctx['entitlement']['hassourcenote']);
        $this->assertStringContainsString(
            'The development override cannot raise the licensed plan (Enterprise requested).',
            $this->render_page($ctx)
        );
    }

    /*
     * -------------------------------------------------------------------------
     * Sources other plugins declare (hook health_sources)
     * -------------------------------------------------------------------------
     */

    /**
     * Base only: no card, incident or text names a source the base does not
     * own. A Starter site has no webhooks and no email ingestion, and a page
     * that listed them would advertise code that is not there.
     */
    public function test_base_only_names_no_add_on_source(): void {
        $this->without_plugin_sections();
        $this->record(incident::SOURCE_OBSERVER, 'observer.notify.failed', incident::SEVERITY_ERROR);

        $context = (new health_page())->export(self::PAGE_URL);
        $keys    = array_column($context['sources'], 'key');
        $this->assertNotContains('webhook', $keys);
        $this->assertNotContains('email_ingestion', $keys);

        $html = $this->render_page($context);
        $this->assertStringNotContainsString('Webhooks', $html);
        $this->assertStringNotContainsString('Email ingestion', $html);
    }

    /**
     * The empty state names no source either, since which sources exist
     * depends on what is installed.
     */
    public function test_the_empty_state_names_no_source(): void {
        $this->without_plugin_sections();
        $html = $this->render_page((new health_page())->export(self::PAGE_URL));

        $this->assertStringContainsString(
            get_string('health_empty_body', 'local_moodesk', 24),
            $html
        );
        $this->assertStringNotContainsStringIgnoringCase('webhook', $html);
        $this->assertStringNotContainsStringIgnoringCase('ingestion', $html);
    }

    /**
     * A source a plugin declares gets its card, under the plugin's label, and
     * its incidents show and count towards the overall state.
     */
    public function test_a_declared_source_gets_a_card_and_its_incidents_show(): void {
        $this->declare_webhook_source();

        $id      = $this->record('webhook', 'webhook.delivery.abandoned', incident::SEVERITY_CRITICAL);
        $context = $this->context();

        $bykey = array_column($context['sources'], null, 'key');
        $this->assertCount(5, $bykey);
        $this->assertSame(get_string('pluginname', 'local_moodesk'), $bykey['webhook']['label']);
        $this->assertSame(1, $bykey['webhook']['count']);

        $this->assertSame([$id], array_column($context['incidents'], 'id'));
        $this->assertSame(get_string('pluginname', 'local_moodesk'), $context['incidents'][0]['sourcelabel']);
        $this->assertSame(health_page::STATE_DOWN, $context['state']);
    }

    /**
     * An incident whose source nobody declares any more — the add-on was
     * removed — is not shown, does not count, and is not deleted: once the
     * source is declared again, it is back.
     */
    public function test_an_undeclared_source_is_hidden_but_kept(): void {
        global $DB;

        $this->declare_webhook_source();
        $id = $this->record('webhook', 'webhook.delivery.abandoned', incident::SEVERITY_CRITICAL);

        // The add-on is gone.
        $this->redirectHook(health_sources::class, static function (health_sources $hook): void {
        });

        $context = $this->context();
        $this->assertNotContains('webhook', array_column($context['sources'], 'key'));
        $this->assertSame([], $context['incidents']);
        $this->assertFalse($context['hasincidents']);
        $this->assertSame(health_page::STATE_HEALTHY, $context['state']);
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $id]));

        // And back.
        $this->declare_webhook_source();
        $this->assertSame([$id], array_column($this->context()['incidents'], 'id'));
    }

    /**
     * Drop the sections plugins add to the page (hook health_panels), so a
     * case about the base's own page holds whether the add-on is installed.
     */
    private function without_plugin_sections(): void {
        $this->redirectHook(\local_moodesk\hook\health_panels::class, static function (): void {
        });
    }

    /**
     * Declare 'webhook' the way local_moodeskpro does, with a label the base
     * owns so the case runs with or without the add-on installed.
     */
    private function declare_webhook_source(): void {
        $this->redirectHook(health_sources::class, static function (health_sources $hook): void {
            $hook->add_source('webhook', 'local_moodesk', 'pluginname');
        });
    }
}
