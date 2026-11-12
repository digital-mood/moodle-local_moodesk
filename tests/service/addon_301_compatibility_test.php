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
 * The base 3.0.2 with local_moodeskpro 3.0.1.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\domain\incident;
use local_moodesk\hook\agent_groups;
use local_moodesk\hook\health_sources;
use local_moodesk\output\health_page;
use local_moodesk\task\dispatch_incident_alerts;

/**
 * What the base does with the calls local_moodeskpro 3.0.1 makes.
 *
 * The base updates from the Marketplace and the add-on separately, so a site
 * runs the base 3.0.2 with the add-on 3.0.1 for a while, and the add-on's
 * dependency admits it. These cases make the add-on 3.0.1's calls the way its
 * code makes them: it does not know the health_sources hook, records under the
 * deprecated incident::SOURCE_* constants, calls send() with four arguments,
 * and reports groups without naming them. None of it may throw.
 *
 * @covers \local_moodesk\service\incident_service
 * @covers \local_moodesk\service\incident_source_registry
 * @covers \local_moodesk\service\email_alert_service
 * @covers \local_moodesk\service\agent_service
 */
final class addon_301_compatibility_test extends \advanced_testcase {
    /**
     * No plugin declares a source: the add-on 3.0.1 predates the hook.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        incident_service::reset_deferred_for_testing();
        incident_source_registry::reset_for_testing();
        $this->redirectHook(health_sources::class, static function (health_sources $hook): void {
        });
    }

    /**
     * Static state outlives the per-test database reset.
     */
    protected function tearDown(): void {
        incident_service::reset_deferred_for_testing();
        incident_source_registry::reset_for_testing();
        parent::tearDown();
    }

    /**
     * The three operator alerts of the add-on 3.0.1 call send() with no
     * provider. It answers false, says why to a developer, and sends nothing.
     */
    public function test_send_without_a_provider_sends_nothing_and_does_not_throw(): void {
        $sink    = $this->redirectMessages();
        $service = new class extends email_alert_service {
            /**
             * Call send() the way local_moodeskpro 3.0.1 does.
             *
             * @param \stdClass $to
             * @return bool
             */
            public function send_as_301(\stdClass $to): bool {
                return $this->send(\core_user::get_noreply_user(), $to, 'Subject', 'Body');
            }
        };

        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse($service->send_as_301($user));
        $this->assertDebuggingCalled();
        $this->assertSame([], $sink->get_messages());
    }

    /**
     * The merged-ticket bounce of the add-on 3.0.1 passes the base's own
     * provider, and still goes out.
     */
    public function test_send_with_the_base_provider_still_sends(): void {
        $sink    = $this->redirectMessages();
        $service = new class extends email_alert_service {
            /**
             * Call send() the way notify_reply_to_merged_ticket does.
             *
             * @param \stdClass $to
             * @return bool
             */
            public function send_bounce(\stdClass $to): bool {
                return $this->send(\core_user::get_noreply_user(), $to, 'Subject', 'Body', 'ticket_notification');
            }
        };

        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue($service->send_bounce($user));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('local_moodesk', $messages[0]->component);
        $this->assertSame('ticket_notification', $messages[0]->eventtype);
    }

    /**
     * Outside the strict mode PHPUnit runs in, an incident under a source
     * nobody declares is recorded, and a developer is told.
     */
    public function test_tolerant_record_stores_both_add_on_sources(): void {
        global $DB;

        incident_source_registry::set_strict_for_testing(false);
        $svc = incident_service::create();

        // The add-on 3.0.1 still uses the constants, deprecated since 3.0.2.
        foreach ([incident::SOURCE_WEBHOOK, incident::SOURCE_EMAIL_INGESTION] as $source) {
            $id = $svc->record($source, $source . '.probe.failed', incident::SEVERITY_CRITICAL, 'down');
            $this->assertGreaterThan(0, $id);
            $this->assertDebuggingCalled();
            $this->assertSame($source, $DB->get_field('local_moodesk_incident', 'source', ['id' => $id]));
        }
    }

    /**
     * The same, inside a transaction someone else holds, as an observer does.
     */
    public function test_tolerant_record_works_inside_a_delegated_transaction(): void {
        global $DB;

        incident_source_registry::set_strict_for_testing(false);

        $transaction = $DB->start_delegated_transaction();
        $id = incident_service::create()->record(incident::SOURCE_WEBHOOK, 'webhook.probe.failed', incident::SEVERITY_ERROR, 'x');
        $this->assertDebuggingCalled();
        $transaction->allow_commit();
        incident_service::flush_deferred();

        $this->assertGreaterThan(0, $id);
        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['id' => $id]));
    }

    /**
     * Those incidents are neither shown on System Health nor alerted, and no
     * label string is asked for: the base 3.0.2 no longer has them, and the
     * add-on 3.0.1 never did.
     */
    public function test_undeclared_incidents_are_not_shown_or_alerted(): void {
        global $DB;

        incident_source_registry::set_strict_for_testing(false);
        $svc = incident_service::create();
        $ids = [];
        foreach ([incident::SOURCE_WEBHOOK, incident::SOURCE_EMAIL_INGESTION] as $source) {
            $ids[] = $svc->record($source, $source . '.probe.failed', incident::SEVERITY_CRITICAL, 'down');
            $this->assertDebuggingCalled();
        }
        incident_service::flush_deferred();

        $context = (new health_page())->export('/local/moodesk/health.php');
        $this->assertSame([], $context['incidents']);
        $this->assertSame(health_page::STATE_HEALTHY, $context['state']);
        $this->assertSame(incident::base_sources(), array_column($context['sources'], 'key'));
        $this->assertSame([], $svc->pending_alerts());

        $sink = $this->redirectMessages();
        (new dispatch_incident_alerts())->execute();
        $this->assertSame([], $sink->get_messages());
        foreach ($ids as $id) {
            $this->assertNull($DB->get_field('local_moodesk_incident', 'timelastalerted', ['id' => $id]));
        }
    }

    /**
     * Under PHPUnit, by default, the same call is a typo and fails loudly,
     * even though record() swallows every other failure.
     */
    public function test_strict_record_throws_for_an_undeclared_source(): void {
        $this->expectException(\coding_exception::class);
        incident_service::create()->record(incident::SOURCE_WEBHOOK, 'webhook.probe.failed', incident::SEVERITY_ERROR, 'x');
    }

    /**
     * The add-on 3.0.1 reports teams without naming them: the base's texts
     * speak of departments only, which is the accepted cost.
     */
    public function test_groups_without_a_type_keep_the_departments_only_texts(): void {
        $this->redirectHook(agent_groups::class, static function (agent_groups $hook): void {
            foreach ($hook->userids as $userid) {
                $hook->add_groups($userid, ['Tier 1']);
            }
        });

        $user = $this->getDataGenerator()->create_user();
        $type = agent_service::create()->group_type([(int) $user->id]);

        $this->assertNull($type);
        $this->assertSame(
            get_string('agents_offboard_confirm_body', 'local_moodesk'),
            agent_service::membership_text('agents_offboard_confirm_body', $type)
        );
    }
}
