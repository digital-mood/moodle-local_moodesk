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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The bulk action module's contract with the template that feeds it.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk;

/**
 * An AMD module and its template are one component split across two files.
 *
 * Nothing enforced that. bulk_actions.js opened with a lookup for
 * #mhd-select-all and returned early when it came back null; the template never
 * rendered that id, and never had. So the module bailed out before binding a
 * single listener: the checkboxes rendered, the toolbar stayed hidden behind
 * its `hidden` attribute, and the whole bulk action feature was dead from the
 * day it shipped. PHPUnit was green throughout, because no test ever read the
 * two files in the same breath.
 *
 * That is the gap this closes. Like {@see pill_markup_test}, the sources are
 * read rather than rendered: what is asserted is a property of the contract
 * between the files, and a render test would only prove it for whichever branch
 * of the template the fixture happened to reach.
 *
 * The contract is deliberately scoped to the modules listed in CONTRACTS rather
 * than swept across every AMD module in the plugin. A sweep would be a second
 * piece of work — several modules build ids dynamically or address markup this
 * plugin does not own — and this test is worth having before that work happens,
 * not after it.
 *
 * @coversNothing
 */
final class bulk_markup_test extends \advanced_testcase {
    /** @var string Plugin root inside the Moodle tree. */
    private const PLUGIN_DIR = __DIR__ . '/..';

    /**
     * AMD module => the template that must satisfy its lookups.
     *
     * @var array<string, string>
     */
    private const CONTRACTS = [
        'amd/src/bulk_actions.js' => 'templates/ticket_list.mustache',
    ];

    /**
     * Read one file below the plugin root.
     *
     * @param string $relative Path relative to the plugin root.
     * @return string
     */
    private function source(string $relative): string {
        $path = self::PLUGIN_DIR . '/' . $relative;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * A template's source together with every partial it pulls in.
     *
     * The markup a module runs against is the rendered page, not one file: a
     * template is free to move a block into a partial, and the ticket list did
     * exactly that when its results region became separately refreshable. Without
     * following the includes this test would call that a broken contract, which
     * is the opposite of what it is for.
     *
     * @param string $relative Template path relative to the plugin root.
     * @param array  $seen     Guards against a partial cycle.
     * @return string Concatenated source.
     */
    private function template_source(string $relative, array &$seen = []): string {
        if (isset($seen[$relative])) {
            return '';
        }
        $seen[$relative] = true;

        $source = $this->source($relative);

        preg_match_all('/\{\{>\s*local_moodesk\/([a-z0-9_]+)\s*\}\}/', $source, $matches);
        foreach ($matches[1] as $partial) {
            $source .= "\n" . $this->template_source('templates/' . $partial . '.mustache', $seen);
        }

        return $source;
    }

    /**
     * Element ids a module looks up by literal name.
     *
     * Only literal arguments count. getElementById('mhd-bulk-params-' + action)
     * names a family of ids, not one id, and the template is free to render any
     * subset of it — asserting on the prefix would be asserting on markup the
     * module never promised to find.
     *
     * @param string $js Module source.
     * @return string[] Element ids, sorted and deduplicated.
     */
    private function ids_looked_up(string $js): array {
        preg_match_all('/getElementById\(\s*([\'"])([^\'"]+)\1\s*\)/', $js, $matches);
        $ids = array_values(array_unique($matches[2]));
        sort($ids);
        return $ids;
    }

    /**
     * Single class selectors a module queries by literal name.
     *
     * @param string $js Module source.
     * @return string[] Class names without the leading dot, sorted and deduplicated.
     */
    private function classes_queried(string $js): array {
        preg_match_all(
            '/querySelectorAll?\(\s*([\'"])\.([a-z0-9_-]+)(?::checked)?\1\s*\)/i',
            $js,
            $matches
        );
        $classes = array_values(array_unique($matches[2]));
        sort($classes);
        return $classes;
    }

    /**
     * Element ids a template renders.
     *
     * @param string $html Template source.
     * @return string[] Element ids.
     */
    private function ids_rendered(string $html): array {
        preg_match_all('/\bid="([^"{}]+)"/', $html, $matches);
        return array_values(array_unique($matches[1]));
    }

    /**
     * Class names a template renders.
     *
     * @param string $html Template source.
     * @return string[] Class names.
     */
    private function classes_rendered(string $html): array {
        $found = [];
        preg_match_all('/\bclass="([^"]*)"/', $html, $matches);
        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', $attribute, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                // Skip interpolated fragments — {{#isagent}} is not a class name.
                if (!str_contains($token, '{')) {
                    $found[$token] = true;
                }
            }
        }
        return array_keys($found);
    }

    /**
     * A lookup that comes back null is a listener that never binds.
     */
    public function test_every_id_the_module_looks_up_is_rendered_by_its_template(): void {
        $missing = [];

        foreach (self::CONTRACTS as $module => $template) {
            $rendered = $this->ids_rendered($this->template_source($template));
            foreach ($this->ids_looked_up($this->source($module)) as $id) {
                if (!in_array($id, $rendered, true)) {
                    $missing[] = "{$module} looks up #{$id}, {$template} and its partials never render it";
                }
            }
        }

        $this->assertSame([], $missing, implode(' | ', $missing));
    }

    /**
     * The same contract, for the selections a module makes by class.
     */
    public function test_every_class_the_module_queries_is_rendered_by_its_template(): void {
        $missing = [];

        foreach (self::CONTRACTS as $module => $template) {
            $rendered = $this->classes_rendered($this->template_source($template));
            foreach ($this->classes_queried($this->source($module)) as $class) {
                if (!in_array($class, $rendered, true)) {
                    $missing[] = "{$module} queries .{$class}, {$template} and its partials never render it";
                }
            }
        }

        $this->assertSame([], $missing, implode(' | ', $missing));
    }

    /**
     * Moodle serves amd/build/, never amd/src/.
     *
     * Editing the source and forgetting `npx grunt amd` leaves a fix that is
     * real in the repository and absent in the browser — a failure mode with no
     * symptom a reviewer can see in the diff. Comparing the lookups is not a
     * full build check, but it catches the case that actually happens: a
     * contract changed on one side of the pipeline only.
     */
    public function test_the_built_module_agrees_with_its_source(): void {
        foreach (self::CONTRACTS as $module => $unused) {
            $built = str_replace('amd/src/', 'amd/build/', str_replace('.js', '.min.js', $module));

            $this->assertSame(
                $this->ids_looked_up($this->source($module)),
                $this->ids_looked_up($this->source($built)),
                "{$built} is out of date with {$module} — run `npx grunt amd`"
            );
            $this->assertSame(
                $this->classes_queried($this->source($module)),
                $this->classes_queried($this->source($built)),
                "{$built} is out of date with {$module} — run `npx grunt amd`"
            );
        }
    }

    /**
     * The endpoint gate, which is what stops a hand-built request.
     *
     * Hiding the checkboxes decides what an agent is offered, never what the
     * server accepts: the POST is three lines of curl away with a valid session.
     * The service refuses on its own behalf — see ticket_bulk_test — and the
     * entry point refuses before it gets that far. An entry point cannot be
     * called from PHPUnit, so what is asserted here is that the three lines
     * doing the refusing are present and that none of them has drifted to a
     * weaker capability than the one the service enforces.
     */
    public function test_the_bulk_endpoint_is_gated_before_it_acts(): void {
        $source = $this->source('bulk_action.php');

        $this->assertStringContainsString('require_login();', $source);
        $this->assertStringContainsString('require_sesskey();', $source);
        $this->assertStringContainsString(
            "require_capability('local/moodesk:manage'",
            $source,
            'The bulk endpoint must require the same capability the service enforces.'
        );

        // The gate is worth nothing after the work has run.
        $gate = strpos($source, "require_capability('local/moodesk:manage'");
        $work = strpos($source, 'apply_bulk(');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($work);
        $this->assertLessThan($work, $gate, 'The capability check must precede the bulk call.');
    }

    /**
     * The redirect target must stay a local URL.
     *
     * The list page hands the endpoint the URL to come back to, which is a
     * parameter an attacker can also set. PARAM_LOCALURL is what makes that
     * safe; PARAM_URL would happily carry another origin.
     */
    public function test_the_return_url_cannot_leave_the_site(): void {
        $source = $this->source('bulk_action.php');

        $this->assertStringContainsString("optional_param('returnurl', '', PARAM_LOCALURL)", $source);
        $this->assertStringNotContainsString("optional_param('returnurl', '', PARAM_URL)", $source);
    }
}
