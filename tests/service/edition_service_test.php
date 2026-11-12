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
 * Integration tests for the edition feature-gating service.
 *
 * Regression suite for the gating contract: starter installs must not be able
 * to invoke require_feature() for any Pro/Enterprise capability.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Tests for edition_service.
 *
 * The dist tests below keep the @runInSeparateProcess / @preserveGlobalState
 * annotations rather than the PHP 8 attributes the coding standard prefers:
 * the MOODLE_405 leg of CI runs PHPUnit 9.6, which does not read attributes,
 * and these tests need real process isolation because they mutate global
 * state. Revisit once 4.5 leaves the matrix.
 *
 * @covers \local_moodesk\service\edition_service
 */
final class edition_service_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        edition_service::set_dev_override_for_testing(null);
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * Let the licence decide on every CI leg.
     *
     * Under PHPUnit the moodesk_LOCAL_EDITION constant that the Pro and
     * Enterprise legs define is the harness's edition switch and is not
     * clamped, so a test that asserts what get_edition() resolves from the
     * licence (or its absence) masks it. These tests used to skip on those
     * legs; since licence contract v2 they run everywhere.
     */
    private function mask_dev_override(): void {
        edition_service::set_dev_override_for_testing(false);
    }

    public function test_default_edition_is_starter_when_unconfigured(): void {
        $this->mask_dev_override();
        // No 'edition' config and no licence → fallback to starter.
        unset_config('edition', 'local_moodesk');
        unset_config('license_key', 'local_moodesk');
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
    }

    public function test_legacy_edition_config_does_not_elevate_without_license(): void {
        $this->mask_dev_override();
        unset_config('license_key', 'local_moodesk');

        // JWT Gate #3 (1.5.0): the legacy `edition` config row is dead config.
        // Without a valid license, resolution always lands on the Starter floor
        // regardless of the row's value — closing the mdl_config_plugins bypass
        // where an admin could self-promote the install.
        set_config('edition', edition_service::EDITION_PRO, 'local_moodesk');
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());

        set_config('edition', edition_service::EDITION_ENTERPRISE, 'local_moodesk');
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());

        set_config('edition', 'super_galactic_tier', 'local_moodesk');
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
    }

    public function test_starter_features_always_available(): void {
        // Starter-tier features are mapped to EDITION_STARTER and therefore
        // available in every tier — independent of get_edition()'s resolution.
        $this->assertTrue(edition_service::has_feature('sla'));
        $this->assertTrue(edition_service::has_feature('multi_department'));
        $this->assertTrue(edition_service::has_feature('categories'));
        $this->assertTrue(edition_service::has_feature('knowledge_base'));

        // The require_feature() call must not throw for any starter-tier feature.
        edition_service::require_feature('sla');
        edition_service::require_feature('multi_department');
        edition_service::require_feature('categories');
        edition_service::require_feature('knowledge_base');
    }

    public function test_pro_feature_blocked_in_starter(): void {
        $this->mask_dev_override();
        // No license, no override → resolves to Starter; Pro features must be
        // blocked.
        unset_config('license_key', 'local_moodesk');

        $this->assertFalse(edition_service::has_feature('automation'));
        $this->assertFalse(edition_service::has_feature('webhooks'));
        $this->assertFalse(edition_service::has_feature('csat'));
        $this->assertFalse(edition_service::has_feature('teams'));

        $this->expectException(\moodle_exception::class);
        edition_service::require_feature('automation');
    }

    public function test_enterprise_feature_blocked_in_pro(): void {
        // The api_access feature requires Enterprise, so this "blocked"
        // assertion only holds when the resolved edition is below Enterprise.
        edition_service::set_edition_for_testing(edition_service::EDITION_PRO);

        $this->assertFalse(edition_service::has_feature('api_access'));

        $this->expectException(\moodle_exception::class);
        edition_service::require_feature('api_access');
    }

    /**
     * KB-05: the agent tools are Pro, and the gate is a registered key.
     *
     * Three editions, one key. The third assertion is the one that matters
     * most: require_feature() allows an unknown key everywhere (next test), so
     * a gate on a key missing from FEATURES is no gate — this proves the
     * KB-05 gates rest on a registered Pro key, not on that allowance.
     */
    public function test_kb_agent_tools_is_a_registered_pro_feature(): void {
        $this->assertArrayHasKey('kb_agent_tools', edition_service::FEATURES);
        $this->assertSame(edition_service::EDITION_PRO, edition_service::FEATURES['kb_agent_tools']);

        try {
            edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);
            $this->assertFalse(edition_service::has_feature('kb_agent_tools'));
            try {
                edition_service::require_feature('kb_agent_tools');
                $this->fail('Starter must refuse kb_agent_tools.');
            } catch (\moodle_exception $e) {
                $this->assertSame('featurenotavailable', $e->errorcode);
            }

            edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
            $this->assertTrue(edition_service::has_feature('kb_agent_tools'));
            edition_service::require_feature('kb_agent_tools');

            edition_service::set_edition_for_testing(edition_service::EDITION_ENTERPRISE);
            $this->assertTrue(edition_service::has_feature('kb_agent_tools'));
            edition_service::require_feature('kb_agent_tools');
        } finally {
            edition_service::set_edition_for_testing(null);
        }
    }

    /**
     * kb_multilingual is a registered Pro key (KB-06), for the same reason as
     * kb_agent_tools above: the gates on add_translation(), a lang change and
     * the translate= entry rest on it.
     */
    public function test_kb_multilingual_is_a_registered_pro_feature(): void {
        $this->assertArrayHasKey('kb_multilingual', edition_service::FEATURES);
        $this->assertSame(edition_service::EDITION_PRO, edition_service::FEATURES['kb_multilingual']);

        try {
            edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);
            $this->assertFalse(edition_service::has_feature('kb_multilingual'));
            try {
                edition_service::require_feature('kb_multilingual');
                $this->fail('Starter must refuse kb_multilingual.');
            } catch (\moodle_exception $e) {
                $this->assertSame('featurenotavailable', $e->errorcode);
            }

            edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
            $this->assertTrue(edition_service::has_feature('kb_multilingual'));
            edition_service::require_feature('kb_multilingual');

            edition_service::set_edition_for_testing(edition_service::EDITION_ENTERPRISE);
            $this->assertTrue(edition_service::has_feature('kb_multilingual'));
            edition_service::require_feature('kb_multilingual');
        } finally {
            edition_service::set_edition_for_testing(null);
        }
    }

    /**
     * KB-07 (D1, 2026-09-15): feedback and analytics are Pro, registered keys.
     *
     * Two keys with one shape, and the same third assertion as above: the
     * feedback write is refused below Pro with the standard error, and the
     * analytics recorder — which asks has_feature() rather than throwing —
     * answers false there, so a Starter reader records nothing.
     *
     * @dataProvider kb07_pro_keys
     * @param string $key
     */
    public function test_kb07_keys_are_registered_pro_features(string $key): void {
        $this->assertArrayHasKey($key, edition_service::FEATURES);
        $this->assertSame(edition_service::EDITION_PRO, edition_service::FEATURES[$key]);

        try {
            edition_service::set_edition_for_testing(edition_service::EDITION_STARTER);
            $this->assertFalse(edition_service::has_feature($key));
            try {
                edition_service::require_feature($key);
                $this->fail("Starter must refuse $key.");
            } catch (\moodle_exception $e) {
                $this->assertSame('featurenotavailable', $e->errorcode);
            }

            edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
            $this->assertTrue(edition_service::has_feature($key));
            edition_service::require_feature($key);

            edition_service::set_edition_for_testing(edition_service::EDITION_ENTERPRISE);
            $this->assertTrue(edition_service::has_feature($key));
            edition_service::require_feature($key);
        } finally {
            edition_service::set_edition_for_testing(null);
        }
    }

    /**
     * The KB-07 keys.
     *
     * @return array<string, string[]>
     */
    public static function kb07_pro_keys(): array {
        return [
            'kb_feedback'    => ['kb_feedback'],
            'kb_analytics'   => ['kb_analytics'],
            'kb_core_search' => ['kb_core_search'],
        ];
    }

    public function test_unknown_feature_keys_are_not_gated(): void {
        // Features absent from the FEATURES map must be allowed in any edition,
        // so adding a new gated feature is a deliberate, two-step act
        // (declare in map + add require_feature() call site).
        set_config('edition', edition_service::EDITION_STARTER, 'local_moodesk');

        $this->assertTrue(edition_service::has_feature('made_up_thing'));
        edition_service::require_feature('made_up_thing'); // Must not throw.
        $this->assertTrue(true); // Sanity assertion to mark the path as reached.
    }

    /*
     * -------------------------------------------------------------------------
     * edition_dist.php (build-time pin) coverage
     * -------------------------------------------------------------------------
     *
     * The MOODESK_DIST_EDITION constant is process-wide and cannot be
     * un-defined once set. Every test that defines it MUST run in its own PHP
     * subprocess so the constant does not leak into sibling tests.
     *
     * Resolution order under test (licence contract v2 §6.2):
     * 1. moodesk_LOCAL_EDITION   (dev override; restricts only)
     * 2. MOODESK_DIST_EDITION    (OEM pin; restricts only)
     * 3. the resolve_edition hook
     * 4. EDITION_STARTER         (fallback)
     *
     * The pin against every licence (S19–S21) is in agent_capacity_test;
     * here we assert the constant-driven behaviour.
     */

    /**
     * Write a fixture edition_dist.php that defines MOODESK_DIST_EDITION
     * to the supplied value and return its absolute path.
     *
     * @param string $value Raw PHP literal for the constant value (already quoted).
     * @return string
     */
    private function write_dist_fixture(string $value): string {
        $dir  = make_request_directory();
        $path = $dir . '/edition_dist.php';
        $php  = "<?php\ndefined('MOODLE_INTERNAL') || die();\n"
              . "define('MOODESK_DIST_EDITION', $value);\n";
        file_put_contents($path, $php);
        return $path;
    }

    /**
     * A dist pin shipped in the build takes precedence over licence resolution.
     *
     * @runInSeparateProcess
     * phpcs:ignore moodle.Commenting.ValidTags.Invalid
     * @preserveGlobalState disabled
     */
    public function test_dist_pins_edition_when_present(): void {
        $this->mask_dev_override();
        unset_config('license_key', 'local_moodesk');

        $fixture = $this->write_dist_fixture("'starter'");
        edition_service::set_dist_edition_path_for_testing($fixture);

        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
    }

    /**
     * A dist pin of 'pro' cannot raise a site without a licence: it stays
     * Starter (contract S21, decision O-2). Before contract v2 it was Pro.
     *
     * @runInSeparateProcess
     * phpcs:ignore moodle.Commenting.ValidTags.Invalid
     * @preserveGlobalState disabled
     */
    public function test_dist_pin_pro_without_a_licence_stays_starter(): void {
        $this->mask_dev_override();
        unset_config('license_key', 'local_moodesk');

        $fixture = $this->write_dist_fixture("'pro'");
        edition_service::set_dist_edition_path_for_testing($fixture);

        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
        $source = edition_service::edition_source();
        $this->assertSame('dist_pin', $source->source);
        $this->assertSame('pro', $source->requested);
        $this->assertTrue($source->ignored);
    }

    /**
     * An unrecognised dist pin is ignored and the edition falls back to Starter.
     *
     * @runInSeparateProcess
     * phpcs:ignore moodle.Commenting.ValidTags.Invalid
     * @preserveGlobalState disabled
     */
    public function test_dist_invalid_value_is_ignored_falls_back_to_starter(): void {
        $this->mask_dev_override();
        unset_config('license_key', 'local_moodesk');
        // No legacy edition config either, so the no-license path resolves to
        // the Starter floor without emitting the compat-mode debugging().
        unset_config('edition', 'local_moodesk');

        $fixture = $this->write_dist_fixture("'super_galactic_tier'");
        edition_service::set_dist_edition_path_for_testing($fixture);

        // Unknown tier in the dist file → ignored, no license → fallback Starter.
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
    }

    /**
     * With no dist pin present, the edition comes from licence resolution.
     *
     * @runInSeparateProcess
     * phpcs:ignore moodle.Commenting.ValidTags.Invalid
     * @preserveGlobalState disabled
     */
    public function test_dist_absent_falls_back_to_license_resolution(): void {
        $this->mask_dev_override();
        unset_config('license_key', 'local_moodesk');
        unset_config('edition', 'local_moodesk');

        // Point the override at a file that does NOT exist — the loader must
        // not warn, and resolution must continue down the chain.
        edition_service::set_dist_edition_path_for_testing('/nonexistent/edition_dist.php');

        // No license either → fallback Starter.
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
    }

    /*
     * -------------------------------------------------------------------------
     * The memoised resolution
     * -------------------------------------------------------------------------
     */

    /**
     * The test seams reset the memo when they are set.
     */
    public function test_the_seams_reset_the_memo(): void {
        $this->mask_dev_override();
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());

        edition_service::set_edition_for_testing(edition_service::EDITION_PRO);
        $this->assertSame(edition_service::EDITION_PRO, edition_service::get_edition());
        $this->assertSame('phpunit', edition_service::edition_source()->source);

        edition_service::set_edition_for_testing(null);
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
    }

    /**
     * edition_source() hands out a copy: a caller cannot change the edition.
     */
    public function test_edition_source_is_a_copy(): void {
        $this->mask_dev_override();
        $source = edition_service::edition_source();
        $source->effective = edition_service::EDITION_ENTERPRISE;

        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
    }

    /**
     * Neither the template nor any string keeps an upsell in the base (§6.1).
     */
    public function test_the_base_carries_no_upgrade_prompt(): void {
        $this->assertFileDoesNotExist(__DIR__ . '/../../templates/edition_upgrade_prompt.mustache');
        $this->assertFalse(get_string_manager()->string_exists('upgrade_prompt_title', 'local_moodesk'));
        $this->assertStringNotContainsStringIgnoringCase('upgrade', get_string('featurenotavailable', 'local_moodesk', 'x'));
    }
}
