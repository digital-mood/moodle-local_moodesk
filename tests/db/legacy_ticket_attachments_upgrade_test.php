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

namespace local_moodesk\db;

use local_moodesk\domain\reply;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/moodesk/db/upgradelib.php');
require_once(__DIR__ . '/../fixtures/legacy_pro_tables.php');

/**
 * The F1 upgrade step: legacy ticket_attachment files move to the opening reply.
 *
 * Every case is data the old releases could have left behind, written the way
 * they wrote it — raw rows and File API calls, not today's services — and the
 * one assertion that matters everywhere: a file that cannot be placed without
 * guessing is not placed. Runs on every database of the matrix; the file
 * store is content-addressed on all of them, so what moves is rows.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    ::local_moodesk_upgrade_legacy_ticket_attachments
 * @covers    ::local_moodesk_upgrade_resolve_opening_reply
 */
final class legacy_ticket_attachments_upgrade_test extends \advanced_testcase {
    use legacy_pro_tables;

    /** @var int System context id, where every mooDesk file lives. */
    private int $contextid;

    /** @var \stdClass The requester of every fixture ticket. */
    private \stdClass $requester;

    /** @var \stdClass An agent, for replies that are not the requester's. */
    private \stdClass $agent;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->install_legacy_pro_tables();

        $this->contextid = \context_system::instance()->id;
        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent     = $this->getDataGenerator()->create_user();
    }

    /**
     * Leave no legacy table behind.
     */
    protected function tearDown(): void {
        $this->drop_legacy_pro_tables();
        parent::tearDown();
    }

    /*
     * -------------------------------------------------------------------------
     * Fixtures — the shape the old releases left, not today's services
     * -------------------------------------------------------------------------
     */

    /**
     * A ticket row.
     *
     * @return int
     */
    private function ticket(): int {
        global $DB;
        $now = time() - 86400;

        return (int) $DB->insert_record('local_moodesk_ticket', (object) [
            'userid'       => $this->requester->id,
            'createdby'    => $this->requester->id,
            'subject'      => 'Legacy emailed ticket',
            'status'       => 0,
            'priority'     => 1,
            'source'       => 1,
            'timecreated'  => $now,
            'timemodified' => $now,
            'usermodified' => $this->requester->id,
        ]);
    }

    /**
     * A reply row.
     *
     * @param int $ticketid
     * @param int $userid
     * @param int $type        A reply::TYPE_* constant.
     * @param int $timecreated
     * @return int
     */
    private function reply(int $ticketid, int $userid, int $type, int $timecreated): int {
        global $DB;

        return (int) $DB->insert_record('local_moodesk_reply', (object) [
            'ticketid'      => $ticketid,
            'userid'        => $userid,
            'message'       => 'Body',
            'messageformat' => FORMAT_PLAIN,
            'type'          => $type,
            'timecreated'   => $timecreated,
            'timemodified'  => $timecreated,
            'usermodified'  => $userid,
        ]);
    }

    /**
     * The audit row the old ingestion wrote when it created a ticket.
     *
     * @param int      $ticketid
     * @param int|null $replyid
     * @param int      $role
     * @return int
     */
    private function origin(int $ticketid, ?int $replyid, int $role = 0): int {
        global $DB;

        return (int) $DB->insert_record('local_moodesk_email_message', (object) [
            'ticketid'     => $ticketid,
            'replyid'      => $replyid,
            'message_id'   => 'legacy-' . $ticketid . '-' . ($replyid ?? 'none') . '-' . random_string(6) . '@example.test',
            'sender_email' => $this->requester->email,
            'raw_subject'  => 'Legacy emailed ticket',
            'role'         => $role,
            'timecreated'  => time() - 86400,
        ]);
    }

    /**
     * A file in the legacy area, with the metadata the old ingestion set.
     *
     * @param int    $ticketid
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function legacy_file(int $ticketid, string $filename, string $content = 'attachment body'): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid'    => $this->contextid,
            'component'    => 'local_moodesk',
            'filearea'     => 'ticket_attachment',
            'itemid'       => $ticketid,
            'filepath'     => '/',
            'filename'     => $filename,
            'userid'       => $this->requester->id,
            'author'       => 'Legacy Sender',
            'license'      => 'allrightsreserved',
            'mimetype'     => 'text/plain',
            'timecreated'  => 1700000000,
            'timemodified' => 1700000001,
            'sortorder'    => 3,
        ], $content);
    }

    /**
     * File names in an area, sorted.
     *
     * @param string $area
     * @param int    $itemid
     * @return string[]
     */
    private function names(string $area, int $itemid): array {
        $files = get_file_storage()->get_area_files($this->contextid, 'local_moodesk', $area, $itemid, 'filename', false);
        $names = array_map(static fn(\stored_file $f): string => $f->get_filename(), array_values($files));
        sort($names);

        return $names;
    }

    /**
     * Run the step and return its counts.
     *
     * @return array
     */
    private function migrate(): array {
        global $DB;

        return local_moodesk_upgrade_legacy_ticket_attachments($DB);
    }

    /*
     * -------------------------------------------------------------------------
     * Resolution
     * -------------------------------------------------------------------------
     */

    /**
     * The ingestion's own record names the reply: that is where the file goes,
     * even when it is not the earliest reply on the ticket.
     */
    public function test_primary_anchor_places_the_file_on_the_recorded_reply(): void {
        $ticketid = $this->ticket();
        $earlier  = $this->reply($ticketid, $this->agent->id, reply::TYPE_INTERNAL_NOTE, 1000);
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 2000);
        $this->origin($ticketid, $opening);
        $this->legacy_file($ticketid, 'scan.txt');

        $result = $this->migrate();

        $this->assertSame(1, $result['migrated']);
        $this->assertSame(0, $result['ambiguous']);
        $this->assertSame(['scan.txt'], $this->names('reply_attachment', $opening));
        $this->assertSame([], $this->names('reply_attachment', $earlier));
        $this->assertSame([], $this->names('ticket_attachment', $ticketid));
    }

    /**
     * Without an anchor, the requester's unmistakable opening message is the
     * destination.
     */
    public function test_fallback_places_the_file_on_the_requesters_opening_reply(): void {
        $ticketid = $this->ticket();
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->reply($ticketid, $this->agent->id, reply::TYPE_PUBLIC_REPLY, 2000);
        $this->legacy_file($ticketid, 'a.txt');
        $this->legacy_file($ticketid, 'b.txt');

        $result = $this->migrate();

        $this->assertSame(2, $result['migrated']);
        $this->assertSame(['a.txt', 'b.txt'], $this->names('reply_attachment', $opening));
        $this->assertSame([], $this->names('ticket_attachment', $ticketid));
    }

    /**
     * An origin row without a reply id is not an anchor; the fallback still
     * applies.
     */
    public function test_an_origin_row_without_a_reply_is_not_an_anchor(): void {
        $ticketid = $this->ticket();
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->origin($ticketid, null);
        $this->legacy_file($ticketid, 'scan.txt');

        $this->assertSame(1, $this->migrate()['migrated']);
        $this->assertSame(['scan.txt'], $this->names('reply_attachment', $opening));
    }

    /*
     * -------------------------------------------------------------------------
     * Ambiguity — nothing moves, nothing is chosen, nothing is deleted
     * -------------------------------------------------------------------------
     */

    /**
     * A ticket with no reply has nowhere to put the file.
     */
    public function test_a_ticket_with_no_reply_is_left_alone(): void {
        $ticketid = $this->ticket();
        $this->legacy_file($ticketid, 'scan.txt');

        $result = $this->migrate();

        $this->assertSame(0, $result['migrated']);
        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame([$ticketid], $result['ambiguoustickets']);
        $this->assertSame(['scan.txt'], $this->names('ticket_attachment', $ticketid));
    }

    /**
     * Two origin rows naming different replies contradict each other.
     */
    public function test_conflicting_origin_rows_are_ambiguous(): void {
        $ticketid = $this->ticket();
        $one = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $two = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 2000);
        $this->origin($ticketid, $one);
        $this->origin($ticketid, $two);
        $this->legacy_file($ticketid, 'scan.txt');

        $result = $this->migrate();

        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame(['scan.txt'], $this->names('ticket_attachment', $ticketid));
        $this->assertSame([], $this->names('reply_attachment', $one));
        $this->assertSame([], $this->names('reply_attachment', $two));
    }

    /**
     * Two origin rows naming the SAME reply agree; that is one anchor.
     */
    public function test_repeated_origin_rows_naming_one_reply_are_one_anchor(): void {
        $ticketid = $this->ticket();
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->origin($ticketid, $opening);
        $this->origin($ticketid, $opening);
        $this->legacy_file($ticketid, 'scan.txt');

        $this->assertSame(1, $this->migrate()['migrated']);
        $this->assertSame(['scan.txt'], $this->names('reply_attachment', $opening));
    }

    /**
     * An anchor pointing at another ticket's reply, or at a reply that is
     * gone, is not trusted — and the fallback is not tried behind it.
     */
    public function test_an_anchor_that_does_not_belong_to_the_ticket_is_ambiguous(): void {
        $ticketid = $this->ticket();
        $other    = $this->ticket();
        $foreign  = $this->reply($other, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->origin($ticketid, $foreign);
        $this->legacy_file($ticketid, 'scan.txt');

        $result = $this->migrate();

        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame(['scan.txt'], $this->names('ticket_attachment', $ticketid));
        $this->assertSame([], $this->names('reply_attachment', $foreign));
    }

    /**
     * The fallback needs the earliest reply to be the requester's public
     * message: an agent's note first, or an internal note first, is not it.
     */
    public function test_fallback_refuses_when_the_earliest_reply_is_not_the_requesters(): void {
        $ticketid = $this->ticket();
        $this->reply($ticketid, $this->agent->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 2000);
        $this->legacy_file($ticketid, 'scan.txt');

        $this->assertSame(1, $this->migrate()['ambiguous']);
        $this->assertSame(['scan.txt'], $this->names('ticket_attachment', $ticketid));

        $noteticket = $this->ticket();
        $this->reply($noteticket, $this->requester->id, reply::TYPE_INTERNAL_NOTE, 1000);
        $this->reply($noteticket, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 2000);
        $this->legacy_file($noteticket, 'note.txt');

        // Both tickets are still there to be refused on the second pass.
        $this->assertSame(2, $this->migrate()['ambiguous']);
        $this->assertSame(['note.txt'], $this->names('ticket_attachment', $noteticket));
    }

    /**
     * Two of the requester's public replies at the same instant cannot be
     * told apart; a tie with someone else's reply can.
     */
    public function test_fallback_refuses_a_tie_between_two_opening_candidates(): void {
        $tied = $this->ticket();
        $this->reply($tied, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->reply($tied, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->legacy_file($tied, 'scan.txt');

        $this->assertSame(1, $this->migrate()['ambiguous']);
        $this->assertSame(['scan.txt'], $this->names('ticket_attachment', $tied));

        $clear   = $this->ticket();
        $opening = $this->reply($clear, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->reply($clear, $this->agent->id, reply::TYPE_SYSTEM_EVENT, 1000);
        $this->legacy_file($clear, 'scan.txt');

        $this->assertSame(1, $this->migrate()['migrated']);
        $this->assertSame(['scan.txt'], $this->names('reply_attachment', $opening));
    }

    /**
     * A different file already under the same name at the destination is a
     * collision: the whole ticket is left alone, not half of it.
     */
    public function test_a_collision_leaves_the_whole_ticket_alone(): void {
        $ticketid = $this->ticket();
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->origin($ticketid, $opening);
        $this->legacy_file($ticketid, 'scan.txt', 'legacy version');
        $this->legacy_file($ticketid, 'other.txt');
        get_file_storage()->create_file_from_string([
            'contextid' => $this->contextid,
            'component' => 'local_moodesk',
            'filearea'  => 'reply_attachment',
            'itemid'    => $opening,
            'filepath'  => '/',
            'filename'  => 'scan.txt',
        ], 'a different file');

        $result = $this->migrate();

        $this->assertSame(0, $result['migrated']);
        $this->assertSame(2, $result['ambiguous']);
        $this->assertSame(['other.txt', 'scan.txt'], $this->names('ticket_attachment', $ticketid));
        $this->assertSame(['scan.txt'], $this->names('reply_attachment', $opening));
        $existing = get_file_storage()->get_file(
            $this->contextid,
            'local_moodesk',
            'reply_attachment',
            $opening,
            '/',
            'scan.txt'
        );
        $this->assertSame('a different file', $existing->get_content());
    }

    /**
     * A ticket row that is gone has no destination; the files stay.
     */
    public function test_files_of_a_missing_ticket_are_left_alone(): void {
        $this->legacy_file(999999, 'scan.txt');

        $result = $this->migrate();

        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame(['scan.txt'], $this->names('ticket_attachment', 999999));
    }

    /**
     * One ambiguous ticket does not stop the others.
     */
    public function test_the_step_continues_past_an_ambiguous_ticket(): void {
        $stuck = $this->ticket();
        $this->legacy_file($stuck, 'stuck.txt');

        $fine    = $this->ticket();
        $opening = $this->reply($fine, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->origin($fine, $opening);
        $this->legacy_file($fine, 'fine.txt');

        $result = $this->migrate();

        $this->assertSame(1, $result['migrated']);
        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame([$stuck], $result['ambiguoustickets']);
        $this->assertSame(['fine.txt'], $this->names('reply_attachment', $opening));
        $this->assertSame(['stuck.txt'], $this->names('ticket_attachment', $stuck));
    }

    /*
     * -------------------------------------------------------------------------
     * Preservation and idempotence
     * -------------------------------------------------------------------------
     */

    /**
     * The moved file is the same file: content, and the metadata the old
     * ingestion set on it.
     */
    public function test_content_and_metadata_survive_the_move(): void {
        $ticketid = $this->ticket();
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->origin($ticketid, $opening);
        $source = $this->legacy_file($ticketid, 'scan.txt', 'exact bytes');
        $hash   = $source->get_contenthash();

        $this->migrate();

        $moved = get_file_storage()->get_file(
            $this->contextid,
            'local_moodesk',
            'reply_attachment',
            $opening,
            '/',
            'scan.txt'
        );
        $this->assertNotFalse($moved);
        $this->assertSame('exact bytes', $moved->get_content());
        $this->assertSame($hash, $moved->get_contenthash());
        $this->assertSame((int) $this->requester->id, (int) $moved->get_userid());
        $this->assertSame('Legacy Sender', $moved->get_author());
        $this->assertSame('allrightsreserved', $moved->get_license());
        $this->assertSame('text/plain', $moved->get_mimetype());
        $this->assertSame(1700000000, (int) $moved->get_timecreated());
        $this->assertSame(1700000001, (int) $moved->get_timemodified());
        $this->assertSame(3, (int) $moved->get_sortorder());
    }

    /**
     * A second run finds nothing to do; a run after an interrupted first one
     * (the copy made, the source not yet deleted) finishes the job without
     * duplicating anything.
     */
    public function test_the_step_is_idempotent(): void {
        $ticketid = $this->ticket();
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        $this->origin($ticketid, $opening);
        $this->legacy_file($ticketid, 'scan.txt');

        $first  = $this->migrate();
        $second = $this->migrate();

        $this->assertSame(1, $first['migrated']);
        $this->assertSame(['migrated' => 0, 'already' => 0, 'ambiguous' => 0, 'ambiguoustickets' => []], $second);
        $this->assertSame(['scan.txt'], $this->names('reply_attachment', $opening));

        // An interrupted run: the destination copy exists, the source is still there.
        $this->legacy_file($ticketid, 'scan.txt');

        $third = $this->migrate();

        $this->assertSame(0, $third['migrated']);
        $this->assertSame(1, $third['already']);
        $this->assertSame([], $this->names('ticket_attachment', $ticketid));
        $this->assertSame(['scan.txt'], $this->names('reply_attachment', $opening));
    }

    /**
     * A site with nothing in the legacy area is untouched, and the reply
     * attachments it already has are not.
     */
    public function test_a_site_without_legacy_files_is_a_no_op(): void {
        $ticketid = $this->ticket();
        $opening  = $this->reply($ticketid, $this->requester->id, reply::TYPE_PUBLIC_REPLY, 1000);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->contextid,
            'component' => 'local_moodesk',
            'filearea'  => 'reply_attachment',
            'itemid'    => $opening,
            'filepath'  => '/',
            'filename'  => 'modern.txt',
        ], 'written by 2.14.3+');

        $this->assertSame(['migrated' => 0, 'already' => 0, 'ambiguous' => 0, 'ambiguoustickets' => []], $this->migrate());
        $this->assertSame(['modern.txt'], $this->names('reply_attachment', $opening));
    }
}
