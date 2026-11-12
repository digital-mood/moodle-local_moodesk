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
 * Edition and feature gating service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Static service for edition detection and feature gating.
 *
 * DESIGN RATIONALE:
 *  All Pro and Enterprise feature paths begin with a call to require_feature().
 *  This is the single enforcement point. Feature gating is never scattered
 *  as ad hoc config checks across service methods.
 *
 *  Edition resolution (get_edition, licence contract v2 §6.2–§6.3): the
 *  licence verified by local_moodeskpro decides, and nothing on the install
 *  can raise it. Without the add-on the site is Starter. The
 *  development override (moodesk_LOCAL_EDITION) and the OEM pin
 *  (MOODESK_DIST_EDITION) can only restrict it (decision O-2); without a valid
 *  licence the install is Starter whatever they say. The plugin config
 *  `edition` row is not read at all. See get_edition() for the full order and
 *  the test seams.
 *
 * FEATURE MAP:
 *  Each entry maps a feature name to the minimum edition required.
 *  Features not present in the map are assumed available in all editions.
 *
 * ADDING A NEW GATED FEATURE:
 *  1. Add a constant to FEATURES with the appropriate edition requirement.
 *  2. Call edition_service::require_feature('your_feature') as the first line
 *     of the service method that implements it.
 *  3. Do not add the call anywhere else.
 */
class edition_service {
    /*
     * -------------------------------------------------------------------------
     * Edition constants (ordered — do not reorder)
     * -------------------------------------------------------------------------
     */

    /** Free tier, available without a licence. */
    const EDITION_STARTER    = 'starter';
    /** Paid tier unlocking the Pro feature set. */
    const EDITION_PRO        = 'pro';
    /** Top tier unlocking every feature. */
    const EDITION_ENTERPRISE = 'enterprise';

    /**
     * Edition hierarchy. Higher index = higher tier.
     * Used by edition_meets_requirement() to compare editions.
     */
    private const EDITION_ORDER = [
        self::EDITION_STARTER    => 0,
        self::EDITION_PRO        => 1,
        self::EDITION_ENTERPRISE => 2,
    ];

    /*
     * -------------------------------------------------------------------------
     * Feature → minimum edition map
     * -------------------------------------------------------------------------
     */

    /** Feature keys granted by each edition, cumulative from Starter upwards. */
    const FEATURES = [

        /* -------- Starter (always-on core capabilities) --------------------- */
        // These keys exist so that calls to require_feature() are consistent
        // across editions. They never block in any tier.

        'sla'                  => self::EDITION_STARTER,
        'multi_department'     => self::EDITION_STARTER,
        'categories'           => self::EDITION_STARTER,
        // The knowledge base core: articles, reading, search and the
        // suggestions a requester sees while opening a ticket (KB-01..04).
        // Starter by product decision (2026-09-12) — without ticket deflection
        // a knowledge base is a content system, not a support feature.
        'knowledge_base'       => self::EDITION_STARTER,

        /* -------- Pro ------------------------------------------------------- */
        // Backend scaffolding ships in the repository; UI / observers /
        // scheduled tasks come online when the active edition is Pro+.

        // Knowledge base tools inside the agent's ticket workflow (KB-05):
        // insert an article link into a reply, start a draft from a ticket.
        // Registered before anything asks for it on purpose — an unknown key
        // is allowed everywhere (see require_feature), so a gate on a key that
        // is not in this map is no gate at all.
        'kb_agent_tools'       => self::EDITION_PRO,

        // Translations of one article as rows of one group, resolved to the
        // reader's language with a fallback (KB-06): adding a translation,
        // changing a row's language and the translate= entry are gated here;
        // reading a row by id, the policy and one-result-per-group never are.
        // Same rule as above — registered before anything asks for it.
        'kb_multilingual'      => self::EDITION_PRO,

        // The "Was this article helpful?" control on the article page and the vote it
        // stores (KB-07). The control is hidden and the write refused below
        // Pro; existing votes are kept through a downgrade.
        'kb_feedback'          => self::EDITION_PRO,

        // The knowledge base analytics event stream and the analytics page
        // (KB-07). Pro, beside advanced_reports and dashboard_analytics, by
        // decision D1 of 2026-09-15: a report about content the site already
        // owns is operational depth, not integration. Recording is a silent
        // no-op below Pro — has_feature(), the one deliberate exception to
        // "require_feature first", because a recorder that throws would break
        // the reader it rides on.
        'kb_analytics'         => self::EDITION_PRO,

        // Published articles as documents in Moodle's global search (KB-07b).
        // Indexed on every edition and gated where the engine asks — the
        // area's check_access() — so a Starter site's index is complete the
        // day it becomes Pro and every hit is denied until then. has_feature()
        // there, like the recorder: an area that throws inside the engine's
        // result loop would break the search page for every other area.
        'kb_core_search'       => self::EDITION_PRO,

        'teams'                => self::EDITION_PRO,
        'csat'                 => self::EDITION_PRO,
        'custom_fields'        => self::EDITION_PRO,
        'automation'           => self::EDITION_PRO,
        'agent_panel'          => self::EDITION_PRO,
        'webhooks'             => self::EDITION_ENTERPRISE,
        'email_ingestion'      => self::EDITION_PRO,
        'advanced_reports'     => self::EDITION_PRO,

        // The analytical depth of the dashboard: the SLA performance gauge,
        // detailed agent workload and top categories. Deliberately separate
        // from 'advanced_reports', which gates the Reports page as a whole —
        // Starter keeps a dashboard it can genuinely run a helpdesk from
        // (KPIs, status, priority, department, aging, trends, activity).
        'dashboard_analytics'  => self::EDITION_PRO,
        'sla_per_department'   => self::EDITION_PRO,

        /* -------- Enterprise ----------------------------------------------- */

        // Gates merge_service, which calls require_feature('ticket_merge') on
        // every entry point: merge.php, the sidebar action on the ticket, the
        // undo on the target, and the REST merge functions. Capabilities
        // :mergetickets and :revertmerge. See docs/ticket-merge-design-doc.md.
        'ticket_merge'         => self::EDITION_ENTERPRISE,

        'agent_supervision'    => self::EDITION_ENTERPRISE,

        'api_access'           => self::EDITION_ENTERPRISE,

    ];

    /*
     * -------------------------------------------------------------------------
     * Public interface
     * -------------------------------------------------------------------------
     */

    /**
     * Return the active edition for this installation.
     *
     * Resolution order (licence contract v2, §6.2 and §6.3):
     *  1. set_edition_for_testing(), only under PHPUNIT_TEST. Wins outright.
     *  2. The local_moodesk/behat_edition config row, only under
     *     BEHAT_SITE_RUNNING. Wins outright.
     *  3. The development override, the moodesk_LOCAL_EDITION constant that
     *     local_config.php defines. It can only restrict:
     *     E = min(override, licensed tier), and Starter without a valid
     *     licence. **One exception, under PHPUNIT_TEST only:** there the
     *     constant keeps its old, unclamped behaviour, because it is part of
     *     the test harness — the PHPUnit matrix legs of CI force their edition
     *     with it and carry no licence (.github/workflows/phpunit.yml). Outside
     *     a PHPUnit run it is always clamped.
     *  4. The OEM pin, MOODESK_DIST_EDITION in local_moodesk/edition_dist.php.
     *     It can only restrict, like 3. The official ZIP never contains one.
     *  5. The resolve_edition hook: local_moodeskpro answers with the tier of a
     *     licence that passed both validity layers. Without an answer the
     *     site is EDITION_STARTER. See licensed_by_plugin().
     *
     * The first source present decides; an unknown value is skipped. Only the
     * test seams (1, 2 and 3 under PHPUnit) can put the edition above the
     * licence, and none of them exists on a production install.
     *
     * The answer is memoised for the request (this is called on every gated
     * path), keyed on what can change mid-request: the generation and the test
     * seams.
     *
     * @return string One of the EDITION_* constants.
     */
    public static function get_edition(): string {
        return self::resolve()->effective;
    }

    /**
     * Which source decided the edition, and whether it was restricted.
     *
     * For the notes on License status and System Health, and for diagnostics.
     *  - source: 'phpunit' | 'behat' | 'dev_override' | 'dist_pin' | 'licence'
     *    | 'fallback'. Under PHPUnit the unclamped moodesk_LOCAL_EDITION
     *    reports 'phpunit': it is acting as a test seam there.
     *  - requested: what the deciding source asked for (null for the licence
     *    and the fallback).
     *  - effective: the edition, the same value get_edition() returns.
     *  - lowered: a restricting source set the edition below the licensed tier.
     *  - ignored: a restricting source asked for more than the licence allows
     *    and was clamped.
     *
     * @return \stdClass {source: string, requested: ?string, effective: string, lowered: bool, ignored: bool}
     */
    public static function edition_source(): \stdClass {
        return clone self::resolve();
    }

    /**
     * The edition a restricting source yields against the licensed tier.
     *
     * The rule of decision O-2: E = min(requested, licensed), and Starter when
     * there is no valid licence. Pure, so the clamp can be tested for every
     * combination (contract S19–S25) without defining process-wide constants.
     *
     * @param string|null $requested The edition the source asks for, or null.
     * @param string|null $licensed  The licensed tier, or null without a valid licence.
     * @return string One of the EDITION_* constants.
     */
    public static function clamp_to_licence(?string $requested, ?string $licensed): string {
        $ceiling = ($licensed !== null && isset(self::EDITION_ORDER[$licensed])) ? $licensed : self::EDITION_STARTER;
        if ($requested === null || !isset(self::EDITION_ORDER[$requested])) {
            return $ceiling;
        }
        return self::EDITION_ORDER[$requested] < self::EDITION_ORDER[$ceiling] ? $requested : $ceiling;
    }

    /**
     * Whether an edition includes a feature. Features not in the map are
     * available everywhere.
     *
     * @param string $edition One of the EDITION_* constants.
     * @param string $feature A key from self::FEATURES.
     * @return bool
     */
    public static function edition_includes(string $edition, string $feature): bool {
        if (!array_key_exists($feature, self::FEATURES)) {
            return true;
        }
        return self::edition_meets_requirement($edition, self::FEATURES[$feature]);
    }

    /**
     * True when $a is a lower edition than $b.
     *
     * @param string $a One of the EDITION_* constants.
     * @param string $b One of the EDITION_* constants.
     * @return bool
     */
    public static function is_lower(string $a, string $b): bool {
        return (self::EDITION_ORDER[$a] ?? 0) < (self::EDITION_ORDER[$b] ?? 0);
    }

    /**
     * Assert that the current edition includes the given feature.
     *
     * Throws \moodle_exception if the feature is not available.
     * Returns void silently if the feature is available.
     *
     * Usage: call this as the first line of any Pro/Enterprise service method.
     *
     * @param string $feature A key from self::FEATURES.
     * @throws \moodle_exception If the feature is not available in the current edition.
     */
    public static function require_feature(string $feature): void {
        if (!array_key_exists($feature, self::FEATURES)) {
            // Unknown feature — not gated. Allow.
            return;
        }

        $required = self::FEATURES[$feature];
        $current  = self::get_edition();

        if (!self::edition_meets_requirement($current, $required)) {
            // Deliberately silent. An earlier revision logged a blocked feature
            // at DEBUG_DEVELOPER, and it was wrong twice over: require_feature()
            // sits on paths that run on every page, so even a debug-level line
            // is noise measured in thousands a day, and under PHPUnit every
            // debugging() call is an assertion failure unless the test expects
            // it — which turned every existing test of an edition block into a
            // failure in tests that have nothing to do with logging.
            //
            // Nothing is lost. A block is the gate working, not an incident,
            // and the exception below already names the feature and travels up
            // the stack where a developer can see it. What is worth auditing is
            // the licensing decision behind the edition, and local_moodeskpro
            // records that.
            throw new \moodle_exception(
                'featurenotavailable',
                'local_moodesk',
                '',
                $feature
            );
        }
    }

    /**
     * Return true if the current edition includes a feature, without throwing.
     *
     * Use this for conditional UI rendering (e.g. show/hide upgrade prompts).
     * For enforcing access to functionality, use require_feature() instead.
     *
     * @param string $feature
     * @return bool
     */
    public static function has_feature(string $feature): bool {
        if (!array_key_exists($feature, self::FEATURES)) {
            return true;
        }
        return self::edition_includes(self::get_edition(), $feature);
    }

    /*
     * -------------------------------------------------------------------------
     * Test seam
     * -------------------------------------------------------------------------
     */

    /**
     * Forced edition for the current request.
     * Honored only when PHPUNIT_TEST is defined; see get_edition().
     *
     * @var string|null
     */
    private static ?string $editionoverride = null;

    /**
     * Test-only setter to force get_edition()'s result.
     *
     * Replaces the old habit of opening Pro/Enterprise feature gates with
     * `set_config('edition', ...)`, which only worked while the (now removed)
     * license compatibility mode honoured that legacy config row. Silently
     * ignored outside PHPUnit so it cannot be misused at runtime.
     *
     * @param string|null $edition One of the EDITION_* constants, or null to reset.
     */
    public static function set_edition_for_testing(?string $edition): void {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            self::$editionoverride = $edition;
            self::$resolved = null;
        }
    }

    /**
     * Test-only stand-in for the moodesk_LOCAL_EDITION constant.
     * Honored only when PHPUNIT_TEST is defined; see set_dev_override_for_testing().
     *
     * @var string|false|null
     */
    private static $devoverrideseam = null;

    /**
     * Test-only: replace what get_edition() reads as the development override.
     *
     * The constant cannot be undefined or redefined in-process, and under
     * PHPUnit it is a harness seam that is never clamped. This seam lets a test
     * see the production behaviour instead:
     *  - false: behave as if moodesk_LOCAL_EDITION were not defined, so the
     *    licence decides on every CI leg (rows with no edition source);
     *  - a string: behave as if the constant held it on a normal install, so
     *    it is clamped to the licence (contract S22–S25);
     *  - null: reset to reading the constant.
     * Silently ignored outside PHPUnit.
     *
     * @param string|false|null $value
     */
    public static function set_dev_override_for_testing($value): void {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            self::$devoverrideseam = $value;
            self::$resolved = null;
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Dist-edition pin (build-time)
     * -------------------------------------------------------------------------
     */

    /**
     * Tracks whether the dist file has been (attempted to be) loaded this request.
     *
     * @var bool
     */
    private static bool $distloaded = false;

    /**
     * Test-only override for the dist edition file path.
     * Honored only when PHPUNIT_TEST is defined.
     *
     * @var string|null
     */
    private static ?string $disteditionpathoverride = null;

    /**
     * Absolute path to the edition_dist.php file (present only in distributed builds).
     *
     * @return string
     */
    public static function dist_edition_path(): string {
        if (self::$disteditionpathoverride !== null && defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return self::$disteditionpathoverride;
        }
        return __DIR__ . '/../../edition_dist.php';
    }

    /**
     * Test-only setter to point dist_edition_path() at a fixture file (or null to use default).
     * Silently ignored outside PHPUnit so it cannot be misused at runtime.
     *
     * Because PHP constants are immutable once defined, callers that vary the
     * fixture across cases should run those tests in a separate process
     * (PHPUnit @runInSeparateProcess) — this resets the static load flag and
     * lets each fixture re-define MOODESK_DIST_EDITION cleanly.
     *
     * @param string|null $path Absolute path to a fixture file, or null to reset.
     */
    public static function set_dist_edition_path_for_testing(?string $path): void {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            self::$disteditionpathoverride = $path;
            self::$distloaded = false;
            self::$resolved = null;
        }
    }

    /*
     * -------------------------------------------------------------------------
     * Private
     * -------------------------------------------------------------------------
     */

    /** @var \stdClass|null The memoised resolution; see resolve(). */
    private static ?\stdClass $resolved = null;

    /** @var string What $resolved was computed from. */
    private static string $resolvedkey = '';

    /** @var int Unix time after which $resolved is recomputed anyway. */
    private static int $resolveduntil = 0;

    /** @var int Bumped to drop the memo; see invalidate_resolution(). */
    private static int $generation = 0;

    /**
     * Drop the memoised edition. A plugin calls this when what it answers changes.
     */
    public static function invalidate_resolution(): void {
        self::$generation++;
    }

    /** Seconds a memoised resolution lives, so a long cron run sees an expiry. */
    private const RESOLUTION_TTL = 60;

    /**
     * The edition and the source that decided it, memoised.
     *
     * The key holds every input that can change within one request or between
     * two PHPUnit tests in the same process: the generation (a plugin bumps it
     * through invalidate_resolution()), the Behat row and the test seams (which
     * also clear the memo when they are set).
     *
     * @return \stdClass See edition_source().
     */
    private static function resolve(): \stdClass {
        $behat = (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING)
            ? (string) get_config('local_moodesk', 'behat_edition')
            : '';
        $key = implode('|', [
            self::$generation,
            $behat,
            var_export(self::$editionoverride, true),
            var_export(self::$devoverrideseam, true),
            (string) self::$disteditionpathoverride,
        ]);
        $now = time();
        if (self::$resolved !== null && self::$resolvedkey === $key && $now < self::$resolveduntil) {
            return self::$resolved;
        }

        self::$resolved = self::compute_source();
        self::$resolvedkey = $key;
        self::$resolveduntil = $now + self::RESOLUTION_TTL;
        return self::$resolved;
    }

    /**
     * The edition a plugin answers for (hook resolve_edition), Starter without one.
     *
     * Runs inside the memo, so once per resolution and not on every gated
     * call, and only when no test seam has already decided. The sources that
     * can only restrict are clamped to this answer, so they keep applying when
     * a plugin raises the edition.
     *
     * @return \stdClass See edition_source().
     */
    private static function licensed_by_plugin(): \stdClass {
        $hook = new \local_moodesk\hook\resolve_edition(self::EDITION_STARTER, 'fallback');
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return self::source($hook->get_source(), null, $hook->get_edition());
    }

    /**
     * The tier a plugin answered for, or null when the site has none.
     *
     * @return string|null
     */
    private static function licensed_tier(): ?string {
        $licensed = self::licensed_by_plugin();
        return $licensed->source === 'fallback' ? null : $licensed->effective;
    }

    /**
     * Walk the sources in order (see get_edition()) and return the first that decides.
     *
     * @return \stdClass See edition_source().
     */
    private static function compute_source(): \stdClass {
        $phpunit = defined('PHPUNIT_TEST') && PHPUNIT_TEST;

        if (self::$editionoverride !== null && $phpunit) {
            return self::source('phpunit', self::$editionoverride, self::$editionoverride);
        }

        // Behat equivalent of the override above. The smoke suite has to drive
        // both sides of an edition gate from a feature file, and the step that
        // sets it runs in a different process from the request that reads it,
        // so a static cannot carry it — this is a config row instead. It is
        // read only while Moodle is serving the Behat test site:
        // BEHAT_SITE_RUNNING is defined by lib/setup.php once the request
        // matches $CFG->behat_wwwroot against the Behat data root, neither of
        // which exists on a production install.
        if (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING) {
            $behatedition = get_config('local_moodesk', 'behat_edition');
            if (is_string($behatedition) && isset(self::EDITION_ORDER[$behatedition])) {
                return self::source('behat', $behatedition, $behatedition);
            }
        }

        // The development override. Under PHPUnit the real constant is the CI
        // legs' edition switch and stays unclamped; the seam stands in for a
        // constant on a normal install, where it is always clamped.
        $override = null;
        $harness = false;
        if ($phpunit && self::$devoverrideseam !== null) {
            $override = is_string(self::$devoverrideseam) ? self::$devoverrideseam : null;
        } else if (defined('moodesk_LOCAL_EDITION')) {
            $override = constant('moodesk_LOCAL_EDITION');
            $harness = $phpunit;
        }
        if (is_string($override) && isset(self::EDITION_ORDER[$override])) {
            return self::dev_override_resolution($override, $harness);
        }

        self::ensure_dist_edition_loaded();
        if (defined('MOODESK_DIST_EDITION')) {
            $dist = constant('MOODESK_DIST_EDITION');
            if (is_string($dist) && isset(self::EDITION_ORDER[$dist])) {
                return self::restricted('dist_pin', $dist, self::licensed_tier());
            }
        }

        return self::licensed_by_plugin();
    }

    /**
     * The resolution for a valid moodesk_LOCAL_EDITION value.
     *
     * Contract v2 §6.2, row 3: the constant is unclamped only as the PHPUnit
     * harness's edition switch. Everywhere else it can only restrict (O-2).
     * Kept separate so tests can drive both sides of that rule.
     *
     * @param string $override A valid EDITION_* value.
     * @param bool   $harness  True only when the real constant is read under PHPUNIT_TEST.
     * @return \stdClass See edition_source().
     */
    private static function dev_override_resolution(string $override, bool $harness): \stdClass {
        if ($harness) {
            return self::source('phpunit', $override, $override);
        }
        return self::restricted('dev_override', $override, self::licensed_tier());
    }

    /**
     * The resolution for a source that can only restrict (O-2).
     *
     * @param string      $source    'dev_override' or 'dist_pin'.
     * @param string      $requested What the source asks for.
     * @param string|null $licensed  The licensed tier, or null.
     * @return \stdClass
     */
    private static function restricted(string $source, string $requested, ?string $licensed): \stdClass {
        $effective = self::clamp_to_licence($requested, $licensed);
        $ceiling = ($licensed !== null && isset(self::EDITION_ORDER[$licensed])) ? $licensed : self::EDITION_STARTER;
        return self::source(
            $source,
            $requested,
            $effective,
            $licensed !== null && self::is_lower($requested, $ceiling),
            self::is_lower($ceiling, $requested)
        );
    }

    /**
     * Build an edition_source() record.
     *
     * @param string      $source
     * @param string|null $requested
     * @param string      $effective
     * @param bool        $lowered
     * @param bool        $ignored
     * @return \stdClass
     */
    private static function source(
        string $source,
        ?string $requested,
        string $effective,
        bool $lowered = false,
        bool $ignored = false
    ): \stdClass {
        return (object) [
            'source'    => $source,
            'requested' => $requested,
            'effective' => $effective,
            'lowered'   => $lowered,
            'ignored'   => $ignored,
        ];
    }

    /**
     * Include edition_dist.php at most once per request, if it exists.
     *
     * @return void
     */
    private static function ensure_dist_edition_loaded(): void {
        if (self::$distloaded) {
            return;
        }
        self::$distloaded = true;
        $path = self::dist_edition_path();
        if (is_readable($path)) {
            require_once($path);
        }
    }

    /**
     * Return true if $current edition satisfies the $required edition level.
     *
     * @param string $current  One of the EDITION_* constants.
     * @param string $required One of the EDITION_* constants.
     * @return bool
     */
    private static function edition_meets_requirement(string $current, string $required): bool {
        $currentlevel  = self::EDITION_ORDER[$current] ?? 0;
        $requiredlevel = self::EDITION_ORDER[$required] ?? 0;
        return $currentlevel >= $requiredlevel;
    }
}
