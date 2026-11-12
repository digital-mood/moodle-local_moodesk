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
 * Tests for the dashboard_filters hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\domain\dashboard_filter;
use local_moodesk\repository\dashboard_repository;
use local_moodesk\service\ticket_service;

/**
 * Plugin criteria travel in the dashboard filter and narrow every scoped metric.
 *
 * @covers \local_moodesk\hook\dashboard_filters
 * @covers \local_moodesk\domain\dashboard_filter
 * @covers \local_moodesk\repository\dashboard_repository
 */
final class dashboard_filters_hook_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    public function test_criteria_travel_with_the_filter(): void {
        $filter = dashboard_filter::from_days(30);
        $this->assertSame([], $filter->get_plugin_criteria());
        $this->assertFalse($filter->has_scope());

        $scoped = $filter->with_plugin_criteria(['plgsubject' => 'Main']);

        $this->assertTrue($scoped->has_scope());
        $this->assertSame(['plgsubject' => 'Main'], $scoped->previous_period()->get_plugin_criteria());
        $this->assertSame(['plgsubject' => 'Main'], $scoped->with_requester(5)->get_plugin_criteria());
        $this->assertSame([], $filter->get_plugin_criteria(), 'The original filter is unchanged.');
    }

    public function test_the_condition_narrows_a_scoped_metric(): void {
        global $DB;
        $this->setAdminUser();
        $categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$categoryid) {
            $categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid' => null, 'name' => 'General', 'sortorder' => 0, 'visible' => 1,
                'timecreated' => time(), 'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');
        $requester = $this->getDataGenerator()->create_user();
        foreach (['Main', 'Other'] as $subject) {
            ticket_service::create()->create_ticket((object) [
                'userid' => $requester->id, 'subject' => $subject, 'message' => 'Body.', 'messageformat' => FORMAT_PLAIN,
            ], $requester->id);
        }
        $this->resetDebugging();

        $prefixes = [];
        $this->redirectHook(dashboard_filters::class, function (dashboard_filters $hook) use (&$prefixes): void {
            if ($hook->get_phase() !== dashboard_filters::PHASE_SQL) {
                return;
            }
            $name = $hook->param('subject');
            $prefixes[] = $name;
            $hook->add_condition(
                "EXISTS (SELECT 1 FROM {local_moodesk_ticket} x WHERE x.id = t.id AND x.subject = :{$name})",
                [$name => $hook->get_value('plgsubject')]
            );
        });

        $repo = new dashboard_repository();
        $filter = dashboard_filter::from_days(30);
        $this->assertSame(2, $repo->count_active($filter));
        $this->assertSame([], $prefixes, 'No plugin criteria, no dispatch.');

        $this->assertSame(1, $repo->count_active($filter->with_plugin_criteria(['plgsubject' => 'Main'])));
        $this->assertNotEmpty($prefixes);
    }
}
