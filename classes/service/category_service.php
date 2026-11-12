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
 * Category application service.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\repository\category_repository;

/**
 * Category application service.
 *
 * CONTRACT:
 *  - All category business logic lives here.
 *  - Capability checks happen first in every public admin method.
 *  - No $DB access directly — everything routes through category_repository.
 *
 * NOTE on schema: local_moodesk_category has no timemodified column.
 * Only fields present in the data object are sent to the repository.
 */
class category_service {
    /** @var category_repository */
    private category_repository $repo;

    /**
     * Constructor.
     *
     * @param category_repository $repo
     */
    public function __construct(category_repository $repo) {
        $this->repo = $repo;
    }

    /**
     * Build a fully-wired service for production use.
     */
    public static function create(): self {
        return new self(new category_repository());
    }

    /*
     * -------------------------------------------------------------------------
     * Read
     * -------------------------------------------------------------------------
     */

    /**
     * Return active categories as an id => name map (for select elements).
     */
    public function get_active_menu(): array {
        return $this->repo->get_active_menu();
    }

    /**
     * Return all active category records.
     *
     * @return \stdClass[]
     */
    public function get_all_active(): array {
        return $this->repo->get_all_active();
    }

    /**
     * Return an id => name map of every category, archived ones included.
     *
     * For labelling something that references a category — an article filed
     * under one that has since been archived still has to say which. Names
     * only, so no capability: a category name is what every requester already
     * sees in the New Ticket form.
     *
     * @return array<int, string>
     */
    public function get_name_map(): array {
        $names = [];
        foreach ($this->repo->get_all() as $category) {
            $names[(int) $category->id] = (string) $category->name;
        }
        return $names;
    }

    /**
     * Return all category records (active and archived) for admin views.
     *
     * @param int $actorid User performing the action.
     * @return \stdClass[]
     */
    public function get_all(int $actorid): array {
        require_capability('local/moodesk:managecategory', \context_system::instance(), $actorid);
        return $this->repo->get_all();
    }

    /**
     * Return a single category by ID.
     *
     * @param int $id      Record ID.
     * @param int $actorid User performing the action.
     * @throws \moodle_exception If not found.
     */
    public function get_category(int $id, int $actorid): \stdClass {
        require_capability('local/moodesk:managecategory', \context_system::instance(), $actorid);
        $cat = $this->repo->get_by_id($id);
        if (!$cat) {
            throw new \moodle_exception('categorynotfound', 'local_moodesk');
        }
        return $cat;
    }

    /*
     * -------------------------------------------------------------------------
     * Write
     * -------------------------------------------------------------------------
     */

    /**
     * Create a new category.
     *
     * @param \stdClass $data    Must have: name. Optional: description, sortorder.
     * @param int       $actorid User performing the action.
     * @return int  New category ID.
     */
    public function create_category(\stdClass $data, int $actorid): int {
        require_capability('local/moodesk:managecategory', \context_system::instance(), $actorid);

        if (empty(trim($data->name ?? ''))) {
            throw new \moodle_exception('missingparam', 'local_moodesk', '', 'name');
        }

        $record               = new \stdClass();
        $record->name         = clean_param(trim($data->name), PARAM_TEXT);
        $record->description  = clean_param(trim($data->description ?? ''), PARAM_TEXT);
        $record->sortorder    = isset($data->sortorder) ? max(0, (int) $data->sortorder) : 0;
        $record->visible      = 1;
        $record->timecreated  = time();
        $record->usermodified = $actorid;

        return $this->repo->insert($record);
    }

    /**
     * Update an existing category.
     *
     * @param int       $id      Record ID.
     * @param \stdClass $data    Submitted values.
     * @param int       $actorid User performing the action.
     * @throws \moodle_exception If category does not exist.
     */
    public function update_category(int $id, \stdClass $data, int $actorid): void {
        require_capability('local/moodesk:managecategory', \context_system::instance(), $actorid);

        if (!$this->repo->exists($id)) {
            throw new \moodle_exception('categorynotfound', 'local_moodesk');
        }

        $update               = new \stdClass();
        $update->id           = $id;
        $update->usermodified = $actorid;

        if (isset($data->name)) {
            $update->name = clean_param(trim($data->name), PARAM_TEXT);
        }
        if (property_exists($data, 'description')) {
            $update->description = clean_param(trim((string) $data->description), PARAM_TEXT);
        }
        if (isset($data->sortorder)) {
            $update->sortorder = max(0, (int) $data->sortorder);
        }
        if (isset($data->visible)) {
            $update->visible = (int) (bool) $data->visible;
        }

        $this->repo->update($update);
    }

    /**
     * Toggle the visible flag on a category.
     *
     * The default category (config: defaultcategoryid) cannot be archived
     * — it's the fallback target for uncategorised tickets.
     *
     * @param int $id      Record ID.
     * @param int $actorid User performing the action.
     * @throws \moodle_exception If trying to archive the default category.
     */
    public function toggle_visible(int $id, int $actorid): void {
        require_capability('local/moodesk:managecategory', \context_system::instance(), $actorid);

        $cat       = $this->get_category($id, $actorid);
        $defaultid = (int) get_config('local_moodesk', 'defaultcategoryid');

        if ((int) $cat->id === $defaultid && (int) $cat->visible === 1) {
            throw new \moodle_exception('cat_default_cannot_archive', 'local_moodesk');
        }

        $update               = new \stdClass();
        $update->id           = $id;
        $update->visible      = (int) $cat->visible ? 0 : 1;
        $update->usermodified = $actorid;

        $this->repo->update($update);
    }
}
