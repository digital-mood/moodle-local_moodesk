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
 * Lets a plugin add panels to the dashboard.
 *
 * Dispatched by dashboard.php once the base metrics are built, with the scope
 * the page applied. Panels render after the base's own, whether or not the
 * base found data for the period ({@see section_collection}).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add panels to the mooDesk dashboard.')]
#[\core\attribute\tags('local_moodesk')]
final class dashboard_panels extends section_collection {
    /** After Tickets by priority, the second half of that row. */
    const SLOT_AFTER_PRIORITY = 'afterpriority';

    /** Before Ticket aging, the first half of that row. */
    const SLOT_BEFORE_AGING = 'beforeaging';

    /** Before Recent activity. */
    const SLOT_BEFORE_ACTIVITY = 'beforeactivity';

    /** @var array<string, array<int, array{template: string, context: array}>> Panels placed among the base's. */
    private array $panels = [
        self::SLOT_AFTER_PRIORITY => [],
        self::SLOT_BEFORE_AGING => [],
        self::SLOT_BEFORE_ACTIVITY => [],
    ];

    /** @var array[] KPI tiles, in the context dashboard_service gives its own. */
    private array $kpis = [];

    /**
     * Create the hook for one dashboard.
     *
     * @param \local_moodesk\domain\dashboard_filter $filter   The scope the page applied, plugin criteria included.
     * @param string                                $viewmode One of the dashboard_service::VIEW_* constants.
     * @param int                                   $userid   The viewer.
     */
    public function __construct(
        /** @var \local_moodesk\domain\dashboard_filter */
        public readonly \local_moodesk\domain\dashboard_filter $filter,
        /** @var string */
        public readonly string $viewmode,
        /** @var int */
        public readonly int $userid,
    ) {
    }

    /**
     * Add a panel among the base's, shown only when the period has data.
     *
     * @param string $slot     One of the SLOT_* constants.
     * @param string $template A Mustache template name.
     * @param array  $context  Its context.
     * @throws \coding_exception On an unknown slot.
     */
    public function add_panel(string $slot, string $template, array $context = []): void {
        if (!array_key_exists($slot, $this->panels)) {
            throw new \coding_exception("Unknown dashboard slot: {$slot}");
        }
        $this->panels[$slot][] = ['template' => $template, 'context' => $context];
    }

    /**
     * Render the panels of a slot, after the hook was dispatched.
     *
     * @param string $slot One of the SLOT_* constants.
     * @param \renderer_base|\core\output\bootstrap_renderer $output
     * @return array<int, array{html: string}>
     */
    public function render_slot(string $slot, $output): array {
        $html = [];
        foreach ($this->panels[$slot] as $panel) {
            $html[] = ['html' => $output->render_from_template($panel['template'], $panel['context'])];
        }
        return $html;
    }

    /**
     * Add a tile to the KPI row.
     *
     * @param array $kpi Same shape as the base's tiles.
     */
    public function add_kpi(array $kpi): void {
        $this->kpis[] = $kpi;
    }

    /**
     * The tiles plugins added.
     *
     * @return array[]
     */
    public function get_kpis(): array {
        return $this->kpis;
    }
}
