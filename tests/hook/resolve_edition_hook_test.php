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
 * Tests for the resolve_edition hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\service\edition_service;

/**
 * The edition the base resolves is offered to plugins, and only that.
 *
 * The CI legs' moodesk_LOCAL_EDITION constant is masked, so no seam decides on
 * any leg and the hook is dispatched.
 *
 * @covers \local_moodesk\hook\resolve_edition
 * @covers \local_moodesk\service\edition_service
 */
final class resolve_edition_hook_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // A suite that ran earlier in the process may have left a forced edition.
        edition_service::set_edition_for_testing(null);
        edition_service::set_dev_override_for_testing(false);
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        edition_service::set_edition_for_testing(null);
        edition_service::set_dev_override_for_testing(null);
        parent::tearDown();
    }

    /**
     * Point the hook at a callback and drop the memoised resolution.
     *
     * @param callable $callback
     */
    private function redirect(callable $callback): void {
        $this->redirectHook(resolve_edition::class, $callback);
        edition_service::set_edition_for_testing(null);
    }

    public function test_without_a_callback_the_base_answer_stands(): void {
        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
        $this->assertSame('fallback', edition_service::edition_source()->source);
    }

    public function test_the_hook_receives_the_base_resolution(): void {
        $seen = null;
        $this->redirect(function (resolve_edition $hook) use (&$seen): void {
            $seen = [$hook->get_edition(), $hook->get_source()];
        });

        $this->assertSame(edition_service::EDITION_STARTER, edition_service::get_edition());
        $this->assertSame([edition_service::EDITION_STARTER, 'fallback'], $seen);
        $this->assertSame('fallback', edition_service::edition_source()->source);
    }

    public function test_a_callback_replaces_edition_and_source(): void {
        $this->redirect(function (resolve_edition $hook): void {
            $hook->set_edition(edition_service::EDITION_PRO, 'local_moodeskpro');
        });

        $this->assertSame(edition_service::EDITION_PRO, edition_service::get_edition());
        $source = edition_service::edition_source();
        $this->assertSame('local_moodeskpro', $source->source);
        $this->assertNull($source->requested);
        $this->assertFalse($source->lowered);
        $this->assertFalse($source->ignored);
        $this->assertTrue(edition_service::has_feature('automation'));
    }

    public function test_the_development_override_can_only_lower_what_a_plugin_answers(): void {
        $this->redirect(function (resolve_edition $hook): void {
            $hook->set_edition(edition_service::EDITION_ENTERPRISE, 'licence');
        });

        edition_service::set_dev_override_for_testing(edition_service::EDITION_PRO);
        $this->assertSame(edition_service::EDITION_PRO, edition_service::get_edition());
        $this->assertTrue(edition_service::edition_source()->lowered);
    }

    public function test_the_development_override_cannot_raise_what_a_plugin_answers(): void {
        $this->redirect(function (resolve_edition $hook): void {
            $hook->set_edition(edition_service::EDITION_PRO, 'licence');
        });

        edition_service::set_dev_override_for_testing(edition_service::EDITION_ENTERPRISE);
        $this->assertSame(edition_service::EDITION_PRO, edition_service::get_edition());
        $this->assertTrue(edition_service::edition_source()->ignored);
    }

    public function test_invalidating_the_resolution_asks_again(): void {
        $calls = 0;
        $this->redirect(function () use (&$calls): void {
            $calls++;
        });

        edition_service::get_edition();
        edition_service::invalidate_resolution();
        edition_service::get_edition();

        $this->assertSame(2, $calls);
    }

    public function test_the_hook_runs_once_per_resolution(): void {
        $calls = 0;
        $this->redirect(function () use (&$calls): void {
            $calls++;
        });

        edition_service::get_edition();
        edition_service::get_edition();
        edition_service::has_feature('automation');

        $this->assertSame(1, $calls);
    }

    public function test_a_test_seam_is_not_offered_to_the_hook(): void {
        $calls = 0;
        $this->redirectHook(resolve_edition::class, function () use (&$calls): void {
            $calls++;
        });
        edition_service::set_edition_for_testing(edition_service::EDITION_ENTERPRISE);

        $this->assertSame(edition_service::EDITION_ENTERPRISE, edition_service::get_edition());
        $this->assertSame(0, $calls);
    }

    public function test_set_edition_rejects_an_unknown_edition(): void {
        $hook = new resolve_edition(edition_service::EDITION_STARTER, 'fallback');
        $this->expectException(\coding_exception::class);
        $hook->set_edition('platinum', 'local_moodeskpro');
    }

    public function test_set_edition_requires_a_source(): void {
        $hook = new resolve_edition(edition_service::EDITION_STARTER, 'fallback');
        $this->expectException(\coding_exception::class);
        $hook->set_edition(edition_service::EDITION_PRO, '');
    }
}
