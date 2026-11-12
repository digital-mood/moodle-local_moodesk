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
 * Unit tests for the ticket detail's action bodies.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\controller;

use local_moodesk\domain\reply;
use local_moodesk\domain\ticket;
use local_moodesk\service\edition_service;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;
use local_moodesk\util\ajax_error;

/**
 * Guards the half of the controller both mounts share.
 *
 * view.php wraps these in a redirect and ajax.php wraps them in JSON, so what
 * they do — and what they refuse — has to be true before either wrapper is
 * involved. The drawer sends a real browser's POST at ajax.php, which is why
 * these tests build the POST the composer actually submits rather than calling
 * the services underneath it.
 *
 * @covers \local_moodesk\controller\ticket_page_handler
 */
final class ticket_page_handler_test extends \advanced_testcase {
    /** @var int Ticket under test. */
    private int $ticketid;

    /** @var \stdClass The user who raised it. */
    private \stdClass $requester;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$categoryid) {
            $categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid'     => null,
                'name'         => 'General',
                'sortorder'    => 0,
                'visible'      => 1,
                'timecreated'  => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();

        $data                = new \stdClass();
        $data->userid        = $this->requester->id;
        $data->subject       = 'Cannot access my course';
        $data->message       = 'Body.';
        $data->messageformat = FORMAT_PLAIN;

        $result         = ticket_service::create()->create_ticket($data, $this->requester->id);
        $this->ticketid = (int) $result['ticketid'];

        $this->resetDebugging();
    }

    protected function tearDown(): void {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        edition_service::set_edition_for_testing(null);
        parent::tearDown();
    }

    /**
     * Put the composer's POST into the request, exactly as a browser sends it.
     *
     * The hidden fields are moodleform's own: the form identifier it tracks
     * submissions with, the session key, and the type the reply toggle sets.
     *
     * @param string $message
     * @param int    $type
     */
    private function post_reply(string $message, int $type = reply::TYPE_PUBLIC_REPLY): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_qf__local_moodesk_form_reply_form' => '1',
            'sesskey'      => sesskey(),
            'ticketid'     => (string) $this->ticketid,
            'replytype'    => (string) $type,
            'message'      => $message,
            'submitbutton' => 'Send reply',
        ];
    }

    /**
     * The handler as the endpoints build it, for an agent who can do everything.
     *
     * @return ticket_page_handler
     */
    private function handler_for_admin(): ticket_page_handler {
        return new ticket_page_handler(
            $this->ticketid,
            (int) get_admin()->id,
            ticket_service::create(),
            reply_service::create(),
            true,
            true
        );
    }

    public function test_a_public_reply_is_saved_and_reported(): void {
        $this->setAdminUser();
        $this->post_reply('The key was rotated this morning.');

        $result = $this->handler_for_admin()->submit_reply();

        $this->assertNotNull($result, 'The composer POST was refused by the form.');
        $this->assertSame(reply::TYPE_PUBLIC_REPLY, $result['replytype']);
        $this->assertNotEmpty($result['message']);

        $thread = reply_service::create()->get_thread($this->ticketid, (int) get_admin()->id);
        $bodies = array_column($thread, 'message');
        $this->assertContains('The key was rotated this morning.', $bodies);
    }

    public function test_an_internal_note_is_saved_as_a_note(): void {
        $this->setAdminUser();
        $this->post_reply('Escalating to the enrolment team.', reply::TYPE_INTERNAL_NOTE);

        $result = $this->handler_for_admin()->submit_reply();

        $this->assertNotNull($result);
        $this->assertSame(reply::TYPE_INTERNAL_NOTE, $result['replytype']);
    }

    public function test_an_empty_message_saves_nothing(): void {
        $this->setAdminUser();
        $this->post_reply('   ');

        $this->assertNull($this->handler_for_admin()->submit_reply());
    }

    public function test_nothing_submitted_saves_nothing(): void {
        $this->setAdminUser();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];

        $this->assertNull($this->handler_for_admin()->submit_reply());
    }
}
