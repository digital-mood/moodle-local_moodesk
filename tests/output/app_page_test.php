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
 * Unit tests for the app_page helper.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

/**
 * Verifies app_page always renders product screens on the embedded pagelayout
 * (the application shell is the only mode after F8 retired the legacy chrome).
 *
 * @covers \local_moodesk\output\app_page
 */
final class app_page_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Build a fresh page bound to the system context.
     *
     * @return \moodle_page
     */
    private function fresh_page(): \moodle_page {
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_url('/local/moodesk/dashboard.php');
        return $page;
    }

    public function test_uses_embedded_layout(): void {
        $page = $this->fresh_page();
        new app_page($page, 'dashboard');
        $this->assertSame('embedded', $page->pagelayout);
    }

    public function test_page_key_is_retained(): void {
        $page = $this->fresh_page();
        $apppage = new app_page($page, 'dashboard');
        $this->assertSame('dashboard', $apppage->get_page_key());
    }
}
