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
 * Access policy for knowledge base articles: who may read one, who may write
 * one, and who may decide whether it is live.
 *
 * The single source of truth, in the same sense ticket_access_policy is for
 * tickets: the service, the pages, the file-serving callback and the external
 * functions all ask this class. A draft that reaches a requester is the failure
 * this stream exists to rule out, and the only way to rule it out is to have
 * one rule and to have every path ask it.
 *
 * The rule has two halves that must agree: {@see can_view()} answers for one
 * row, {@see scope_for()} answers for a query. The repository turns a scope
 * into SQL; tests/policy/kb_access_policy_test.php checks that for every actor
 * and every kind of row the two halves give the same answer.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\policy;

use local_moodesk\domain\kb_article;

/**
 * Decides whether an actor may view, edit or manage knowledge base articles.
 *
 * There is no system actor here. Nothing runs against the knowledge base
 * without a person behind it, so actor 0 is denied everything rather than
 * trusted with everything.
 */
class kb_access_policy {
    /** May see nothing: no local/moodesk:viewkb. */
    const SCOPE_NONE = 0;

    /** Published articles for everyone. A requester. */
    const SCOPE_PUBLIC = 1;

    /** Published articles, whoever they are for. An agent. */
    const SCOPE_AGENT = 2;

    /** Every article in every status. An editor or a manager. */
    const SCOPE_ALL = 3;

    /** @var \context_system */
    private \context_system $context;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->context = \context_system::instance();
    }

    /* ----------------------------------------------------------------------
     * Reading
     * -------------------------------------------------------------------- */

    /**
     * Whether the actor may open the knowledge base at all.
     *
     * @param int $actorid Actor user ID.
     * @return bool
     */
    public function can_read_kb(int $actorid): bool {
        return $actorid > 0 && has_capability('local/moodesk:viewkb', $this->context, $actorid);
    }

    /**
     * Whether the actor may see the given article.
     *
     * Decision tree:
     *  1. can edit          → every article, drafts and archived included.
     *  2. no :viewkb        → denied.
     *  3. not published     → denied.
     *  4. for everyone      → allowed.
     *  5. for agents only   → allowed to holders of :agent.
     *
     * @param int       $actorid Actor user ID.
     * @param \stdClass $article Raw article record; needs status and audience.
     * @return bool
     */
    public function can_view(int $actorid, \stdClass $article): bool {
        if ($this->can_edit($actorid)) {
            return true;
        }
        if (!$this->can_read_kb($actorid)) {
            return false;
        }
        if ((int) $article->status !== kb_article::STATUS_PUBLISHED) {
            return false;
        }
        if ((int) $article->audience === kb_article::AUDIENCE_EVERYONE) {
            return true;
        }
        return $this->is_agent($actorid);
    }

    /**
     * Throw if the actor may not see the article.
     *
     * The exception names :viewkb whatever the actual reason, so that a denied
     * draft looks exactly like a denied missing article from outside.
     *
     * @param int       $actorid
     * @param \stdClass $article
     * @throws \required_capability_exception
     */
    public function require_can_view(int $actorid, \stdClass $article): void {
        if (!$this->can_view($actorid, $article)) {
            throw new \required_capability_exception($this->context, 'local/moodesk:viewkb', 'nopermissions', '');
        }
    }

    /**
     * The widest set of rows the actor may see, as a SCOPE_* constant.
     *
     * This is can_view() restated for a WHERE clause. The repository is the
     * only translator of scopes into SQL, and the policy test holds the two in
     * agreement.
     *
     * @param int $actorid Actor user ID.
     * @return int One of the SCOPE_* constants.
     */
    public function scope_for(int $actorid): int {
        if ($this->can_edit($actorid)) {
            return self::SCOPE_ALL;
        }
        if (!$this->can_read_kb($actorid)) {
            return self::SCOPE_NONE;
        }
        return $this->is_agent($actorid) ? self::SCOPE_AGENT : self::SCOPE_PUBLIC;
    }

    /* ----------------------------------------------------------------------
     * Writing
     * -------------------------------------------------------------------- */

    /**
     * Whether the actor may create and edit articles.
     *
     * :managekb implies it: a manager who may decide whether an article is
     * live may also write one. The reverse does not hold.
     *
     * @param int $actorid Actor user ID.
     * @return bool
     */
    public function can_edit(int $actorid): bool {
        if ($actorid <= 0) {
            return false;
        }
        return has_capability('local/moodesk:editkb', $this->context, $actorid)
            || has_capability('local/moodesk:managekb', $this->context, $actorid);
    }

    /**
     * Throw if the actor may not write articles.
     *
     * @param int $actorid
     * @throws \required_capability_exception
     */
    public function require_can_edit(int $actorid): void {
        if (!$this->can_edit($actorid)) {
            throw new \required_capability_exception($this->context, 'local/moodesk:editkb', 'nopermissions', '');
        }
    }

    /**
     * Whether the actor may publish, unpublish, archive, restore or delete an
     * article, or change who it is for.
     *
     * @param int $actorid Actor user ID.
     * @return bool
     */
    public function can_manage(int $actorid): bool {
        return $actorid > 0 && has_capability('local/moodesk:managekb', $this->context, $actorid);
    }

    /**
     * Throw if the actor may not manage articles.
     *
     * @param int $actorid
     * @throws \required_capability_exception
     */
    public function require_can_manage(int $actorid): void {
        if (!$this->can_manage($actorid)) {
            throw new \required_capability_exception($this->context, 'local/moodesk:managekb', 'nopermissions', '');
        }
    }

    /* ----------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */

    /**
     * Whether the actor is a helpdesk agent.
     *
     * The primary marker, checked the way the rest of the plugin checks it:
     * without doanything, so a site administrator is an agent only if the
     * capability was actually granted.
     *
     * @param int $actorid
     * @return bool
     */
    private function is_agent(int $actorid): bool {
        return has_capability('local/moodesk:agent', $this->context, $actorid, false);
    }
}
