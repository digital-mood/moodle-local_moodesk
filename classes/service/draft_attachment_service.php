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

namespace local_moodesk\service;

/**
 * What a user's draft file area currently holds, and how to remove one file from it.
 *
 * The reply composer draws its own attachment chips instead of rendering
 * Moodle's file manager (ADR: docs/reply-composer-attachments.md), so it needs a
 * way to ask what is actually in the draft. Moodle offers no supported
 * client-side answer: the file manager exposes no instance, and the
 * core_files_* external functions are web-service-token only — none of them
 * declares 'ajax' => true, so core/ajax refuses them. This service is that
 * answer, built on the File API the file manager itself uses.
 *
 * Ownership is structural rather than checked. Every call resolves the area
 * against the *current user's* context, so a draft itemid belonging to anybody
 * else simply does not resolve — there is no area to read and no file to
 * delete. This is the same guarantee Moodle's own repository/draftfiles_ajax.php
 * relies on.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft_attachment_service {
    /**
     * Build the service.
     *
     * @return self
     */
    public static function create(): self {
        return new self();
    }

    /**
     * The files the current user's draft area holds, in display order.
     *
     * Directories are excluded: the composer's draft areas are created with
     * subdirs off, so a folder here would be something the user could neither
     * have made nor do anything with.
     *
     * @param int $draftitemid The draft area to read.
     * @return array[] One entry per file: filename, filepath, size, sizeformatted, url.
     */
    public function list_files(int $draftitemid): array {
        global $USER;

        if ($draftitemid <= 0) {
            return [];
        }

        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            'filename',
            false
        );

        $out = [];
        foreach ($files as $file) {
            $out[] = [
                'filename'       => $file->get_filename(),
                'filepath'       => $file->get_filepath(),
                'size'           => (int) $file->get_filesize(),
                'sizeformatted'  => display_size($file->get_filesize()),
                'url'            => \moodle_url::make_draftfile_url(
                    $draftitemid,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false),
            ];
        }

        return $out;
    }

    /**
     * Remove one file from the current user's draft area.
     *
     * Missing is not an error. A chip the user clicks twice, or a delete racing
     * a re-sync, should leave the draft in the state the user asked for rather
     * than raising — and the state they asked for is "that file is gone".
     *
     * @param int    $draftitemid The draft area to delete from.
     * @param string $filepath    Path within the area, e.g. '/'.
     * @param string $filename    File to remove.
     * @return bool Whether a file was actually deleted.
     */
    public function delete_file(int $draftitemid, string $filepath, string $filename): bool {
        global $USER;

        if ($draftitemid <= 0 || $filename === '') {
            return false;
        }

        $file = get_file_storage()->get_file(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draftitemid,
            $filepath,
            $filename
        );

        if (!$file || $file->is_directory()) {
            return false;
        }

        $file->delete();

        return true;
    }
}
