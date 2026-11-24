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
 * Tests for ajax_error.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\util;

use local_moodesk\domain\incident;
use local_moodesk\service\incident_service;

/**
 * What a failed AJAX action tells the browser, and what it keeps (audit H7).
 *
 * @covers \local_moodesk\util\ajax_error
 */
final class ajax_error_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        incident_service::reset_deferred_for_testing();
        $this->setAdminUser();
    }

    protected function tearDown(): void {
        incident_service::reset_deferred_for_testing();
        parent::tearDown();
    }

    /**
     * A product rule speaks for itself: its localised message is the answer.
     */
    public function test_a_moodle_exception_keeps_its_message(): void {
        global $DB;

        $payload = ajax_error::payload(new \moodle_exception('agents_has_open_tickets', 'local_moodesk'), 'revoke');

        $this->assertFalse($payload['success']);
        $this->assertSame(get_string('agents_has_open_tickets', 'local_moodesk'), $payload['error']);

        incident_service::flush_deferred();
        $this->assertSame(0, $DB->count_records('local_moodesk_incident'));
    }

    /**
     * Anything else is code breaking: the browser gets one generic sentence
     * and the detail — message, class, file — becomes an incident.
     */
    public function test_an_unexpected_throwable_is_generic_on_the_wire_and_recorded(): void {
        global $DB;

        $detail  = 'SQLSTATE[42S02]: Base table not found in /var/www/secret/lib.php';
        $payload = ajax_error::payload(new \RuntimeException($detail), 'save_filter_view');

        $this->assertFalse($payload['success']);
        $this->assertSame(get_string('ajax_unexpected_error', 'local_moodesk'), $payload['error']);
        $this->assertStringNotContainsString('42S02', $payload['error']);
        $this->assertStringNotContainsString('/var/www', $payload['error']);

        incident_service::flush_deferred();
        $row = $DB->get_record('local_moodesk_incident', ['code' => 'ajax.save_filter_view.unexpected']);
        $this->assertNotEmpty($row);
        $this->assertSame(incident::SOURCE_UI, $row->source);
        $this->assertSame(incident::SEVERITY_ERROR, $row->severity);
        $this->assertStringContainsString('RuntimeException', $row->message);
        $this->assertStringContainsString($detail, $row->message);
    }

    /**
     * Each reason ajax.php turns a request away for, and the string it is shown as.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function refusal_provider(): array {
        return [
            'not a POST' => [ajax_error::REFUSED_POST_REQUIRED, 'ajax_post_required', 'local_moodesk'],
            'expired session' => [ajax_error::REFUSED_SESSKEY, 'invalidsesskey', 'error'],
            'no permission' => [ajax_error::REFUSED_PERMISSION, 'ajax_permission_denied', 'local_moodesk'],
            'saved view not JSON' => [ajax_error::REFUSED_INVALID_JSON, 'ajax_invalid_json', 'local_moodesk'],
            'unknown action' => [ajax_error::REFUSED_UNKNOWN_ACTION, 'ajax_unknown_action', 'local_moodesk'],
        ];
    }

    /**
     * A refusal is a failure whose message is a language string, never a literal.
     *
     * @dataProvider refusal_provider
     * @param string $reason
     * @param string $identifier
     * @param string $component
     */
    public function test_a_refusal_is_a_localised_failure(string $reason, string $identifier, string $component): void {
        $this->assertTrue(get_string_manager()->string_exists($identifier, $component));

        $payload = ajax_error::refused($reason);

        $this->assertSame(['success' => false, 'error' => get_string($identifier, $component)], $payload);
    }

    /**
     * A reason that is not one of the constants is a programming error.
     */
    public function test_an_unknown_reason_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        ajax_error::refused('Permission denied');
    }

    /**
     * The Marketplace review found fixed English text in ajax.php: every answer
     * it writes goes through refused(), payload() or get_string().
     */
    public function test_ajax_php_holds_no_literal_error(): void {
        $source = file_get_contents(__DIR__ . '/../../ajax.php');

        $this->assertDoesNotMatchRegularExpression('/[\'"]error[\'"]\s*=>\s*[\'"]/', $source);
        $this->assertSame(12, substr_count($source, 'ajax_error::refused(ajax_error::REFUSED_'));
    }

    /**
     * The action name is sanitised into the incident code, never trusted.
     */
    public function test_the_action_is_sanitised_in_the_code(): void {
        global $DB;

        ajax_error::payload(new \TypeError('boom'), 'Weird Action/../x');
        incident_service::flush_deferred();

        $this->assertTrue($DB->record_exists('local_moodesk_incident', ['code' => 'ajax.weird_action____x.unexpected']));
    }
}
