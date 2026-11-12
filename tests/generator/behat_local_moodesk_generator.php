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
 * Behat data generator for local_moodesk.
 *
 * Lets a feature file write:
 *
 *     Given the following "local_moodesk > tickets" exist:
 *       | subject | user       | assignee |
 *       | Printer | requester1 | agent1   |
 *
 * @package   local_moodesk
 * @category  test
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for local_moodesk.
 *
 * @package   local_moodesk
 * @category  test
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_moodesk_generator extends behat_generator_base {
    /**
     * The entities a feature file can create.
     *
     * The plugin's own categories and departments are deliberately absent: the
     * install seeds a default of each and every smoke scenario runs on them, so
     * exposing more here would be fixture surface nothing needs. Note that
     * 'category' could not be reused for a mooDesk category anyway — the base
     * class already owns that name for course categories.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            // A `kbcategory` column names a mooDesk category for a ticket too,
            // through the same resolver the articles use; `category` stays
            // the base class's course category.
            'tickets' => [
                'singular'      => 'ticket',
                'datagenerator' => 'ticket',
                'required'      => ['user'],
                'switchids'     => ['user' => 'userid', 'assignee' => 'assigneeid', 'kbcategory' => 'categoryid'],
            ],
            'replies' => [
                'singular'      => 'reply',
                'datagenerator' => 'reply',
                'required'      => ['ticket', 'user'],
                'switchids'     => ['user' => 'userid', 'ticket' => 'ticketid'],
            ],
            // Status and audience take the words the generator understands
            // (draft / published / archived, everyone / agents); `user` is who
            // wrote it. No category column: the base class owns
            // get_category_id() for course categories.
            // `kbcategory` names a mooDesk category (created active if it
            // does not exist yet) — not `category`, which the base class owns
            // for course categories.
            // `translationof` names the title of the article this row is a
            // translation of (KB-06); with it the row joins that group.
            'kb articles' => [
                'singular'      => 'kb article',
                'datagenerator' => 'kb_article',
                'required'      => ['title'],
                'switchids'     => ['user' => 'createdby', 'kbcategory' => 'categoryid', 'translationof' => 'groupid'],
            ],
            // A fake language pack (code, name, parent, iso) written under the
            // Behat dataroot, so a scenario can have `es` or `es_mx` without a
            // pack download. See the data generator for what it is and is not.
            'language packs' => [
                'singular'      => 'language pack',
                'datagenerator' => 'language_pack',
                'required'      => ['code'],
            ],
        ];
    }

    /**
     * Resolve a mooDesk category name to its id, creating it when it does not exist.
     *
     * @param string $name The category name.
     * @return int
     */
    protected function get_kbcategory_id($name): int {
        global $DB;

        $id = $DB->get_field('local_moodesk_category', 'id', ['name' => $name], IGNORE_MULTIPLE);
        if ($id) {
            return (int) $id;
        }
        $now = time();
        return (int) $DB->insert_record('local_moodesk_category', (object) [
            'name' => $name, 'sortorder' => 100, 'visible' => 1, 'timecreated' => $now, 'usermodified' => 2,
        ]);
    }

    /**
     * Resolve an article title to its row id.
     *
     * @param string $title The exact title of the translation row.
     * @return int
     */
    protected function get_article_id($title): int {
        global $DB;

        $id = $DB->get_field('local_moodesk_kb_article', 'id', ['title' => $title], IGNORE_MULTIPLE);
        if (!$id) {
            throw new Exception('The specified mooDesk article with title "' . $title . '" does not exist');
        }
        return (int) $id;
    }

    /**
     * Resolve the title of a source article to the group a translation joins.
     *
     * @param string $title The exact title of the article first written.
     * @return int Its groupid.
     */
    protected function get_translationof_id($title): int {
        global $DB;

        $groupid = $DB->get_field('local_moodesk_kb_article', 'groupid', ['title' => $title], IGNORE_MULTIPLE);
        if (!$groupid) {
            throw new Exception('The specified mooDesk article with title "' . $title . '" does not exist');
        }
        return (int) $groupid;
    }

    /**
     * Resolve an assignee username to a user id.
     *
     * @param string $username
     * @return int
     */
    protected function get_assignee_id($username): int {
        return $this->get_user_id($username);
    }

    /**
     * Resolve a ticket subject to a ticket id.
     *
     * @param string $subject The exact ticket subject.
     * @return int
     */
    protected function get_ticket_id($subject): int {
        global $DB;

        $id = $DB->get_field('local_moodesk_ticket', 'id', ['subject' => $subject], IGNORE_MULTIPLE);

        if (!$id) {
            throw new Exception('The specified mooDesk ticket with subject "' . $subject . '" does not exist');
        }

        return (int) $id;
    }
}
