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
 * Access control for the System Health page (OBS-04).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

use local_moodesk\service\navigation_service;

/**
 * The health page is gated twice, and the two gates are different authorities.
 *
 * Seeing that ingestion has stopped is not the same as declaring it handled: a
 * support lead should be able to look without being handed the settings tree.
 * So viewing needs :viewhealth and acting needs :managesettings, and neither
 * substitutes for the other.
 *
 * Like {@see page_gate_test}, the page file is inspected rather than executed:
 * proving the gate is present costs a grep, while proving it fires costs a full
 * HTTP request setup that buys little more.
 *
 * @covers \local_moodesk\service\navigation_service
 */
final class health_gate_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/../..';

    /**
     * Capability definitions and navigation are read per case.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The page requires :viewhealth before it renders anything.
     */
    public function test_the_page_requires_viewhealth(): void {
        $source = file_get_contents(self::PLUGIN_DIR . '/health.php');

        $this->assertNotFalse($source, 'health.php is missing.');
        $this->assertStringContainsString(
            "require_capability('local/moodesk:viewhealth', \$context);",
            $source,
            'health.php must refuse a viewer without local/moodesk:viewhealth.'
        );
    }

    /**
     * Acting on an incident re-checks :managesettings on the POST itself.
     *
     * The template omits the buttons without the capability, but a form post
     * does not care what was rendered — hiding a control is presentation, not
     * access control.
     */
    public function test_acting_requires_managesettings_on_the_post(): void {
        $source = file_get_contents(self::PLUGIN_DIR . '/health.php');

        $this->assertStringContainsString(
            "require_capability('local/moodesk:managesettings', \$context);",
            $source,
            'health.php must re-check the manage capability when handling an action.'
        );
        $this->assertStringContainsString(
            'confirm_sesskey()',
            $source,
            'health.php must require a valid sesskey on state-changing requests.'
        );
    }

    /**
     * The capability exists and is defined at system level, read type — it
     * grants sight of operational state, not the power to change anything.
     */
    public function test_the_capability_is_declared_read_only_at_system_level(): void {
        $capabilities = [];
        require(self::PLUGIN_DIR . '/db/access.php');

        $this->assertArrayHasKey('local/moodesk:viewhealth', $capabilities);

        $definition = $capabilities['local/moodesk:viewhealth'];
        $this->assertSame('read', $definition['captype']);
        $this->assertSame(CONTEXT_SYSTEM, $definition['contextlevel']);
        $this->assertSame(CAP_ALLOW, $definition['archetypes']['manager'] ?? null);
    }

    /**
     * The navigation entry follows the capability: someone who cannot open the
     * page is not shown a link to it.
     */
    public function test_the_nav_entry_follows_the_capability(): void {
        $context = \context_system::instance();

        $this->setAdminUser();
        $keys = array_map(
            static fn($item) => $item->key,
            navigation_service::get_catalogue($context)
        );
        $this->assertContains('health', $keys);

        // A plain user holds :viewown (the 'user' archetype) but not :viewhealth.
        $this->setUser($this->getDataGenerator()->create_user());
        $keys = array_map(
            static fn($item) => $item->key,
            navigation_service::get_catalogue($context)
        );
        $this->assertNotContains('health', $keys);
    }
}
