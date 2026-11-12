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

use moodle_url;

/**
 * One action in a {@see page_header}.
 *
 * A value object, and deliberately a thin one. Note what it does NOT carry:
 * a colour variant and a size.
 *
 * The variant is decided by the SLOT the action goes into, not by the caller —
 * `page_header::with_action()` renders outlined, `with_primary_action()` renders
 * filled. That is the whole reason this class exists rather than a plain array:
 * before it, eleven screens spelled a header button by hand and two of them
 * spelled the primary one first, in the wrong colour. Making the variant
 * unreachable from the call site is what stops that recurring — the same move
 * components/_button.scss made when it stopped letting screens declare a size.
 *
 * The size is likewise not here: every header action is the Button's default
 * size, and tests/button_markup_test.php already forbids any file but the
 * component from having an opinion about a button's dimensions.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class header_action {
    /** @var string The sprite id for the plus glyph carried by every "create" action. */
    public const ICON_PLUS = 'plus';

    /**
     * Describe one control in a page header.
     *
     * @param string         $label      Already-localised label. Also the accessible name
     *                                   when the action renders icon-only.
     * @param moodle_url|null $url       Destination. Null renders a <button type="button">,
     *                                   which is what a JS-driven action wants.
     * @param string|null    $icon       Sprite id without the `mhd-icon-` prefix, or null.
     * @param array          $attributes Extra HTML attributes as name => value. This is how
     *                                   progressive enhancement survives the move into the
     *                                   component: `data-action`, `target`, `rel`.
     * @param bool           $icononly   Hide the label visually and expose it as the
     *                                   accessible name instead. Requires an icon.
     * @param string|null    $form       Id of a form already on the page that this action
     *                                   submits. Renders a <button type="submit" form="…">.
     *                                   Mutually exclusive with $url.
     * @param bool           $destructive Whether the action destroys or withdraws something.
     *                                   The slot still picks filled or outlined; this only
     *                                   moves the pick to the danger colour of that slot.
     */
    public function __construct(
        /** @var string Already-localised label. */
        public readonly string $label,
        /** @var moodle_url|null Destination, or null for a button. */
        public readonly ?moodle_url $url = null,
        /** @var string|null Sprite id without the `mhd-icon-` prefix. */
        public readonly ?string $icon = null,
        /** @var array Extra HTML attributes as name => value. */
        public readonly array $attributes = [],
        /** @var bool Whether the label is hidden and used as the accessible name. */
        public readonly bool $icononly = false,
        /** @var string|null Id of the form on the page this action submits, or null. */
        public readonly ?string $form = null,
        /** @var bool Whether the action is destructive. */
        public readonly bool $destructive = false,
    ) {
        if ($this->url !== null && $this->form !== null) {
            throw new \coding_exception('A header action either navigates or submits a form, not both.');
        }
    }

    /**
     * An action that submits a form already on the page.
     *
     * For a state change that is a POST — publish, archive, delete — rather than
     * a navigation. The form itself (its action, its hidden fields, its sesskey,
     * any confirm() it wants) is the screen's, rendered in its own template with
     * an id; the header only carries the button that submits it, so the
     * primary slot can hold a transition without the header learning what a
     * transition is.
     *
     * @param string      $label       Already-localised label.
     * @param string      $formid      The id attribute of the form to submit.
     * @param string|null $icon        Sprite id without the `mhd-icon-` prefix, or null.
     * @param bool        $destructive Whether the action destroys or withdraws something.
     * @param bool        $icononly    Hide the label and use it as the accessible name.
     * @return self
     */
    public static function submit(
        string $label,
        string $formid,
        ?string $icon = null,
        bool $destructive = false,
        bool $icononly = false
    ): self {
        return new self($label, null, $icon, [], $icononly, $formid, $destructive);
    }

    /**
     * A "create a new <entity>" action: the plus glyph alone, no visible label.
     *
     * Every screen that creates something goes through here, so the product has
     * one answer to "what does a New button look like" instead of nine. The
     * label is not discarded — it becomes the button's accessible name and its
     * tooltip, so the control still announces "New department" to a screen
     * reader and still says so on hover.
     *
     * Use this ONLY for actions that create a new entity. An action that merely
     * opens a form, navigates, or edits something existing is an ordinary
     * action and keeps its visible label.
     *
     * @param string     $label      Already-localised label, e.g. "New department".
     * @param moodle_url $url        Where the action goes.
     * @param array      $attributes Extra HTML attributes as name => value.
     * @return self
     */
    public static function create_new(string $label, moodle_url $url, array $attributes = []): self {
        return new self($label, $url, self::ICON_PLUS, $attributes, true);
    }

    /**
     * Flatten to template context.
     *
     * @param string $variant Button colour variant, supplied by the slot: 'filled' or 'outlined'.
     * @return array
     */
    public function export(string $variant): array {
        $attributes = [];
        foreach ($this->attributes as $name => $value) {
            $attributes[] = ['name' => $name, 'value' => $value];
        }

        // The slot's variant, moved to its danger colour when the action is
        // destructive. Still the slot's decision: filled stays filled, outlined
        // stays outlined — only the hue says "this one takes something away".
        if ($this->destructive) {
            $variant = $variant === 'filled' ? 'danger' : 'outlined-danger';
        }

        return [
            'label' => $this->label,
            'url' => $this->url ? $this->url->out(false) : null,
            'hasurl' => $this->url !== null,
            'form' => $this->form,
            'hasform' => $this->form !== null,
            'icon' => $this->icon,
            'hasicon' => $this->icon !== null,
            'icononly' => $this->icononly,
            'variant' => $variant,
            'attributes' => $attributes,
            'hasattributes' => !empty($attributes),
        ];
    }
}
