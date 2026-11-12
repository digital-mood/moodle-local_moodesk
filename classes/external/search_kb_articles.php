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
 * Suggested-articles endpoint for the New Ticket composer.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// Same 4.1 / 4.2+ shim the other endpoints use; see assign_ticket for the
// reasoning about the deprecated externallib.php include.
if (class_exists('core_external\external_api')) {
    foreach (
        [
        'external_api',
        'external_function_parameters',
        'external_value',
        'external_single_structure',
        'external_multiple_structure',
        ] as $mhdexternalclass
    ) {
        if (!class_exists($mhdexternalclass, false)) {
            class_alias('core_external\\' . $mhdexternalclass, $mhdexternalclass);
        }
    }
} else {
    require_once($CFG->libdir . '/externallib.php');
}

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use local_moodesk\output\kb_ui;
use local_moodesk\service\category_service;
use local_moodesk\service\edition_service;
use local_moodesk\service\kb_service;

/**
 * Knowledge base articles worth reading before a ticket is sent.
 *
 * Feeds the "Suggested articles" block the New Ticket form shows as the
 * subject is typed. Like search_active_tickets, this is **not** part of the
 * mooDesk API service: it is called over AJAX from the plugin's own UI with a
 * session, so it does not use the api_endpoint trait and is not gated on the
 * Enterprise api_access feature.
 *
 * Its contract is the knowledge base's, not the ticket form's. Whoever may
 * read published articles on kb.php gets the same articles here — the gate is
 * :viewkb and kb_access_policy, applied by kb_service::suggest(). The form
 * that mounts the block already requires :submit; that capability says who
 * may open a ticket and has no say over who may read an answer, so it is
 * deliberately not asked for here.
 *
 * What comes back is what a suggestion needs and nothing that explains it:
 * no body, status, audience, author, timestamps or scores. One row per
 * article group, in the requester's language when it exists (KB-06): the
 * service resolves, this endpoint only adds the row's language and, when it
 * is not the viewer's, its name — additive fields the older client ignores.
 */
class search_kb_articles extends external_api {
    /** Shortest subject worth hitting the database for. */
    private const MIN_SUBJECT_LENGTH = 3;

    /** Longest subject taken — the form's own limit on the field. */
    private const MAX_SUBJECT_LENGTH = 255;

    /** Length of the excerpt offered under each title. */
    private const EXCERPT_LENGTH = 120;

    /**
     * Parameter definition for execute().
     *
     * No limit parameter on purpose: how many articles a composer may offer
     * is the service's decision (kb_service::SUGGEST_LIMIT), not the client's.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'subject'    => new external_value(PARAM_TEXT, 'What has been typed as the ticket subject'),
            'categoryid' => new external_value(PARAM_INT, 'Category selected on the form, or 0', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Up to kb_service::SUGGEST_LIMIT published articles for the subject.
     *
     * @param string $subject    Subject as typed.
     * @param int    $categoryid Selected category, or 0 for none.
     * @return array[] Rows of id/title/excerpt/categoryname/url, plus lang/langlabel (KB-06).
     */
    public static function execute(string $subject, int $categoryid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'subject'    => $subject,
            'categoryid' => $categoryid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        edition_service::require_feature('knowledge_base');
        require_capability('local/moodesk:viewkb', $context);

        // A switched-off knowledge base is "nothing to suggest", not a fault:
        // the form does not render the block then either, and kb_enabled is a
        // presentation switch the service does not know about (kb_ui).
        if (!kb_ui::is_enabled()) {
            return [];
        }

        $subject = \core_text::substr(trim($params['subject']), 0, self::MAX_SUBJECT_LENGTH);
        if (\core_text::strlen($subject) < self::MIN_SUBJECT_LENGTH) {
            return [];
        }

        global $USER;

        $service  = kb_service::create();
        $articles = $service->suggest($subject, (int) $USER->id, (int) $params['categoryid']);
        if (!$articles) {
            return [];
        }

        // What was offered, for plugins that count it. The subject typed stays here.
        \core\di::get(\core\hook\manager::class)->dispatch(new \local_moodesk\hook\kb_suggestions_shown(
            array_map(static fn(\stdClass $a): int => (int) $a->id, $articles),
            (int) $USER->id
        ));

        $tokens = kb_service::tokenize($subject);
        $names  = category_service::create()->get_name_map();

        $rows = [];
        foreach ($articles as $article) {
            $categoryid = $article->categoryid !== null ? (int) $article->categoryid : 0;
            $rows[] = [
                'id'           => (int) $article->id,
                'title'        => format_string($article->title, true, ['escape' => false]),
                'excerpt'      => kb_ui::excerpt((string) $article->body, $tokens, self::EXCERPT_LENGTH),
                'categoryname' => isset($names[$categoryid])
                    ? format_string($names[$categoryid], true, ['escape' => false])
                    : '',
                'url'          => (new \moodle_url('/local/moodesk/kb_article.php', ['id' => $article->id]))->out(false),
                // KB-06, additive: the row's language, and its name only when
                // it is not the language the viewer reads in (Pro) — the
                // badge the item shows. Existing clients ignore both.
                'lang'         => (string) $article->lang,
                'langlabel'    => kb_ui::language_label((string) $article->lang),
            ];
        }

        return $rows;
    }

    /**
     * Return description for execute().
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'           => new external_value(PARAM_INT, 'Article ID'),
                'title'        => new external_value(PARAM_TEXT, 'Article title, plain text'),
                'excerpt'      => new external_value(PARAM_TEXT, 'Short plain-text excerpt, or empty'),
                'categoryname' => new external_value(PARAM_TEXT, 'Name of the category the article is filed under, or empty'),
                'url'          => new external_value(PARAM_URL, 'Where to read the article'),
                'lang'         => new external_value(PARAM_SAFEDIR, 'Language code of this row (KB-06)'),
                'langlabel'    => new external_value(
                    PARAM_TEXT,
                    'Name of the language when it is not the viewer\'s, else empty (KB-06)'
                ),
            ])
        );
    }
}
