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
 * Category repository: all database access for local_moodesk_category.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\repository;

/**
 * Data access layer for local_moodesk_category.
 *
 * CONTRACT:
 *  - This is the only class allowed to query local_moodesk_category.
 *  - No capability checks. No business logic. No events.
 *  - Returns raw stdClass objects or arrays as delivered by Moodle DML.
 *
 * Schema note: local_moodesk_category has no timemodified column.
 * Updates only touch the fields explicitly included in the data object.
 */
class category_repository {
    /** @var \moodle_database */
    private \moodle_database $db;

    /** Name of the local_moodesk_category table. */
    private const TABLE = 'local_moodesk_category';

    /**
     * Build the repository, falling back to the global database handle.
     *
     * @param \moodle_database|null $db  Injected for testing; falls back to global $DB.
     */
    public function __construct(?\moodle_database $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /*
     * -------------------------------------------------------------------------
     * Read
     * -------------------------------------------------------------------------
     */

    /**
     * Fetch a single category by ID. Returns null if not found.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public function get_by_id(int $id): ?\stdClass {
        $record = $this->db->get_record(self::TABLE, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Return all categories (active and archived), ordered by sortorder ASC, name ASC.
     *
     * @return \stdClass[]
     */
    public function get_all(): array {
        return array_values(
            $this->db->get_records(self::TABLE, [], 'sortorder ASC, name ASC')
        );
    }

    /**
     * Return only active (visible=1) categories, ordered by sortorder ASC, name ASC.
     *
     * @return \stdClass[]
     */
    public function get_all_active(): array {
        return array_values(
            $this->db->get_records(self::TABLE, ['visible' => 1], 'sortorder ASC, name ASC')
        );
    }

    /**
     * Return an id => name map of active categories.
     * Convenient for building select element options.
     *
     * @return array<int, string>
     */
    public function get_active_menu(): array {
        return $this->db->get_records_menu(
            self::TABLE,
            ['visible' => 1],
            'sortorder ASC, name ASC',
            'id, name'
        );
    }

    /**
     * Return true if a category with the given ID exists.
     *
     * @param int $id
     * @return bool
     */
    public function exists(int $id): bool {
        return $this->db->record_exists(self::TABLE, ['id' => $id]);
    }

    /**
     * Return true if an active category with the given ID exists.
     *
     * @param int $id
     * @return bool
     */
    public function exists_active(int $id): bool {
        return $this->db->record_exists(self::TABLE, ['id' => $id, 'visible' => 1]);
    }

    /*
     * -------------------------------------------------------------------------
     * Write
     * -------------------------------------------------------------------------
     */

    /**
     * Insert a new category. Returns the new ID.
     *
     * @param \stdClass $data
     * @return int
     */
    public function insert(\stdClass $data): int {
        return (int) $this->db->insert_record(self::TABLE, $data);
    }

    /**
     * Update an existing category. $data must include 'id'.
     *
     * @param \stdClass $data
     * @return bool
     */
    public function update(\stdClass $data): bool {
        return $this->db->update_record(self::TABLE, $data);
    }
}
