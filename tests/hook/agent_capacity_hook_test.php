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
 * Tests for the agent_capacity hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\service\agent_service;
use local_moodesk\service\edition_service;
use local_moodesk\service\entitlement_service;

/**
 * The capacity shown and the capacity enforced both pass through the hook.
 *
 * Runs without a licence (Starter, 2 agents) with the CI legs' edition
 * constant masked, so the base figure is the same on every leg.
 *
 * @covers \local_moodesk\hook\agent_capacity
 * @covers \local_moodesk\service\entitlement_service
 * @covers \local_moodesk\service\agent_service::grant_agent
 */
final class agent_capacity_hook_test extends \advanced_testcase {
    /** @var \stdClass Holds :manageagents via the manager archetype. */
    private \stdClass $manager;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        // A suite that ran earlier in the process may have left a forced edition.
        edition_service::set_edition_for_testing(null);
        edition_service::set_dev_override_for_testing(false);

        $this->manager = $this->getDataGenerator()->create_user();
        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $this->manager->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        edition_service::set_dev_override_for_testing(null);
        parent::tearDown();
    }

    /**
     * Grant a fresh user through the service, the gated path.
     *
     * @return \stdClass
     */
    private function grant_new(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        agent_service::create()->grant_agent((int) $user->id, (int) $this->manager->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Take every callback off the hook: local_moodeskpro has one on the legs that
     * install it, and with it nothing is refused.
     */
    private function without_callbacks(): void {
        $this->redirectHook(agent_capacity::class, static function (): void {
        });
    }

    public function test_without_a_callback_the_base_capacity_stands(): void {
        $this->without_callbacks();
        $this->assertSame(2, entitlement_service::agent_limit());
        $this->assertTrue(entitlement_service::can_add_agent(1));
        $this->assertFalse(entitlement_service::can_add_agent(2));
        $this->assertSame(2, entitlement_service::agent_entitlement()->limit);
    }

    public function test_the_hook_receives_the_edition_and_the_base_limit(): void {
        $seen = null;
        $this->redirectHook(agent_capacity::class, function (agent_capacity $hook) use (&$seen): void {
            $seen = [$hook->edition, $hook->get_limit(), $hook->get_refuse()];
        });

        entitlement_service::agent_limit();

        $this->assertSame([edition_service::EDITION_STARTER, 2, true], $seen);
    }

    public function test_a_callback_limit_is_both_shown_and_enforced(): void {
        $this->redirectHook(agent_capacity::class, function (agent_capacity $hook): void {
            $hook->set_limit(3);
        });

        $this->assertSame(3, entitlement_service::agent_limit());
        $this->assertSame(3, entitlement_service::agent_entitlement()->limit);
        $this->assertTrue(entitlement_service::can_add_agent(2));
        $this->assertFalse(entitlement_service::can_add_agent(3));
    }

    public function test_a_callback_that_does_not_refuse_lets_grants_through(): void {
        $this->grant_new();
        $this->grant_new();

        $this->redirectHook(agent_capacity::class, function (agent_capacity $hook): void {
            $hook->set_refuse(false);
        });
        $third = $this->grant_new();

        $this->assertTrue(agent_service::create()->is_agent((int) $third->id));
        $this->assertSame(2, entitlement_service::agent_limit());
        $this->assertTrue(entitlement_service::agent_entitlement()->exceeded);
    }

    public function test_without_a_callback_the_third_grant_is_refused(): void {
        $this->without_callbacks();
        $this->grant_new();
        $this->grant_new();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('agents_limit_reached', 'local_moodesk', (object) [
            'active' => 2,
            'capacity' => 2,
            'edition' => get_string('edition_starter', 'local_moodesk'),
        ]));
        $this->grant_new();
    }

    public function test_a_negative_limit_is_rejected(): void {
        $hook = new agent_capacity(edition_service::EDITION_STARTER, 2);
        $this->expectException(\coding_exception::class);
        $hook->set_limit(-1);
    }
}
