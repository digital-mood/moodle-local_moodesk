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
 * Lets a plugin add sections to the ticket detail.
 *
 * Dispatched by {@see \local_moodesk\output\ticket_detail} when it builds its
 * context, for both mounts (the page and the drawer). There are three slots:
 *
 *  - sidebar: cards in the rail, after the base's own cards; without a rail
 *    (a requester's view) they render in the main column instead, where the
 *    base puts the custom fields;
 *  - composer: tools in the reply composer, after the knowledge base tool;
 *  - banner: notices above the conversation;
 *  - header: actions in the detail's header.
 *
 * A callback adds a section as a template and its context, and the detail
 * renders it in the order added (design rule d of
 * docs/two-plugin-split-plan.md §8.2). The callback decides who sees what: the
 * detail renders whatever it is given. With no section the markup is unchanged.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add sections to the mooDesk ticket detail (sidebar, composer, banner).')]
#[\core\attribute\tags('local_moodesk')]
final class ticket_detail_sections {
    /** Cards in the rail, or the main column without one. */
    const SLOT_SIDEBAR = 'sidebar';

    /** Tools in the reply composer. */
    const SLOT_COMPOSER = 'composer';

    /** Notices above the conversation. */
    const SLOT_BANNER = 'banner';

    /** Actions in the detail's header. */
    const SLOT_HEADER = 'header';

    /** Among the ticket's editable properties, after the department. */
    const SLOT_PROPERTIES = 'properties';

    /** Under the properties in the rail, in the main column when there is no rail. */
    const SLOT_FIELDS = 'fields';

    /** In the rail, between the properties and the ticket's administrative cards. */
    const SLOT_ACTIONS = 'actions';

    /** Among the pills under the ticket's subject. */
    const SLOT_BADGES = 'badges';

    /** @var array<string, array<int, array{template: string, context: array}>> Sections per slot, in order. */
    private array $sections = [
        self::SLOT_SIDEBAR => [],
        self::SLOT_COMPOSER => [],
        self::SLOT_BANNER => [],
        self::SLOT_HEADER => [],
        self::SLOT_PROPERTIES => [],
        self::SLOT_FIELDS => [],
        self::SLOT_ACTIONS => [],
        self::SLOT_BADGES => [],
    ];

    /** @var array<int, array{label: string, value: string}> Rows of the read-only property list. */
    private array $readonlyproperties = [];

    /**
     * Create the hook for one ticket and viewer.
     *
     * @param \stdClass $ticket    The ticket record the detail shows. Read it; do not change it.
     * @param int       $userid    The viewer.
     * @param bool      $canmanage Whether the viewer may manage the ticket (the rail renders only then).
     * @param bool      $isdrawer  True for the drawer mount, false for the page.
     * @param bool      $hascomposer Whether the composer renders.
     * @param bool      $canreply  Whether the viewer may reply here.
     * @param bool      $canaddnote Whether the viewer may add a note here.
     * @param bool      $isagent   Whether the viewer is an agent on this ticket.
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $ticket,
        /** @var int */
        public readonly int $userid,
        /** @var bool */
        public readonly bool $canmanage,
        /** @var bool */
        public readonly bool $isdrawer,
        /** @var bool */
        public readonly bool $hascomposer = false,
        /** @var bool */
        public readonly bool $canreply = false,
        /** @var bool */
        public readonly bool $canaddnote = false,
        /** @var bool */
        public readonly bool $isagent = false,
    ) {
    }

    /**
     * Add a row to the property list shown when the properties cannot be edited.
     *
     * @param string $label Its label.
     * @param string $value Its value, as text.
     */
    public function add_readonly_property(string $label, string $value): void {
        $this->readonlyproperties[] = ['label' => $label, 'value' => $value];
    }

    /**
     * The read-only rows added so far.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function get_readonly_properties(): array {
        return $this->readonlyproperties;
    }

    /**
     * Add a section to a slot.
     *
     * @param string $slot     One of the SLOT_* constants.
     * @param string $template A Mustache template name.
     * @param array  $context  Its context.
     * @throws \coding_exception On an unknown slot or an empty template name.
     */
    public function add_section(string $slot, string $template, array $context = []): void {
        if (!array_key_exists($slot, $this->sections)) {
            throw new \coding_exception("Unknown ticket detail slot: {$slot}");
        }
        if ($template === '') {
            throw new \coding_exception('A section template is required');
        }
        $this->sections[$slot][] = ['template' => $template, 'context' => $context];
    }

    /**
     * The sections of a slot, in order.
     *
     * @param string $slot One of the SLOT_* constants.
     * @return array<int, array{template: string, context: array}>
     * @throws \coding_exception On an unknown slot.
     */
    public function get_sections(string $slot): array {
        if (!array_key_exists($slot, $this->sections)) {
            throw new \coding_exception("Unknown ticket detail slot: {$slot}");
        }
        return $this->sections[$slot];
    }
}
