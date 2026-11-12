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
 * Tests for the ticket_list_filters hook.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\output\ticket_list_query;
use local_moodesk\repository\ticket_repository;
use local_moodesk\service\ticket_service;

/**
 * A plugin filter is a parameter the list keeps, a control and an EXISTS condition.
 *
 * @covers \local_moodesk\hook\ticket_list_filters
 * @covers \local_moodesk\hook\filter_extension
 * @covers \local_moodesk\output\ticket_list_query
 * @covers \local_moodesk\repository\ticket_repository
 */
final class ticket_list_filters_hook_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * A plugin that filters on the subject through a parameter `plgsubject`.
     */
    private function redirect_subject_filter(): void {
        $this->redirectHook(ticket_list_filters::class, function (ticket_list_filters $hook): void {
            switch ($hook->get_phase()) {
                case ticket_list_filters::PHASE_PARAMS:
                    $hook->declare_param('plgsubject', PARAM_TEXT, '');
                    break;
                case ticket_list_filters::PHASE_OPTIONS:
                    $hook->add_select('plgsubject', 'Subject', ['' => 'Any', 'Main' => 'Main'], $hook->get_value('plgsubject'));
                    break;
                case ticket_list_filters::PHASE_SQL:
                    $name = $hook->param('subject');
                    $hook->add_condition(
                        "EXISTS (SELECT 1 FROM {local_moodesk_ticket} x WHERE x.id = t.id AND x.subject = :{$name})",
                        [$name => $hook->get_value('plgsubject')]
                    );
                    break;
            }
        });
    }

    public function test_without_a_callback_the_query_carries_no_plugin_filter(): void {
        $query = ticket_list_query::from_query_string('plgsubject=Main', true);
        $this->assertSame([], $query->get_plugin_values());
        $this->assertArrayNotHasKey('plugin', $query->to_filters());
        $this->assertArrayNotHasKey('plgsubject', $query->to_active_params());
    }

    public function test_a_declared_parameter_travels_with_the_list(): void {
        $this->redirect_subject_filter();

        $query = ticket_list_query::from_query_string('plgsubject=Main&priority=2', true);

        $this->assertSame(['plgsubject' => 'Main'], $query->get_plugin_values());
        $this->assertSame(['plgsubject' => 'Main'], $query->to_filters()['plugin']);
        $this->assertSame('Main', $query->to_active_params()['plgsubject']);
        $this->assertStringContainsString('plgsubject=Main', $query->to_url()->out(false));
        $this->assertSame(1, $query->count_secondary_filters());
    }

    public function test_the_default_value_is_no_filter(): void {
        $this->redirect_subject_filter();
        $this->assertSame([], ticket_list_query::from_query_string('plgsubject=', true)->get_plugin_values());
    }

    public function test_requesters_get_no_plugin_filters(): void {
        $this->redirect_subject_filter();
        $this->assertSame([], ticket_list_query::from_query_string('plgsubject=Main', false)->get_plugin_values());
    }

    public function test_the_condition_narrows_the_query(): void {
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
        $repo = new ticket_repository();
        $this->assertSame(2, $repo->count_all([]));

        $this->redirect_subject_filter();
        $this->assertSame(1, $repo->count_all(['plugin' => ['plgsubject' => 'Main']]));
        $this->assertSame(2, $repo->count_all([]));
    }

    public function test_options_build_the_select(): void {
        $this->redirect_subject_filter();
        $hook = ticket_list_filters::for_options(['plgsubject' => 'Main']);
        \core\di::get(\core\hook\manager::class)->dispatch($hook);

        $selects = $hook->get_selects();
        $this->assertCount(1, $selects);
        $this->assertSame('plgsubject', $selects[0]['name']);
        $this->assertSame([false, true], array_column($selects[0]['options'], 'isselected'));
    }

    public function test_a_reserved_name_is_rejected(): void {
        $hook = ticket_list_filters::for_params(ticket_list_query::RESERVED_PARAMS);
        $this->expectException(\coding_exception::class);
        $hook->declare_param('sla', PARAM_INT, 0);
    }

    public function test_a_condition_that_is_not_exists_is_rejected(): void {
        $hook = ticket_list_filters::for_sql(['x' => 1], 'tl');
        $this->expectException(\coding_exception::class);
        $hook->add_condition('t.status = 3');
    }

    public function test_a_parameter_not_named_through_param_is_rejected(): void {
        $hook = ticket_list_filters::for_sql(['x' => 1], 'tl');
        $this->expectException(\coding_exception::class);
        $hook->add_condition('EXISTS (SELECT 1 FROM {user} u WHERE u.id = :fteamid)', ['fteamid' => 3]);
    }
}
