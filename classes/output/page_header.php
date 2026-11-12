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

use coding_exception;
use renderable;
use renderer_base;
use templatable;

/**
 * The Page Header: the one band at the top of every mooDesk screen.
 *
 *   Title                                    [Secondary] [Primary]
 *   Optional description
 *
 * The title is required. Description, actions and the meta slot are not, and
 * nothing renders a placeholder for the ones that are absent.
 *
 * ── Why this is a builder and not an array ───────────────────────────────────
 *
 * Because ORDER AND EMPHASIS ARE THE POINT, and an array cannot hold them.
 *
 * The product reached twenty-eight screens with eight different header idioms
 * before this class existed. Two of those screens put the filled button to the
 * LEFT of the outlined one, and one of them inverted a second time when it
 * stacked on a phone. That did not happen because anyone decided it should; it
 * happened because every screen spelled the row out by hand and nothing in the
 * markup said which button was the primary one.
 *
 * So the primary action is a NAMED SLOT, not a position in a list:
 *
 *   - with_action()          appends a SECONDARY action. Rendered outlined.
 *   - with_primary_action()  sets THE primary action. Rendered filled, and
 *                            rendered last, by construction.
 *
 * A caller cannot express "primary first" and cannot express "outlined primary",
 * because neither the order nor the variant is theirs to pass. The invariant
 * stops being a convention people remember and becomes a property of the type.
 *
 * ── What does NOT belong here ────────────────────────────────────────────────
 *
 * Section chrome one level down: `.mhd-datatable-title` (a panel heading),
 * `.mhd-panel-head` (a dashboard card), `.mhd-report-header` (a report section)
 * and `.mhd-detail__header` (the ticket's own identity — pills, subject, meta,
 * shared verbatim with the drawer). Those answer "what is this section" or
 * "what is this ticket". This answers "what is this page", and there is exactly
 * one of it per screen.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class page_header implements renderable, templatable {
    /** @var string The page title. Required, and the only required part. */
    private string $title;

    /** @var string|null One line under the title, or null. */
    private ?string $description = null;

    /** @var header_action[] Secondary actions, left to right. */
    private array $actions = [];

    /** @var header_action|null The one primary action, or null. */
    private ?header_action $primaryaction = null;

    /** @var string|null Non-interactive right-hand content (a status pill), or null. */
    private ?string $meta = null;

    /**
     * Start a header for the given page.
     *
     * @param string $title Already-localised page title. Must not be empty.
     * @throws coding_exception If the title is empty.
     */
    public function __construct(string $title) {
        if (trim($title) === '') {
            throw new coding_exception('A page header must have a title.');
        }
        $this->title = $title;
    }

    /**
     * Add the optional line under the title.
     *
     * Plain text: it is escaped on output. A description that wants a link is a
     * description doing an action's job — give the link to with_action() instead.
     *
     * @param string $description Already-localised description.
     * @return static
     */
    public function with_description(string $description): static {
        $this->description = $description;
        return $this;
    }

    /**
     * Append a secondary action. Rendered outlined, to the left of the primary.
     *
     * @param header_action $action The action.
     * @return static
     */
    public function with_action(header_action $action): static {
        $this->actions[] = $action;
        return $this;
    }

    /**
     * Set the one primary action. Rendered filled, and always the last control
     * in the row.
     *
     * Calling this twice replaces rather than appends: a page has one primary
     * action or none, and two filled buttons in one header is the ambiguity the
     * whole component exists to remove.
     *
     * @param header_action $action The action.
     * @return static
     */
    public function with_primary_action(header_action $action): static {
        $this->primaryaction = $action;
        return $this;
    }

    /**
     * Set non-interactive right-hand content — today, the health screen's state
     * pill. Rendered before the actions, and never styled as a button.
     *
     * @param string $html Already-rendered, already-escaped HTML.
     * @return static
     */
    public function with_meta(string $html): static {
        $this->meta = $html;
        return $this;
    }

    /**
     * Render to HTML, ready to concatenate.
     *
     * Most screens hand the context to their own template under a `pageheader`
     * key and include the partial from there. This is for the screens that have
     * no template of their own to put it in: an edition gate that returns the
     * upgrade prompt and nothing else, and a page whose whole body is a
     * moodleform. Same component, same markup, same place on the screen — only
     * the seam differs.
     *
     * @return string
     */
    public function render(): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_moodesk/page_header', $this->to_context());
    }

    /**
     * Flatten to template context.
     *
     * THIS is what an entry point calls, not export_for_template().
     *
     * The templatable contract types its parameter as renderer_base, and at the
     * point a product screen builds its context $OUTPUT is not one yet — it is
     * core\output\bootstrap_renderer, the lazy stand-in Moodle keeps there until
     * the theme is set up. Passing it is a TypeError, and it is a TypeError that
     * only appears in a browser: under PHPUnit the same call has the same
     * problem, which is a warning worth heeding rather than working around.
     *
     * Nothing in the export needs a renderer, so nothing here asks for one.
     *
     * @return array
     */
    public function to_context(): array {
        $actions = [];
        foreach ($this->actions as $action) {
            $actions[] = $action->export('outlined');
        }

        return [
            'title' => $this->title,
            'description' => $this->description,
            'meta' => $this->meta,
            'actions' => $actions,
            'primaryaction' => $this->primaryaction?->export('filled'),
            // One flag for the whole right-hand zone, so a header with neither
            // actions nor meta emits no element for it at all.
            'hasactions' => !empty($actions) || $this->primaryaction !== null || $this->meta !== null,
        ];
    }

    /**
     * Satisfy the templatable contract.
     *
     * Kept so the class can be handed to a renderer that expects a templatable.
     * Screens should call {@see to_context()} instead — see the note there.
     *
     * @param renderer_base $output The renderer. Unused.
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return $this->to_context();
    }
}
