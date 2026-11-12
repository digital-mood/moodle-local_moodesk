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
 * Unit tests for the ticket list results component and the query behind it.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\ticket;

/**
 * Guards the one implementation the list page and its refresh fragment share.
 *
 * What is being defended here is a property rather than a feature: the initial
 * paint and every refresh after it must come from the same query and the same
 * rows. The tests that matter most are the ones that put a URL through both
 * doors — {@see ticket_list_query::from_request()} and
 * {@see ticket_list_query::from_query_string()} — and demand the same answer,
 * because a divergence there is precisely the bug this design exists to make
 * impossible.
 *
 * @covers \local_moodesk\output\ticket_list_query
 * @covers \local_moodesk\output\ticket_list_results
 */
final class ticket_list_results_test extends \advanced_testcase {
    /** @var \stdClass The requester every fixture ticket belongs to. */
    private \stdClass $requester;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        // Seed the default category that ticket_service::create_ticket() needs.
        $categoryid = (int) ($DB->get_field('local_moodesk_category', 'id', ['visible' => 1], IGNORE_MULTIPLE) ?: 0);
        if (!$categoryid) {
            $categoryid = (int) $DB->insert_record('local_moodesk_category', (object) [
                'parentid'     => null,
                'name'         => 'General',
                'sortorder'    => 0,
                'visible'      => 1,
                'timecreated'  => time(),
                'usermodified' => 0,
            ]);
        }
        set_config('defaultcategoryid', $categoryid, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
    }

    /**
     * Raise one ticket in a given state.
     *
     * @param int $status
     * @return int Ticket id.
     */
    private function make_ticket(int $status): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_moodesk');
        $row = $generator->create_ticket([
            'userid' => $this->requester->id,
            'status' => $status,
        ]);
        // Ticket creation fans out to automations and webhooks, whose own latent
        // issues call debugging(); drain it so only these assertions decide the
        // outcome.
        $this->resetDebugging();

        return (int) $row->id;
    }

    /**
     * Ids the results component would put on screen.
     *
     * @param ticket_list_query $query
     * @return int[]
     */
    private function listed_ids(ticket_list_query $query): array {
        $context = ticket_list_results::create($query, (int) get_admin()->id, true)->get_context();

        return array_map(static fn(array $row): int => (int) $row['id'], $context['tickets']);
    }

    public function test_the_two_doors_into_the_query_agree(): void {
        // The page's door.
        $_GET = [];
        $_POST = [];
        $_GET['status'] = [(string) ticket::STATUS_OPEN, (string) ticket::STATUS_PENDING];
        $_GET['priority'] = '2';
        $_GET['categoryid'] = '7';
        $_GET['departmentid'] = '0';
        $_GET['assigneeid'] = '-1';
        $_GET['teamid'] = '3';
        $_GET['sla'] = '2';
        $_GET['search'] = 'course access';
        $_GET['created_from'] = '2026-01-01';
        $_GET['updated_to'] = '2026-06-30';
        $_GET['sort'] = 'sla';
        $_GET['page'] = '2';
        $_GET['view'] = 'unassigned';

        $fromrequest = ticket_list_query::from_request(true);

        // The fragment's door, handed the address the page publishes.
        $fromstring = ticket_list_query::from_query_string(
            $fromrequest->to_url()->get_query_string(false),
            true
        );

        $this->assertSame($fromrequest->to_filters(), $fromstring->to_filters());
        $this->assertSame($fromrequest->to_active_params(), $fromstring->to_active_params());
        $this->assertSame($fromrequest->get_page(), $fromstring->get_page());
        $this->assertSame($fromrequest->get_view(), $fromstring->get_view());
        $this->assertSame($fromrequest->get_sort(), $fromstring->get_sort());
    }

    public function test_a_query_string_cannot_smuggle_anything_past_the_cleaning(): void {
        $query = ticket_list_query::from_query_string(
            '?status[]=1%27+OR+1%3D1&priority=<script>&page=-5&view=../../etc&sort=drop%20table',
            true
        );

        $this->assertSame([1], $query->get_statuses());
        // A non-numeric priority cleans to 0, which is itself a real priority;
        // what matters is that nothing but an integer survives — what matters is that nothing but an integer survives.
        $this->assertIsInt($query->get_priority());
        $this->assertSame(0, $query->get_page());
        // An unknown view falls back rather than reaching the service.
        $this->assertSame(\local_moodesk\domain\department::VIEW_ALL, $query->get_view());
        $this->assertSame('droptable', $query->get_sort());
    }

    public function test_a_status_filter_decides_membership_on_the_server(): void {
        $open = $this->make_ticket(ticket::STATUS_OPEN);
        $closed = $this->make_ticket(ticket::STATUS_CLOSED);

        $openonly = ticket_list_query::from_query_string(
            'status[]=' . ticket::STATUS_OPEN,
            true
        );

        $listed = $this->listed_ids($openonly);

        $this->assertContains($open, $listed);
        $this->assertNotContains($closed, $listed);
    }

    public function test_a_ticket_that_leaves_the_filter_leaves_the_results(): void {
        $ticketid = $this->make_ticket(ticket::STATUS_OPEN);
        $query = ticket_list_query::from_query_string('status[]=' . ticket::STATUS_OPEN, true);

        $this->assertContains($ticketid, $this->listed_ids($query));

        // The mutation the drawer performs, through the same service the AJAX
        // endpoint uses.
        \local_moodesk\service\ticket_service::create()
            ->transition_status($ticketid, ticket::STATUS_CLOSED, (int) get_admin()->id);
        $this->resetDebugging();

        // Same query, run again: the server is what notices.
        $refreshed = ticket_list_query::from_query_string('status[]=' . ticket::STATUS_OPEN, true);

        $this->assertNotContains($ticketid, $this->listed_ids($refreshed));
    }

    public function test_a_ticket_that_still_matches_stays_with_its_new_values(): void {
        global $DB;

        // A real agent, not the admin: the service resolves agents by capability
        // and get_users_by_capability leaves the admin out of that list.
        $agent = $this->getDataGenerator()->create_user();
        $agentroleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($agentroleid, $agent->id, \context_system::instance()->id);

        $ticketid = $this->make_ticket(ticket::STATUS_OPEN);
        $query = ticket_list_query::from_query_string('status[]=' . ticket::STATUS_OPEN, true);

        \local_moodesk\service\ticket_service::create()
            ->assign($ticketid, (int) $agent->id, (int) get_admin()->id);
        $this->resetDebugging();

        $context = ticket_list_results::create($query, (int) get_admin()->id, true)->get_context();
        $row = null;
        foreach ($context['tickets'] as $candidate) {
            if ((int) $candidate['id'] === $ticketid) {
                $row = $candidate;
                break;
            }
        }

        $this->assertNotNull($row, 'The ticket still matches the filter and must still be listed.');
        $this->assertFalse($row['isunassigned']);
        $this->assertSame(fullname($agent), $row['assigneename']);
    }

    public function test_the_region_publishes_the_address_it_answers(): void {
        $query = ticket_list_query::from_query_string(
            'view=unassigned&status[]=' . ticket::STATUS_OPEN . '&page=1',
            true
        );

        $context = ticket_list_results::create($query, (int) get_admin()->id, true)->get_context();

        // What the refresh module reads back off the markup. It has to survive a
        // round trip, because window.location cannot be used for it: the detail
        // drawer replaces the address with the open ticket's URL.
        $this->assertStringContainsString('view=unassigned', $context['listurl']);
        $this->assertStringContainsString('page=1', $context['listurl']);

        $roundtripped = ticket_list_query::from_query_string(
            parse_url($context['listurl'], PHP_URL_QUERY) ?? '',
            true
        );
        $this->assertSame($query->to_filters(), $roundtripped->to_filters());
        $this->assertSame($query->get_page(), $roundtripped->get_page());
    }

    public function test_the_list_controls_bar_stays_away_from_an_empty_list(): void {
        // No tickets raised at all, so nothing to select and nothing to sort.
        $query = ticket_list_query::from_query_string('', true);

        $context = ticket_list_results::create($query, (int) get_admin()->id, true)->get_context();

        $this->assertTrue($context['empty']);
        $this->assertFalse($context['showlistcontrols']);
    }

    public function test_the_region_renders_with_the_hooks_the_refresh_depends_on(): void {
        global $OUTPUT;

        $ticketid = $this->make_ticket(ticket::STATUS_OPEN);
        $query = ticket_list_query::from_query_string('', true);

        $html = ticket_list_results::create($query, (int) get_admin()->id, true)->render($OUTPUT);

        // The region hook the refresh swaps, the address it re-queries with, and
        // the row attribute it looks a ticket up by. All three are contract
        // between the markup and local_moodesk/ticket_list; a rename that
        // forgets one leaves a refresh that silently does nothing.
        $this->assertStringContainsString('data-region="mhd-ticket-list-results"', $html);
        $this->assertStringContainsString('data-listurl="', $html);
        $this->assertStringContainsString('data-ticketid="' . $ticketid . '"', $html);
        $this->assertStringContainsString('data-action="mhd-open-detail"', $html);
        // Select-all lives inside the region, which is why bulk_actions.js has
        // to reach it by delegation.
        $this->assertStringContainsString('id="mhd-select-all"', $html);
    }

    public function test_the_whole_list_still_renders_around_the_partial(): void {
        global $OUTPUT;

        $this->make_ticket(ticket::STATUS_OPEN);
        $query = ticket_list_query::from_query_string('', true);

        // The page's own context is not rebuilt here; what is being proved is
        // that the partial resolves and renders inside the parent template, so
        // a missing include or a renamed partial fails a test rather than a page.
        $context = ticket_list_results::create($query, (int) get_admin()->id, true)->get_context()
            + ['isagent' => true, 'canbulk' => true, 'hastabs' => false, 'filterformurl' => '#'];

        $html = $OUTPUT->render_from_template('local_moodesk/ticket_list', $context);

        $this->assertStringContainsString('mhd-ticket-list', $html);
        $this->assertStringContainsString('data-region="mhd-ticket-list-results"', $html);
        $this->assertStringContainsString('mhd-ticket-rows', $html);
    }

    public function test_the_partial_asks_for_nothing_the_component_does_not_provide(): void {
        // The invariant the whole design rests on. Rendered inside the page the
        // partial can read the parent's context, so a variable belonging to the
        // filter bar works there and comes back empty in the fragment, where
        // there is no parent — a row's status dropdown silently losing its
        // options after a refresh is exactly how that failed once already.
        $source = (string) file_get_contents(
            __DIR__ . '/../../templates/ticket_list_results.mustache'
        );

        // Drop the docblock: its context listing and example JSON name variables
        // without using them.
        $body = substr($source, (int) strpos($source, '}}') + 2);

        preg_match_all('/\{\{[#^\/&]?\{?([a-z_][a-z0-9_]*)\}?\}\}/', $body, $matches);
        $used = array_values(array_unique($matches[1]));

        $query = ticket_list_query::from_query_string('', true);
        $provided = array_keys(
            ticket_list_results::create($query, (int) get_admin()->id, true)->get_context()
        );

        // Row fields come from inside the tickets[] section, and {{#str}} is
        // Moodle's own helper rather than a context variable.
        $rowfields = [
            'id', 'requesteravatar', 'requesterfullname', 'subject', 'statuslabel', 'status',
            'prioritylabel', 'priority', 'categoryname', 'departmentname', 'hasdepartment',
            'assigneename', 'timecreated', 'timeupdated', 'isunassigned', 'sla_breach',
            'sla_warning', 'sla_visible', 'sla_timelabel', 'sla_urgency_score', 'viewurl',
            'value', 'label',
        ];

        $unsatisfied = array_values(array_diff($used, $provided, $rowfields, ['str']));

        $this->assertSame(
            [],
            $unsatisfied,
            'The results partial reads variables its own component does not supply, so they '
                . 'would be empty when the fragment renders it: ' . implode(', ', $unsatisfied)
        );
    }

    public function test_the_fragment_callback_returns_the_region(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/moodesk/lib.php');

        $open = $this->make_ticket(ticket::STATUS_OPEN);
        $closed = $this->make_ticket(ticket::STATUS_CLOSED);

        // Exactly what the browser sends: the query string off the region's
        // published address. The component being right is not enough — the
        // callback is what the refresh actually calls.
        $html = local_moodesk_output_fragment_ticket_list_results([
            'query' => 'view=all&status%5B0%5D=' . ticket::STATUS_OPEN,
        ]);

        $this->assertStringContainsString('data-region="mhd-ticket-list-results"', $html);
        $this->assertStringContainsString('data-ticketid="' . $open . '"', $html);
        $this->assertStringNotContainsString('data-ticketid="' . $closed . '"', $html);
    }

    public function test_a_requester_carries_only_the_filters_they_have(): void {
        $this->setUser($this->requester);

        // An agent-only parameter in the URL must not become an agent-only
        // filter just because it was typed there.
        $query = ticket_list_query::from_query_string('priority=2&teamid=9&sort=sla', false);

        $filters = $query->to_filters();

        $this->assertArrayNotHasKey('priority', $filters);
        $this->assertArrayNotHasKey('teamid', $filters);
        $this->assertSame('', $query->get_sort());
    }
}
