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
 * Serving files embedded in knowledge base articles.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

use local_moodesk\service\kb_service;

/**
 * Tests for the kb_article branch of local_moodesk_pluginfile().
 *
 * An image in a draft is exactly as reachable as the draft. The callback does
 * not restate the rule — it asks kb_access_policy through
 * local_moodesk_kb_article_file_for() — so what is pinned here is that
 * decision, for every kind of reader. The callback itself only sends what the
 * decision allows and refuses with send_file_not_found() otherwise; sending
 * writes headers and closes output buffers, which a unit test cannot host
 * without --fail-on-warning turning red, so the delivery is left to Behat.
 *
 * @covers ::local_moodesk_kb_article_file_for
 */
final class pluginfile_kb_article_test extends \advanced_testcase {
    /** @var \context_system */
    private \context_system $context;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/local/moodesk/lib.php');
        $this->context = \context_system::instance();
    }

    /**
     * An article with one image under it.
     *
     * @param string $status
     * @param string $audience
     * @return int article id
     */
    private function article_with_image(string $status, string $audience = 'everyone'): int {
        $article = $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_kb_article([
            'status'   => $status,
            'audience' => $audience,
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'local_moodesk',
            'filearea'  => kb_service::FILEAREA,
            'itemid'    => $article->id,
            'filepath'  => '/',
            'filename'  => 'shot.png',
        ], 'png-bytes');
        return (int) $article->id;
    }

    /**
     * A user holding the capabilities named.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    private function user_holding(array $capabilities): \stdClass {
        $user   = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $this->context->id, true);
        }
        role_assign($roleid, $user->id, $this->context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Ask for the file as the current user; true when it would be served.
     *
     * The decision is tested, not the delivery: send_stored_file() writes
     * headers and closes buffers, neither of which a unit test can host, and
     * the callback does nothing with the decision but act on it.
     *
     * @param int    $articleid
     * @param string $filename
     * @return bool
     */
    private function served(int $articleid, string $filename = 'shot.png'): bool {
        global $USER;
        $file = local_moodesk_kb_article_file_for($this->context, $articleid, $filename, (int) $USER->id);
        if ($file === null) {
            return false;
        }
        $this->assertSame('png-bytes', $file->get_content());
        return true;
    }

    /**
     * A requester gets a published article's image and nothing else's.
     */
    public function test_requester_reach(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertTrue($this->served($this->article_with_image('published')));
        $this->assertFalse($this->served($this->article_with_image('draft')));
        $this->assertFalse($this->served($this->article_with_image('archived')));
        $this->assertFalse($this->served($this->article_with_image('published', 'agents')));
    }

    /**
     * An agent also gets agents-only images; still no drafts.
     */
    public function test_agent_reach(): void {
        $this->setUser($this->user_holding(['local/moodesk:agent']));

        $this->assertTrue($this->served($this->article_with_image('published', 'agents')));
        $this->assertFalse($this->served($this->article_with_image('draft', 'agents')));
    }

    /**
     * An editor gets everything, drafts included.
     */
    public function test_editor_reach(): void {
        $this->setUser($this->user_holding(['local/moodesk:editkb']));

        $this->assertTrue($this->served($this->article_with_image('draft')));
        $this->assertTrue($this->served($this->article_with_image('archived', 'agents')));
    }

    /**
     * A missing article and a missing file are both "not found", never a leak
     * of which one it was.
     */
    public function test_missing_article_and_missing_file(): void {
        $this->setUser($this->user_holding(['local/moodesk:editkb']));

        $this->assertFalse($this->served(999999));
        $this->assertFalse($this->served($this->article_with_image('published'), 'other.png'));
    }
}
