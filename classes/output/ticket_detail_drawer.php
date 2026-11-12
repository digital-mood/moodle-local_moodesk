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
 * The overlay panel that hosts the ticket detail on the ticket list.
 *
 * This class owns the empty shell only. The detail itself is delivered by the
 * ticket_detail fragment when a row is opened, so the list page carries no
 * ticket data it may never need, and the editor and file manager inside the
 * composer are initialised by Moodle's own fragment plumbing rather than by
 * anything custom.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_detail_drawer {
    /**
     * Render the panel shell and queue its behaviour module.
     *
     * @param \moodle_page $page Usually the global $PAGE.
     * @return string Drawer HTML, to be appended to the page content.
     */
    public function render(\moodle_page $page): string {
        global $OUTPUT;

        $page->requires->js_call_amd('local_moodesk/ticket_detail_drawer', 'init');

        return $OUTPUT->render_from_template('local_moodesk/ticket_detail_drawer', [
            'contextid'    => \context_system::instance()->id,
            'label'        => get_string('detail_drawer_label', 'local_moodesk'),
            'loadinglabel' => get_string('detail_drawer_loading', 'local_moodesk'),
            'errorlabel'   => get_string('detail_drawer_error', 'local_moodesk'),
            // The error state's escape hatch: the ticket's own page once one is
            // known, the list until then.
            'fullpagelabel' => get_string('detail_open_full_page', 'local_moodesk'),
            'closelabel'    => get_string('drawer_close', 'local_moodesk'),
            'listurl'       => (new \moodle_url('/local/moodesk/index.php'))->out(false),
            'unsavedconfirm' => get_string('detail_unsaved_confirm', 'local_moodesk'),
        ]);
    }
}
