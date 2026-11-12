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

namespace local_moodesk\hook;

/**
 * Lets a plugin add sections to a knowledge base article.
 *
 * Dispatched by {@see \local_moodesk\output\kb_article_view::export()} for an
 * article the viewer may read. Sections render after the article body and its
 * feedback control, before the way out ({@see section_collection}).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add sections to a mooDesk knowledge base article.')]
#[\core\attribute\tags('local_moodesk')]
final class kb_article_view_extend extends section_collection {
    /** @var array<int, array{template: string, context: array}> Sections above the article. */
    private array $top = [];

    /** @var array<int, array{template: string, context: array}> Facts in the meta line. */
    private array $facts = [];

    /**
     * Create the hook for one article and reader.
     *
     * @param \stdClass $article The article record. Read it; do not change it.
     * @param int       $actorid The reader.
     * @param bool      $canedit Whether the reader may edit the knowledge base.
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $article,
        /** @var int */
        public readonly int $actorid,
        /** @var bool */
        public readonly bool $canedit,
    ) {
    }

    /**
     * Add a section under the page header, above the article.
     *
     * @param string $template A Mustache template name.
     * @param array  $context  Its context.
     */
    public function add_top(string $template, array $context = []): void {
        $this->top[] = ['template' => $template, 'context' => $context];
    }

    /**
     * Add a fact to the article's meta line. The page wraps it in a
     * `.mhd-kb-article__fact` block; the template renders only the value.
     *
     * @param string $label    The fact's label, already translated.
     * @param string $template A Mustache template name.
     * @param array  $context  Its context.
     */
    public function add_fact(string $label, string $template, array $context = []): void {
        $this->facts[] = ['label' => $label, 'template' => $template, 'context' => $context];
    }

    /**
     * The sections added with add_top().
     *
     * @return array<int, array{template: string, context: array}>
     */
    public function get_top(): array {
        return $this->top;
    }

    /**
     * The facts added with add_fact().
     *
     * @return array<int, array{label: string, template: string, context: array}>
     */
    public function get_facts(): array {
        return $this->facts;
    }

    /**
     * Render the sections added with add_top(), after the hook was dispatched.
     *
     * @param \renderer_base|\core\output\bootstrap_renderer $output
     * @return array<int, array{html: string}>
     */
    public function render_top($output): array {
        return array_map(
            static fn(array $s): array => ['html' => $output->render_from_template($s['template'], $s['context'])],
            $this->top
        );
    }

    /**
     * Render the facts added with add_fact(), after the hook was dispatched.
     *
     * @param \renderer_base|\core\output\bootstrap_renderer $output
     * @return array<int, array{label: string, html: string}>
     */
    public function render_facts($output): array {
        return array_map(
            static fn(array $s): array => [
                'label' => $s['label'],
                'html'  => $output->render_from_template($s['template'], $s['context']),
            ],
            $this->facts
        );
    }
}
