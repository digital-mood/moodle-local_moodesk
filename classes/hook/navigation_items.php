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

use local_moodesk\service\nav_item;

/**
 * Lets a plugin add entries to the mooDesk navigation.
 *
 * Dispatched by {@see \local_moodesk\service\navigation_service::get_catalogue()}
 * with the catalogue the base built for the current user, after its own
 * capability and edition gating. A callback adds its items, already filtered
 * for that user, either at the end or right after an existing key so the
 * sidebar keeps its order. Not dispatched when the user has no mooDesk access.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add entries to the mooDesk navigation.')]
#[\core\attribute\tags('local_moodesk', 'navigation')]
final class navigation_items {
    /** @var nav_item[] The catalogue, in order. */
    private array $items;

    /**
     * Create the hook with the base's catalogue.
     *
     * @param \context   $context The context the catalogue was built for.
     * @param nav_item[] $items   The base's catalogue, in order.
     */
    public function __construct(
        /** @var \context */
        public readonly \context $context,
        array $items,
    ) {
        $this->items = array_values($items);
    }

    /**
     * Add an item.
     *
     * @param nav_item    $item     The entry to add.
     * @param string|null $afterkey Insert right after the top-level item with this key;
     *                              at the end when null or when no item has that key.
     * @param string|null $beforekey Insert right before the top-level item with this key,
     *                              when no $afterkey placed it.
     * @throws \coding_exception When an item with the same key is already there.
     */
    public function add_item(nav_item $item, ?string $afterkey = null, ?string $beforekey = null): void {
        foreach ($this->items as $existing) {
            if ($existing->key === $item->key) {
                throw new \coding_exception("Duplicate mooDesk navigation key: {$item->key}");
            }
        }
        if ($afterkey !== null) {
            foreach ($this->items as $index => $existing) {
                if ($existing->key === $afterkey) {
                    array_splice($this->items, $index + 1, 0, [$item]);
                    return;
                }
            }
        }
        if ($beforekey !== null) {
            foreach ($this->items as $index => $existing) {
                if ($existing->key === $beforekey) {
                    array_splice($this->items, $index, 0, [$item]);
                    return;
                }
            }
        }
        $this->items[] = $item;
    }

    /**
     * The catalogue as it stands now, in order.
     *
     * @return nav_item[]
     */
    public function get_items(): array {
        return $this->items;
    }
}
