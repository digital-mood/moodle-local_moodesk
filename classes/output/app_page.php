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
 * Central page helper for mooDesk product screens.
 *
 * Every product entry point routes its layout, assets and chrome through this
 * single helper instead of calling header()/footer() directly. It renders on
 * Moodle's 'embedded' pagelayout (clean canvas: no navbar, footer, drawers or
 * blocks) while keeping Moodle's <head> (AMD/RequireJS, sesskey, theme CSS),
 * and mounts the mooDesk application shell ({@see app_shell}) on top. Moodle
 * stays the substrate (auth, session, JS runtime); the shell paints the UI.
 *
 * The application shell is the only mode (phase F8: the legacy 'admin'/'standard'
 * chrome path and the `ui_mode` rollback flag were retired once every screen was
 * validated in the shell).
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class app_page {
    /** @var \moodle_page The page being configured. */
    protected \moodle_page $page;

    /** @var string Navigation/page key used for active-state resolution (e.g. 'dashboard'). */
    protected string $pagekey;

    /** @var bool Initial collapsed state of the shell sidebar (per-user preference). */
    protected bool $collapsed = false;

    /**
     * Configure the page for a mooDesk product screen.
     *
     * @param \moodle_page $page    Usually the global $PAGE.
     * @param string       $pagekey Matches a {@see \local_moodesk\service\nav_item} key,
     *                              so the shell can highlight the active item.
     */
    public function __construct(\moodle_page $page, string $pagekey) {
        $this->page = $page;
        $this->pagekey = $pagekey;
        $this->configure();
    }

    /**
     * Apply the embedded layout and the shell's required assets.
     */
    protected function configure(): void {
        $this->page->requires->css('/local/moodesk/styles.css');

        // Clean full-canvas layout: no navbar, footer, drawers or blocks, but
        // Moodle's <head> (AMD/RequireJS, sesskey, theme CSS) is kept.
        $this->page->set_pagelayout('embedded');
        $this->collapsed = (bool) get_user_preferences('local_moodesk_sidebar_collapsed', 0);
        $this->page->requires->js_call_amd(
            'local_moodesk/app_shell',
            'init',
            ['local_moodesk_sidebar_collapsed']
        );
    }

    /**
     * The navigation/page key this page renders under.
     *
     * @return string
     */
    public function get_page_key(): string {
        return $this->pagekey;
    }

    /**
     * Mount already-rendered content inside the mooDesk shell and return the
     * full page HTML, ready to echo.
     *
     * @param string $content Already-rendered inner content (e.g. from a template).
     * @return string Full page HTML.
     */
    public function render_within_shell(string $content): string {
        global $OUTPUT;

        $shell = new app_shell($content, $this->pagekey, $this->collapsed);
        $shellhtml = $OUTPUT->render_from_template(
            'local_moodesk/shell/app_shell',
            $shell->export_for_template($OUTPUT)
        );

        return $OUTPUT->header() . $shellhtml . $OUTPUT->footer();
    }
}
