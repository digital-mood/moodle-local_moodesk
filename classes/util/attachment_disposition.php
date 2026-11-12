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
 * How an attachment may be sent to the browser.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\util;

/**
 * Decides whether an attachment has to be sent as a download.
 *
 * A ticket attachment is a file a requester chose, or that an email sender
 * chose — on a site with email ingestion enabled, from someone with no Moodle
 * account at all. Moodle serves files from `pluginfile.php`, which is on the
 * site's own origin, so a file the browser executes rather than displays runs
 * as script with the reader's session. The reader here is usually a support
 * agent, and often an administrator.
 *
 * Core forces a download for SVG and nothing else ({@see send_file()} in
 * lib/filelib.php), because everywhere else in Moodle the person who uploaded
 * the file was trusted to some degree. A help desk inverts that: the least
 * trusted party in the system is the one supplying the file. So the plugin adds
 * the rest of the rule, and core's own file-type registry supplies the list:
 *
 *  - `web_file` — the typegroup Moodle already keeps for the types a browser
 *    renders as a document rather than as content: html, xhtml, htm, css, js,
 *    scss. Reading it from core means a new web type added to a future Moodle
 *    release is covered here without this class being touched.
 *  - SVG, via core's own predicate, so this class states the whole contract
 *    rather than depending on `send_file()` continuing to special-case it.
 *
 * Everything else keeps the behaviour it has today. An image, a PDF or a plain
 * text file still opens in the browser, because none of them executes in this
 * origin and forcing a download would make the agent's job worse for no gain.
 */
final class attachment_disposition {
    /**
     * Whether this file must be sent as a download rather than rendered inline.
     *
     * @param \stored_file $file The attachment about to be served.
     * @return bool True when the file must not be rendered by the browser.
     */
    public static function must_force_download(\stored_file $file): bool {
        return self::is_active_mimetype(self::sending_mimetype($file));
    }

    /**
     * The mimetype `send_file()` will use for this file.
     *
     * Mirrors lib/filelib.php: `send_stored_file()` passes the stored mimetype,
     * and `send_file()` falls back to deriving one from the file name when that
     * is missing or unknown. The decision has to be made against the same value
     * the `Content-Type` header will carry, or the two can disagree.
     *
     * @param \stored_file $file The attachment about to be served.
     * @return string A mimetype; never empty, because the fallback always
     *                resolves, to `application/octet-stream` at worst.
     */
    public static function sending_mimetype(\stored_file $file): string {
        $mimetype = (string) $file->get_mimetype();

        if ($mimetype === '' || $mimetype === 'document/unknown') {
            $mimetype = (string) get_mimetype_for_sending($file->get_filename());
        }

        return $mimetype;
    }

    /**
     * Whether a mimetype executes in the browser when it is rendered inline.
     *
     * @param string $mimetype Mimetype to classify.
     * @return bool True when serving it inline would run it on this origin.
     */
    public static function is_active_mimetype(string $mimetype): bool {
        return file_mimetype_in_typegroup($mimetype, 'web_file')
            || file_is_svg_image_from_mimetype($mimetype);
    }
}
