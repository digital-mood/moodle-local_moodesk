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
 * Tests for the shell_notices hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\output\app_shell;

/**
 * Notices added by plugins are rendered above the content, and nothing else changes.
 *
 * @covers \local_moodesk\hook\shell_notices
 * @covers \local_moodesk\output\app_shell
 */
final class shell_notices_hook_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * The core renderer used to export and render the shell.
     *
     * @return \renderer_base
     */
    private function renderer(): \renderer_base {
        global $PAGE;
        return $PAGE->get_renderer('core');
    }

    public function test_without_a_notice_the_shell_has_no_notice_slot(): void {
        $output = $this->renderer();
        $ctx = (new app_shell('<p>body</p>', 'dashboard'))->export_for_template($output);

        $this->assertSame([], $ctx['notices']);
        $this->assertFalse($ctx['hasnotices']);
        $html = $output->render_from_template('local_moodesk/shell/app_shell', $ctx);
        $this->assertStringNotContainsString('moodesk-shell-notices', $html);
    }

    public function test_notices_are_rendered_in_order_above_the_content(): void {
        $seenkey = null;
        $this->redirectHook(shell_notices::class, function (shell_notices $hook) use (&$seenkey): void {
            $seenkey = $hook->pagekey;
            $hook->add_notice('core/notification_warning', ['message' => 'First notice']);
            $hook->add_notice('core/notification_info', ['message' => 'Second notice']);
        });

        $output = $this->renderer();
        $ctx = (new app_shell('<p>body</p>', 'dashboard'))->export_for_template($output);

        $this->assertSame('dashboard', $seenkey);
        $this->assertTrue($ctx['hasnotices']);
        $this->assertCount(2, $ctx['notices']);
        $this->assertStringContainsString('First notice', $ctx['notices'][0]['html']);
        $this->assertStringContainsString('Second notice', $ctx['notices'][1]['html']);

        $html = $output->render_from_template('local_moodesk/shell/app_shell', $ctx);
        $slot = strpos($html, 'moodesk-shell-notices');
        $this->assertNotFalse($slot);
        $this->assertLessThan(strpos($html, 'Second notice'), strpos($html, 'First notice'));
        $this->assertLessThan(strpos($html, '<p>body</p>'), $slot);
    }

    public function test_a_notice_needs_a_template(): void {
        $hook = new shell_notices('dashboard');
        $this->expectException(\coding_exception::class);
        $hook->add_notice('');
    }
}
