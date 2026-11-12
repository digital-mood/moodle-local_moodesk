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

/**
 * How a plain-text message of the conversation becomes HTML: escaped, line-
 * broken, and with its URLs as links — and nothing else.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\output\thread_message
 */
final class thread_message_test extends \advanced_testcase {
    /** @var \context_system */
    private \context_system $context;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->context = \context_system::instance();
    }

    /**
     * Render one plain-text message.
     *
     * @param string $text
     * @return string
     */
    private function plain(string $text): string {
        return thread_message::format($text, FORMAT_PLAIN, $this->context);
    }

    public function test_a_url_becomes_a_link_to_itself(): void {
        $html = $this->plain('https://example.com/article');

        $this->assertSame(
            '<a href="https://example.com/article" class="_blanktarget">https://example.com/article</a>',
            $html
        );
    }

    public function test_an_inserted_article_keeps_its_title_as_text_and_its_url_as_a_link(): void {
        $html = $this->plain("Resetting your password: https://site.example/local/moodesk/kb_article.php?id=123\n");

        $this->assertStringStartsWith('Resetting your password: <a href=', $html);
        $this->assertStringContainsString(
            '<a href="https://site.example/local/moodesk/kb_article.php?id=123" class="_blanktarget">'
            . 'https://site.example/local/moodesk/kb_article.php?id=123</a>',
            $html
        );
        // The line break after the link, as the composer inserts it, survives
        // and closes the URL: nothing after it is swept into the href.
        $this->assertStringEndsWith("</a><br />\n", $html);
    }

    public function test_a_script_tag_is_text_and_never_markup(): void {
        $html = $this->plain('<script>alert(1)</script>');

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_html_looking_text_stays_plain(): void {
        $html = $this->plain('<b>hello</b> <a href="https://evil.example/">x</a>');

        // Typed markup is text: escaped once, never a tag.
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;hello&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;a href=&quot;', $html);
        $this->assertStringNotContainsString('<a href="https://evil.example/">x</a>', $html);
        // The one tag in the output is the link the matcher made from the bare
        // URL, and what it made it from is the escaped text: whatever the
        // href now carries is entities, so it can neither close the attribute
        // nor open a tag. (The core matcher does sweep the entities that
        // follow the URL into it — an inherited edge of typed-in markup.)
        $this->assertSame(1, substr_count($html, '<a href="'));
        $this->assertSame(2, substr_count($html, '<'), 'the generated link, open and close, are the only tags');
        $this->assertMatchesRegularExpression('~<a href="https://evil\.example/[^"<>]*" class="_blanktarget">~', $html);
    }

    public function test_an_ampersand_is_encoded_exactly_once(): void {
        $this->assertSame('A &amp; B', $this->plain('A & B'));
    }

    public function test_a_query_string_keeps_its_ampersand_in_the_href_and_on_screen(): void {
        $html = $this->plain('https://example.com/?a=1&b=2');

        $this->assertSame(
            '<a href="https://example.com/?a=1&amp;b=2" class="_blanktarget">https://example.com/?a=1&amp;b=2</a>',
            $html
        );
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_quotes_and_unicode_render_as_before(): void {
        $html = $this->plain('Login & "SSO" café — ünïcödé é');

        $this->assertSame('Login &amp; &quot;SSO&quot; café — ünïcödé é', $html);
    }

    public function test_line_breaks_are_kept_and_close_a_url(): void {
        $html = $this->plain("first https://a.example/x\nsecond line");

        $this->assertSame(
            "first <a href=\"https://a.example/x\" class=\"_blanktarget\">https://a.example/x</a><br />\nsecond line",
            $html
        );
    }

    public function test_trailing_punctuation_stays_outside_the_link(): void {
        $html = $this->plain('See http://x.example/a. Or (http://y.example/b), done;');

        $this->assertStringContainsString('<a href="http://x.example/a" class="_blanktarget">http://x.example/a</a>.', $html);
        $this->assertStringContainsString('(<a href="http://y.example/b" class="_blanktarget">http://y.example/b</a>),', $html);
    }

    public function test_text_that_is_not_a_url_is_left_alone(): void {
        $text = 'no url here: mailto:a@b.example ftp://x.example/ example.com a/b c:d';

        $this->assertSame($text, $this->plain($text));
    }

    public function test_an_image_address_is_a_link_and_never_an_embedded_image(): void {
        // The core filter's own option, on: it would swap the link for an <img>.
        set_config('embedimages', 1, 'filter_urltolink');

        $html = $this->plain('http://x.example/pic.png');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertSame(
            '<a href="http://x.example/pic.png" class="_blanktarget">http://x.example/pic.png</a>',
            $html
        );
    }

    public function test_links_do_not_depend_on_the_site_s_filter_settings(): void {
        // Filter off site-wide and Plain not among its formats: the
        // conversation still links, because this is the product's rule, not
        // the admin's filter configuration.
        filter_set_global_state('urltolink', TEXTFILTER_DISABLED);
        set_config('formats', (string) FORMAT_HTML, 'filter_urltolink');

        $this->assertStringContainsString('<a href="https://a.example/"', $this->plain('https://a.example/'));
    }

    public function test_other_formats_are_format_text_and_nothing_more(): void {
        $expected = format_text('<b>x</b> plain', FORMAT_HTML, ['context' => $this->context]);

        $this->assertSame($expected, thread_message::format('<b>x</b> plain', FORMAT_HTML, $this->context));
    }

    public function test_an_empty_message_is_empty(): void {
        $this->assertSame('', $this->plain(''));
    }
}
