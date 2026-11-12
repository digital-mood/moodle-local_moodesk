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

namespace local_moodesk\output;

use local_moodesk\domain\kb_article;
use local_moodesk\service\kb_service;

/**
 * The knowledge base's presentation helper, and the two templates that use it.
 *
 * The helper is where the three authoring screens agree on what a status
 * looks like, what an audience is called and how a stored body becomes HTML;
 * the templates are rendered here because no other PHPUnit test renders a
 * Mustache file, and a missing string or a broken section passes every other
 * test green.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\output\kb_ui
 */
final class kb_ui_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Unset reads as on — a site upgraded to this version keeps its screens —
     * and the setting is honoured both ways once written.
     */
    public function test_enabled_defaults_to_on(): void {
        unset_config('kb_enabled', 'local_moodesk');
        $this->assertTrue(kb_ui::is_enabled());

        set_config('kb_enabled', 0, 'local_moodesk');
        $this->assertFalse(kb_ui::is_enabled());

        set_config('kb_enabled', 1, 'local_moodesk');
        $this->assertTrue(kb_ui::is_enabled());
    }

    /**
     * Every status has a pill, every pill has a Pill variant, and the labels
     * are the three words the product uses for them.
     */
    public function test_status_pills(): void {
        $this->assertSame(['label' => 'Draft', 'variant' => 'neutral'], kb_ui::status_pill(kb_article::STATUS_DRAFT));
        $this->assertSame(['label' => 'Published', 'variant' => 'success'], kb_ui::status_pill(kb_article::STATUS_PUBLISHED));
        $this->assertSame(['label' => 'Archived', 'variant' => 'muted'], kb_ui::status_pill(kb_article::STATUS_ARCHIVED));

        $labels = kb_ui::status_labels();
        $this->assertSame(kb_article::statuses(), array_keys($labels));
    }

    /**
     * Audience labels, both ways round.
     */
    public function test_audience_labels(): void {
        $this->assertSame('Everyone', kb_ui::audience_label(kb_article::AUDIENCE_EVERYONE));
        $this->assertSame('Agents only', kb_ui::audience_label(kb_article::AUDIENCE_AGENTS));
        $this->assertSame(kb_article::audiences(), array_keys(kb_ui::audience_labels()));
    }

    /**
     * The body comes out cleaned and with its file placeholders resolved to
     * pluginfile URLs into the article's own area.
     */
    public function test_render_body_cleans_and_rewrites_files(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $article   = $generator->create_kb_article([
            'body' => '<p onclick="steal()">Hi <script>alert(1)</script><img src="@@PLUGINFILE@@/dot.png"></p>',
        ]);

        $html = kb_ui::render_body($article);

        $this->assertStringNotContainsString('<script', $html, 'format_text() strips the script.');
        $this->assertStringNotContainsString('onclick', $html, 'format_text() strips the handler.');
        $expectedurl = '/pluginfile.php/' . \context_system::instance()->id . '/local_moodesk/'
            . kb_service::FILEAREA . '/' . $article->id . '/dot.png';
        $this->assertStringContainsString(
            $expectedurl,
            $html,
            'The placeholder becomes a pluginfile URL into the article\'s area.'
        );
    }

    /**
     * The switched-off page keeps the screen's title and says where to switch
     * the knowledge base back on — the link only for someone who can.
     */
    public function test_render_disabled(): void {
        $this->setAdminUser();
        $html = kb_ui::render_disabled('Articles');
        $this->assertStringContainsString('<h1 class="mhd-page-header__title" id="mhd-page-title">Articles</h1>', $html);
        $this->assertStringContainsString('turned off', $html);
        $this->assertStringContainsString('admin/settings.php?section=local_moodesk', $html);

        $this->setUser($this->getDataGenerator()->create_user());
        $html = kb_ui::render_disabled('Articles');
        $this->assertStringContainsString('turned off', $html);
        $this->assertStringNotContainsString('admin/settings.php', $html, 'No settings link for someone who cannot use it.');
    }

    /**
     * The management list template renders its three shapes: rows, "no
     * articles" and "nothing matches", each with the strings it needs.
     */
    public function test_articles_template_renders_its_three_shapes(): void {
        global $OUTPUT;
        $this->setAdminUser();

        $header = (new page_header('Articles'))
            ->with_primary_action(header_action::create_new('New article', new \moodle_url('/local/moodesk/kb_article_edit.php')))
            ->to_context();
        $base = [
            'pageheader' => $header, 'listurl' => '/l', 'newurl' => '/n',
            'statusoptions' => [['value' => 'draft', 'label' => 'Draft', 'selected' => false]],
            'categoryoptions' => [], 'categorynoneselected' => false,
            'total' => 0, 'shown' => 0, 'paginationbar' => '', 'articles' => [],
        ];

        $rows = $OUTPUT->render_from_template('local_moodesk/kb_articles', [
            'hasarticles' => true, 'hasfilters' => false, 'showtoolbar' => true, 'total' => 1, 'shown' => 1,
            'articles' => [[
                'id' => 7, 'title' => 'Reset your password', 'agentsonly' => true, 'agentsonlylabel' => 'Agents only',
                'hascategory' => true, 'categoryname' => 'Access', 'statuslabel' => 'Published', 'statusvariant' => 'success',
                'timemodified' => 'today', 'haseditor' => true, 'editorname' => 'Alba', 'viewurl' => '/v', 'editurl' => '/e',
            ]],
        ] + $base);
        $this->assertStringContainsString('Reset your password', $rows);
        $this->assertStringContainsString('mhd-pill--success', $rows);
        $this->assertStringContainsString('Agents only', $rows);
        $this->assertStringContainsString('by Alba', $rows);
        $this->assertStringContainsString('Showing 1 of 1 articles', $rows);
        $this->assertStringContainsString('name="status"', $rows, 'The filter toolbar renders with rows.');

        $empty = $OUTPUT->render_from_template('local_moodesk/kb_articles', [
            'hasarticles' => false, 'hasfilters' => false, 'showtoolbar' => false,
        ] + $base);
        $this->assertStringContainsString('No articles yet', $empty);
        $this->assertStringNotContainsString('name="status"', $empty, 'No toolbar on an empty knowledge base.');
        $this->assertStringNotContainsString('Nothing matches', $empty);

        $noresults = $OUTPUT->render_from_template('local_moodesk/kb_articles', [
            'hasarticles' => false, 'hasfilters' => true, 'showtoolbar' => true,
        ] + $base);
        $this->assertStringContainsString('Nothing matches these filters', $noresults);
        $this->assertStringContainsString('Clear filters', $noresults);
        $this->assertStringContainsString('name="status"', $noresults, 'The toolbar stays so the filters can be changed.');
        $this->assertStringNotContainsString('No articles yet', $noresults);
    }

    /**
     * The article template: the rendered body inside .mhd-prose, the footer of
     * administrative transitions only when asked for, the header's forms only
     * for the transitions that are on.
     */
    public function test_article_template_renders_body_and_transitions(): void {
        global $OUTPUT;
        $this->setAdminUser();

        $base = [
            'pageheader' => (new page_header('Reset your password'))->to_context(),
            'renderedbody' => '<p>Rendered</p>', 'hasbody' => true, 'isdraft' => false,
            'categoryname' => 'General', 'audiencelabel' => 'Everyone', 'agentsonly' => false,
            'timemodified' => 'today', 'haseditor' => false, 'editorname' => '', 'haspublished' => true,
            'timepublished' => 'yesterday', 'iseditor' => true, 'categoryurl' => '/c', 'actionurl' => '/a', 'sesskey' => 'sk',
            'breadcrumb' => ['label' => 'Breadcrumb', 'items' => [
                ['label' => 'Knowledge base', 'url' => '/kb', 'current' => false],
                ['label' => 'General', 'url' => '/c', 'current' => false],
                ['label' => 'Reset your password', 'url' => null, 'current' => true],
            ]],
            'cta' => ['show' => false, 'url' => '/s', 'text' => '', 'button' => ''],
            'canpublish' => false, 'canunpublish' => false, 'canarchive' => false, 'canrestore' => false,
            'candelete' => false, 'haslifecycle' => false,
        ];

        $reader = $OUTPUT->render_from_template('local_moodesk/kb_article', $base);
        $this->assertStringContainsString('<div class="mhd-prose"><p>Rendered</p></div>', $reader);
        $this->assertStringContainsString('First published', $reader);
        $this->assertStringNotContainsString('Manage status', $reader);
        $this->assertStringNotContainsString('<form', $reader, 'No transition forms without a transition.');

        $manager = $OUTPUT->render_from_template('local_moodesk/kb_article', [
            'canunpublish' => true, 'canarchive' => true, 'candelete' => true, 'haslifecycle' => true,
        ] + $base);
        $this->assertStringContainsString('Manage status', $manager);
        $this->assertStringContainsString('value="unpublish"', $manager);
        $this->assertStringContainsString('value="archive"', $manager);
        $this->assertStringContainsString('value="delete"', $manager);
        $this->assertStringContainsString('onsubmit="return confirm(', $manager);
        $this->assertStringContainsString('mhd-btn--outlined-danger', $manager);
        $this->assertStringNotContainsString('id="mhd-kb-action-publish"', $manager);

        $draft = $OUTPUT->render_from_template('local_moodesk/kb_article', [
            'hasbody' => false, 'isdraft' => true, 'haspublished' => false, 'canpublish' => true,
        ] + $base);
        $this->assertStringContainsString('id="mhd-kb-action-publish"', $draft);
        $this->assertStringContainsString('no content yet', $draft);
        $this->assertStringContainsString('before it can be published', $draft);
        $this->assertStringNotContainsString('mhd-prose', $draft);
    }

    /* ── KB-03: excerpt and highlight ─────────────────────────────────────────── */

    /**
     * Plain text keeps its case and its word boundaries; searchtext is the
     * same text lower-cased.
     */
    public function test_plain_text_and_searchtext_agree(): void {
        $body = '<h2>Steps</h2><p>Click <strong>Forgot&nbsp;password</strong>.</p><ul><li>One</li><li>Two</li></ul>';
        $this->assertSame('Steps Click Forgot password. One Two', kb_article::plain_text($body));
        $this->assertSame(\core_text::strtolower(kb_article::plain_text($body)), kb_article::derive_searchtext($body));
    }

    /**
     * An excerpt is short, cut between words, and centred on the first
     * search hit when there is one.
     */
    public function test_excerpt(): void {
        $short = '<p>Short body.</p>';
        $this->assertSame('Short body.', kb_ui::excerpt($short));

        $words = [];
        for ($i = 1; $i <= 80; $i++) {
            $words[] = "word$i";
        }
        $body = '<p>' . implode(' ', $words) . ' needle word81 word82 word83 word84 word85 word86 word87 word88 word89 word90</p>';

        $plain = kb_ui::excerpt($body);
        $this->assertStringStartsWith('word1 word2', $plain, 'No token: starts at the top.');
        $this->assertStringEndsWith('…', $plain);
        $this->assertLessThanOrEqual(kb_ui::EXCERPT_LENGTH + 1, \core_text::strlen($plain));
        $this->assertMatchesRegularExpression('~word\d+…$~', $plain, 'Cut between words, never inside one.');

        $centred = kb_ui::excerpt($body, ['needle']);
        $this->assertStringStartsWith('…', $centred, 'Cut at the front when it starts mid-text.');
        $this->assertStringContainsString('needle', $centred);
        $this->assertStringNotContainsString('word1 ', $centred);
    }

    /**
     * The safety order: escape, then mark the service's own tokens, in one
     * pass. A query shaped like a script is text on the way out, and a token
     * that happens to match "mark" never lands inside a <mark> we inserted.
     */
    public function test_highlight_escapes_before_marking(): void {
        $text = 'Reset <b>your</b> password & keep it';
        $html = kb_ui::highlight($text, kb_service::tokenize('password reset'));
        $this->assertSame('<mark>Reset</mark> &lt;b&gt;your&lt;/b&gt; <mark>password</mark> &amp; keep it', $html);

        // The mandatory one: a <script> query. The token is the tokeniser's
        // own, escaped like the text, so it can only ever match escaped text.
        $tokens = kb_service::tokenize('<script>alert(1)</script>');
        $this->assertSame(['<script>alert(1)</script>'], $tokens);
        $html = kb_ui::highlight('An article about <script>alert(1)</script> tags', $tokens);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame('An article about <mark>&lt;script&gt;alert(1)&lt;/script&gt;</mark> tags', $html);

        // One pass with every token as an alternative: "mar" cannot match the
        // <mark> tag a previous token inserted.
        $html = kb_ui::highlight('mark the mar', ['mark', 'mar']);
        $this->assertSame('<mark>mark</mark> the <mark>mar</mark>', $html);

        // Case-insensitive, original case kept; no tokens, plain escaping.
        $this->assertSame('<mark>Password</mark> policy', kb_ui::highlight('Password policy', ['password']));
        $this->assertSame('a &lt; b', kb_ui::highlight('a < b', []));

        // Regex metacharacters in a token are literal.
        $this->assertSame('cost <mark>(net)</mark>', kb_ui::highlight('cost (net)', ['(net)']));
    }
}
