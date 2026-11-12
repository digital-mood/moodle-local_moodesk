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
 * Data generator for local_moodesk.
 *
 * Creates tickets and replies through the service layer rather than by writing
 * rows: a fixture that bypasses ticket_service would let a scenario pass on
 * data the product itself can no longer produce.
 *
 * @package   local_moodesk
 * @category  test
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_moodesk\domain\kb_article;
use local_moodesk\domain\ticket;
use local_moodesk\service\reply_service;
use local_moodesk\service\ticket_service;

/**
 * Data generator for local_moodesk.
 *
 * @package   local_moodesk
 * @category  test
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_moodesk_generator extends component_generator_base {
    /** @var int Number of tickets created, used to keep default subjects unique. */
    protected int $ticketcount = 0;

    /** @var int Number of replies created, used to keep default messages unique. */
    protected int $replycount = 0;

    /** @var int Number of knowledge base articles created, used to keep default titles unique. */
    protected int $articlecount = 0;

    /**
     * Reset the per-run counters.
     */
    public function reset(): void {
        $this->ticketcount  = 0;
        $this->replycount   = 0;
        $this->articlecount = 0;
    }

    /**
     * Create a ticket.
     *
     * Recognised fields: userid (requester, required), subject, message,
     * assigneeid, categoryid, departmentid, status, priority. Status and
     * priority accept either the integer constant or its lowercase label
     * ('open', 'urgent').
     *
     * @param array|stdClass|null $record
     * @return stdClass The ticket row, with ->replyid carrying the opening reply.
     */
    public function create_ticket($record = null): stdClass {
        global $DB;

        $record = (object) (array) ($record ?? []);
        $this->ticketcount++;

        if (empty($record->userid)) {
            throw new coding_exception('local_moodesk ticket generator requires a userid (the requester).');
        }

        $data          = new stdClass();
        $data->userid  = (int) $record->userid;
        $data->subject = $record->subject ?? 'Test ticket ' . $this->ticketcount;
        $data->message = $record->message ?? 'Test ticket message ' . $this->ticketcount;
        $data->messageformat = FORMAT_PLAIN;

        if (!empty($record->categoryid)) {
            $data->categoryid = (int) $record->categoryid;
        }
        if (!empty($record->departmentid)) {
            $data->departmentid = (int) $record->departmentid;
        }
        if (isset($record->priority)) {
            $data->priority = self::resolve_priority($record->priority);
        }

        $result   = ticket_service::create()->create_ticket($data, (int) $record->userid);
        $ticketid = (int) $result['ticketid'];

        // Assignment and status are set on the row rather than through the
        // service: both service paths require an actor holding manage
        // capabilities, and a fixture should not have to invent one.
        $update = [];
        if (!empty($record->assigneeid)) {
            $update['assigneeid'] = (int) $record->assigneeid;
        }
        if (isset($record->status)) {
            $update['status'] = self::resolve_status($record->status);
        }
        if ($update) {
            $update['id'] = $ticketid;
            $DB->update_record('local_moodesk_ticket', (object) $update);
        }

        $ticket = $DB->get_record('local_moodesk_ticket', ['id' => $ticketid], '*', MUST_EXIST);
        $ticket->replyid = (int) $result['replyid'];

        return $ticket;
    }

    /**
     * Add a reply or an internal note to an existing ticket.
     *
     * Recognised fields: ticketid (required), userid (author, required),
     * message, type ('reply' or 'note').
     *
     * @param array|stdClass|null $record
     * @return stdClass The reply row.
     */
    public function create_reply($record = null): stdClass {
        global $DB;

        $record = (object) (array) ($record ?? []);
        $this->replycount++;

        if (empty($record->ticketid)) {
            throw new coding_exception('local_moodesk reply generator requires a ticketid.');
        }
        if (empty($record->userid)) {
            throw new coding_exception('local_moodesk reply generator requires a userid (the author).');
        }

        $data                = new stdClass();
        $data->message       = $record->message ?? 'Test reply ' . $this->replycount;
        $data->messageformat = FORMAT_PLAIN;

        $service = reply_service::create();
        $type    = strtolower(trim((string) ($record->type ?? 'reply')));

        if (in_array($type, ['note', 'internal', 'internal note'], true)) {
            $replyid = $service->add_internal_note((int) $record->ticketid, $data, (int) $record->userid);
        } else {
            $replyid = $service->add_public_reply((int) $record->ticketid, $data, (int) $record->userid);
        }

        return $DB->get_record('local_moodesk_reply', ['id' => $replyid], '*', MUST_EXIST);
    }

    /**
     * Create a knowledge base article as a row.
     *
     * Written directly rather than through kb_service: the service insists on an actor who may edit
     * and on a legal path to every status, and a scenario that wants an
     * archived article for somebody else to look at should not have to walk
     * it there. The one derivation the service owns — searchtext — is applied
     * here through the same domain function, so a fixture row is searchable
     * the way a real one is.
     *
     * Recognised fields: title, body, status ('draft', 'published',
     * 'archived' or the constant), audience ('everyone', 'agents' or the
     * constant), categoryid, lang, groupid (the group a translation joins;
     * absent for a source row — a translation inherits the group's category
     * and audience unless given its own), createdby, usermodified, timepublished.
     *
     * @param array|stdClass|null $record
     * @return stdClass The article row.
     */
    public function create_kb_article($record = null): stdClass {
        global $DB, $CFG;

        $record = (object) (array) ($record ?? []);
        $this->articlecount++;
        $now = time();

        $status   = self::resolve_article_status($record->status ?? kb_article::STATUS_DRAFT);
        $audience = self::resolve_article_audience($record->audience ?? kb_article::AUDIENCE_EVERYONE);

        $article               = new stdClass();
        // A translation row names the group it joins; a source row is its own
        // group, set below once the id exists — the repository's rule.
        $article->groupid      = (int) ($record->groupid ?? 0);
        $article->lang         = $record->lang ?? $CFG->lang;
        $article->categoryid   = !empty($record->categoryid) ? (int) $record->categoryid : null;

        // Category and audience belong to the group (KB-06): a translation
        // takes the group's unless the fixture says otherwise, so a fixture
        // cannot build a group the service would never let exist.
        if ($article->groupid > 0) {
            $source = $DB->get_record('local_moodesk_kb_article', ['id' => $article->groupid]);
            if ($source) {
                if (!isset($record->categoryid)) {
                    $article->categoryid = $source->categoryid !== null ? (int) $source->categoryid : null;
                }
                if (!isset($record->audience)) {
                    $audience = (int) $source->audience;
                }
            }
        }
        $article->title        = $record->title ?? 'Test article ' . $this->articlecount;
        $article->body         = $record->body ?? '<p>Test article body ' . $this->articlecount . '</p>';
        $article->bodyformat   = FORMAT_HTML;
        $article->searchtext   = kb_article::derive_searchtext($article->body);
        $article->status       = $status;
        $article->audience     = $audience;
        $article->createdby    = (int) ($record->createdby ?? 0);
        $article->usermodified = (int) ($record->usermodified ?? $article->createdby);
        $article->timecreated  = (int) ($record->timecreated ?? $now);
        $article->timemodified = (int) ($record->timemodified ?? $article->timecreated);
        $article->timepublished = $record->timepublished
            ?? ($status === kb_article::STATUS_DRAFT ? null : $article->timecreated);

        $article->id = $DB->insert_record('local_moodesk_kb_article', $article);
        if ($article->groupid === 0) {
            $DB->set_field('local_moodesk_kb_article', 'groupid', $article->id, ['id' => $article->id]);
            $article->groupid = $article->id;
        }

        return $article;
    }

    /**
     * Fake a language pack, for tests only.
     *
     * PHPUnit and Behat sites have `en` and nothing else, and PARAM_LANG —
     * which every `lang` written to an article goes through — accepts only
     * an installed pack. Core's own tests fake one the same way: a
     * `langconfig.php` under `$CFG->dataroot/lang/<code>/`, which is exactly
     * what "installed" means to the string manager. The site reset removes
     * the dataroot between tests, so nothing outlives the test that wrote it.
     * There is no production code path here and PARAM_LANG is untouched.
     *
     * Recognised fields: code (required), name (localised name; the code
     * upper-cased by default), parent (a parent pack code, '' for none) and
     * iso (ISO 639-1 code for the HTML lang attribute; the first two
     * characters of the code by default).
     *
     * @param array|stdClass|null $record
     * @return stdClass code, name, parent, iso, path.
     */
    public function create_language_pack($record = null): stdClass {
        global $CFG;

        $record = (object) (array) ($record ?? []);
        if (empty($record->code) || clean_param($record->code, PARAM_SAFEDIR) !== $record->code) {
            throw new coding_exception('A fake language pack needs a code that PARAM_SAFEDIR accepts.');
        }
        $pack = (object) [
            'code'   => (string) $record->code,
            'name'   => (string) ($record->name ?? strtoupper($record->code)),
            'parent' => (string) ($record->parent ?? ''),
            'iso'    => (string) ($record->iso ?? substr($record->code, 0, 2)),
        ];
        $pack->path = $CFG->dataroot . '/lang/' . $pack->code;

        $export = static fn(string $v): string => var_export($v, true);
        $langconfig = "<?php\n"
            . "\$string['thislanguage'] = " . $export($pack->name) . ";\n"
            . "\$string['thislanguageint'] = " . $export($pack->name) . ";\n"
            . "\$string['parentlanguage'] = " . $export($pack->parent) . ";\n"
            . "\$string['iso6391'] = " . $export($pack->iso) . ";\n";

        check_dir_exists($pack->path);
        file_put_contents($pack->path . '/langconfig.php', $langconfig);

        // Under PHPUnit the reset is the light one (no langrev bump, which is
        // a config write); under Behat the web process must notice, and the
        // langrev bump is how the string manager's shared cache is invalidated.
        get_string_manager()->reset_caches(defined('PHPUNIT_TEST') && PHPUNIT_TEST);
        // The user property rules list the installed languages as the choices
        // for `lang`, once per process: a user created with the new code must
        // not be silently cleaned back to the site language.
        \core_user::reset_caches();

        return $pack;
    }

    /**
     * Translate an article status label into its constant.
     *
     * @param string|int $status
     * @return int
     */
    protected static function resolve_article_status($status): int {
        if (is_numeric($status)) {
            return (int) $status;
        }
        $map = [
            'draft'     => kb_article::STATUS_DRAFT,
            'published' => kb_article::STATUS_PUBLISHED,
            'archived'  => kb_article::STATUS_ARCHIVED,
        ];
        $key = strtolower(trim((string) $status));
        if (!isset($map[$key])) {
            throw new coding_exception('Unknown mooDesk article status: ' . $status);
        }
        return $map[$key];
    }

    /**
     * Translate an article audience label into its constant.
     *
     * @param string|int $audience
     * @return int
     */
    protected static function resolve_article_audience($audience): int {
        if (is_numeric($audience)) {
            return (int) $audience;
        }
        $map = [
            'everyone' => kb_article::AUDIENCE_EVERYONE,
            'agents'   => kb_article::AUDIENCE_AGENTS,
        ];
        $key = strtolower(trim((string) $audience));
        if (!isset($map[$key])) {
            throw new coding_exception('Unknown mooDesk article audience: ' . $audience);
        }
        return $map[$key];
    }

    /**
     * Translate a status label into its constant.
     *
     * @param string|int $status
     * @return int
     */
    protected static function resolve_status($status): int {
        if (is_numeric($status)) {
            return (int) $status;
        }

        $map = [
            'new'         => ticket::STATUS_NEW,
            'open'        => ticket::STATUS_OPEN,
            'pending'     => ticket::STATUS_PENDING,
            'on hold'     => ticket::STATUS_ON_HOLD,
            'onhold'      => ticket::STATUS_ON_HOLD,
            'resolved'    => ticket::STATUS_RESOLVED,
            'closed'      => ticket::STATUS_CLOSED,
            'in progress' => ticket::STATUS_INPROGRESS,
            'inprogress'  => ticket::STATUS_INPROGRESS,
        ];

        $key = strtolower(trim((string) $status));
        if (!isset($map[$key])) {
            throw new coding_exception('Unknown mooDesk ticket status: ' . $status);
        }

        return $map[$key];
    }

    /**
     * Translate a priority label into its constant.
     *
     * @param string|int $priority
     * @return int
     */
    protected static function resolve_priority($priority): int {
        if (is_numeric($priority)) {
            return (int) $priority;
        }

        $map = [
            'low'    => ticket::PRIORITY_LOW,
            'normal' => ticket::PRIORITY_NORMAL,
            'high'   => ticket::PRIORITY_HIGH,
            'urgent' => ticket::PRIORITY_URGENT,
        ];

        $key = strtolower(trim((string) $priority));
        if (!isset($map[$key])) {
            throw new coding_exception('Unknown mooDesk ticket priority: ' . $priority);
        }

        return $map[$key];
    }
}
