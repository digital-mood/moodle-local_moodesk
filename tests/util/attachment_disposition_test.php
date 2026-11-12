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
 * Unit tests for the attachment disposition rule.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\util;

/**
 * Tests for attachment_disposition.
 *
 * Security regression cover: a ticket attachment is chosen by the least trusted
 * party in the system — a requester, or with email ingestion enabled, someone
 * with no account at all — and it is served from pluginfile.php, on the site's
 * own origin, to an agent or an administrator. A file the browser executes
 * rather than displays would run as script in that reader's session.
 *
 * The two file-building helpers here are deliberately not shortcuts: each one
 * stores the file the way one of the plugin's two real intake paths stores it,
 * so the tests fail if either path starts producing a file this rule does not
 * recognise.
 *
 * @covers \local_moodesk\util\attachment_disposition
 */
final class attachment_disposition_test extends \advanced_testcase {
    /**
     * Mimetypes that must never be rendered by the browser.
     *
     * @return array<string, array{string}>
     */
    public static function active_mimetype_provider(): array {
        return [
            'html'   => ['text/html'],
            'xhtml'  => ['application/xhtml+xml'],
            'js'     => ['application/x-javascript'],
            'css'    => ['text/css'],
            'scss'   => ['text/x-scss'],
            'svg'    => ['image/svg+xml'],
        ];
    }

    /**
     * Mimetypes that are safe to render and must keep doing so.
     *
     * @return array<string, array{string}>
     */
    public static function inert_mimetype_provider(): array {
        return [
            'png'   => ['image/png'],
            'jpeg'  => ['image/jpeg'],
            'gif'   => ['image/gif'],
            'pdf'   => ['application/pdf'],
            'plain' => ['text/plain'],
            'csv'   => ['text/csv'],
            'docx'  => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'zip'   => ['application/zip'],
            'octet' => ['application/octet-stream'],
        ];
    }

    /**
     * A type the browser executes is never rendered.
     *
     * @dataProvider active_mimetype_provider
     * @param string $mimetype Mimetype under test.
     */
    public function test_browser_executable_types_are_refused_inline(string $mimetype): void {
        $this->assertTrue(attachment_disposition::is_active_mimetype($mimetype));
    }

    /**
     * A type that cannot execute keeps opening in the browser.
     *
     * @dataProvider inert_mimetype_provider
     * @param string $mimetype Mimetype under test.
     */
    public function test_inert_types_keep_rendering_inline(string $mimetype): void {
        $this->assertFalse(attachment_disposition::is_active_mimetype($mimetype));
    }

    /**
     * The rule reads core's own registry rather than a list of its own, so the
     * membership it depends on is worth asserting explicitly: if a future
     * Moodle moves html out of `web_file`, this fails here rather than silently
     * reopening the hole in production.
     */
    public function test_the_rule_is_anchored_to_cores_web_file_typegroup(): void {
        $webfile = file_get_typegroup('type', 'web_file');

        $this->assertContains('text/html', $webfile);
        $this->assertContains('application/xhtml+xml', $webfile);
        $this->assertNotContains('image/png', $webfile);
    }

    /**
     * An attachment uploaded through the composer — the path submit.php and
     * ticket_page_handler.php both take — must be refused inline when it is a
     * web document.
     */
    public function test_an_html_file_uploaded_from_the_ui_is_forced_to_download(): void {
        $this->resetAfterTest();

        $file = $this->store_as_the_composer_does('report.html', '<script>alert(1)</script>');

        $this->assertSame('text/html', attachment_disposition::sending_mimetype($file));
        $this->assertTrue(attachment_disposition::must_force_download($file));
    }

    /**
     * The same file arriving by email. The ingestion service builds its file
     * record without a mimetype key on purpose, letting core derive the type
     * from the name rather than trusting the sender's Content-Type, so this
     * asserts the derived type is the one the rule sees.
     */
    public function test_an_html_file_arriving_by_email_is_forced_to_download(): void {
        $this->resetAfterTest();

        $file = $this->store_as_the_email_pipeline_does('console.html', '<script>alert(1)</script>');

        $this->assertSame('text/html', attachment_disposition::sending_mimetype($file));
        $this->assertTrue(attachment_disposition::must_force_download($file));
    }

    /**
     * A sender's declared Content-Type is never stored, so it cannot be used to
     * dress an executable file as an inert one.
     */
    public function test_a_dishonest_extension_is_judged_by_the_name_core_resolves(): void {
        $this->resetAfterTest();

        $file = $this->store_as_the_email_pipeline_does('invoice.png', '<script>alert(1)</script>');

        // Bytes are irrelevant to how a browser is told to treat the response;
        // the name is what decides, and this one is inert.
        $this->assertFalse(attachment_disposition::must_force_download($file));

        // Renaming the same bytes to .html is what makes them dangerous.
        $renamed = $this->store_as_the_email_pipeline_does('invoice.html', '<script>alert(1)</script>');
        $this->assertTrue(attachment_disposition::must_force_download($renamed));
    }

    /**
     * Agents open screenshots constantly; forcing a download for them would be
     * a real cost with no security gain.
     */
    public function test_an_image_attachment_still_opens_in_the_browser(): void {
        $this->resetAfterTest();

        $ui    = $this->store_as_the_composer_does('screenshot.png', 'not-really-a-png');
        $email = $this->store_as_the_email_pipeline_does('photo.jpg', 'not-really-a-jpeg');

        $this->assertFalse(attachment_disposition::must_force_download($ui));
        $this->assertFalse(attachment_disposition::must_force_download($email));
    }

    /**
     * A file whose stored mimetype is missing or unresolved falls back to the
     * name, exactly as send_file() does.
     */
    public function test_an_unknown_stored_mimetype_falls_back_to_the_file_name(): void {
        $this->resetAfterTest();

        $file = $this->store_as_the_email_pipeline_does('payload.html', 'x', 'document/unknown');

        $this->assertSame('text/html', attachment_disposition::sending_mimetype($file));
        $this->assertTrue(attachment_disposition::must_force_download($file));
    }

    /**
     * Store a file the way the ticket composer does: into the user's draft area
     * first, then through file_save_draft_area_files() into reply_attachment.
     *
     * @param string $filename Name to store under.
     * @param string $content  File bytes.
     * @return \stored_file
     */
    private function store_as_the_composer_does(string $filename, string $content): \stored_file {
        global $USER;

        $this->setAdminUser();

        $context = \context_system::instance();
        $itemid  = 1;
        $draftid = file_get_unused_draft_itemid();
        $fs      = get_file_storage();

        $fs->create_file_from_string((object) [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $content);

        file_save_draft_area_files(
            $draftid,
            $context->id,
            'local_moodesk',
            'reply_attachment',
            $itemid,
            ['subdirs' => 0, 'maxfiles' => 5]
        );

        return $fs->get_file($context->id, 'local_moodesk', 'reply_attachment', $itemid, '/', $filename);
    }

    /**
     * Store a file the way email_ingestion_service::store_attachments() does:
     * create_file_from_string() with no mimetype key in the file record.
     *
     * @param string      $filename Name to store under.
     * @param string      $content  File bytes.
     * @param string|null $mimetype Force a stored mimetype, to exercise the
     *                              fallback; null leaves core to derive it.
     * @return \stored_file
     */
    private function store_as_the_email_pipeline_does(
        string $filename,
        string $content,
        ?string $mimetype = null
    ): \stored_file {
        static $itemid = 100;
        $itemid++;

        $context    = \context_system::instance();
        $filerecord = (object) [
            'contextid' => $context->id,
            'component' => 'local_moodesk',
            'filearea'  => 'reply_attachment',
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ];

        if ($mimetype !== null) {
            $filerecord->mimetype = $mimetype;
        }

        return get_file_storage()->create_file_from_string($filerecord, $content);
    }
}
