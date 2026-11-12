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
 * Unit tests for the reply composer's draft attachment service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

/**
 * Guards what the composer's attachment chips are drawn from.
 *
 * The chips are never the source of truth — after every upload and delete the
 * composer re-reads the draft through this service — so these tests are about
 * the two things that would make that re-read a lie: reporting files that are
 * not there, and reaching a draft that is not the caller's
 * (ADR: docs/reply-composer-attachments.md).
 *
 * @covers \local_moodesk\service\draft_attachment_service
 */
final class draft_attachment_service_test extends \advanced_testcase {
    /** @var draft_attachment_service The service under test. */
    private draft_attachment_service $service;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->service = draft_attachment_service::create();
    }

    /**
     * Put a file into the current user's draft area.
     *
     * @param int    $draftitemid Draft area to write into.
     * @param string $filename    Name to store it under.
     * @return void
     */
    private function add_draft_file(int $draftitemid, string $filename): void {
        global $USER;

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], 'contents of ' . $filename);
    }

    public function test_an_untouched_draft_holds_nothing(): void {
        $this->setAdminUser();

        $this->assertSame([], $this->service->list_files(file_get_unused_draft_itemid()));
    }

    public function test_it_reports_what_the_draft_holds(): void {
        $this->setAdminUser();

        $draftitemid = file_get_unused_draft_itemid();
        $this->add_draft_file($draftitemid, 'report.pdf');
        $this->add_draft_file($draftitemid, 'screenshot.png');

        $files = $this->service->list_files($draftitemid);

        $this->assertCount(2, $files);
        $this->assertSame(['report.pdf', 'screenshot.png'], array_column($files, 'filename'));

        // The chips need a size to show and a URL to preview before sending.
        $this->assertNotEmpty($files[0]['sizeformatted']);
        $this->assertStringContainsString('report.pdf', $files[0]['url']);
    }

    public function test_deleting_removes_only_the_named_file(): void {
        $this->setAdminUser();

        $draftitemid = file_get_unused_draft_itemid();
        $this->add_draft_file($draftitemid, 'keep.txt');
        $this->add_draft_file($draftitemid, 'drop.txt');

        $this->assertTrue($this->service->delete_file($draftitemid, '/', 'drop.txt'));
        $this->assertSame(['keep.txt'], array_column($this->service->list_files($draftitemid), 'filename'));
    }

    public function test_deleting_what_is_not_there_is_not_an_error(): void {
        $this->setAdminUser();

        $draftitemid = file_get_unused_draft_itemid();
        $this->add_draft_file($draftitemid, 'only.txt');

        // A chip clicked twice, or a delete racing a re-sync, asks for a state
        // the draft is already in. That is not a failure.
        $this->assertFalse($this->service->delete_file($draftitemid, '/', 'ghost.txt'));
        $this->assertCount(1, $this->service->list_files($draftitemid));
    }

    public function test_one_user_cannot_reach_another_users_draft(): void {
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->setUser($owner);
        $draftitemid = file_get_unused_draft_itemid();
        $this->add_draft_file($draftitemid, 'private.txt');

        // Same item id, different user: the area resolves against whoever is
        // asking, so there is nothing there to read or to delete. This is what
        // makes the endpoints safe without a separate ownership check.
        $this->setUser($other);
        $this->assertSame([], $this->service->list_files($draftitemid));
        $this->assertFalse($this->service->delete_file($draftitemid, '/', 'private.txt'));

        $this->setUser($owner);
        $this->assertCount(1, $this->service->list_files($draftitemid));
    }
}
