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
 * Tests for the "SLA warning only" filter of the ticket list.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\output;

use local_moodesk\domain\department;
use local_moodesk\domain\ticket;
use local_moodesk\service\ticket_service;

/**
 * The warning filter evaluates the whole view, not its first page.
 *
 * Until 3.0.5 the filter re-read "all" the tickets of the view with a call
 * that returns one page, so a ticket in warning after row PAGE_SIZE never
 * appeared, the total only counted the first page and page 2 was empty.
 *
 * Fixtures: the response target is 10 hours. A ticket raised 1 hour ago is
 * ok, 9 hours ago in warning (the last 20 % of the target), 11 hours ago in
 * breach. The ok ones are the newest, so every warning row sits past the
 * first page of the unfiltered view.
 *
 * @covers \local_moodesk\output\ticket_list_results
 * @covers \local_moodesk\service\sla_service
 * @covers \local_moodesk\repository\ticket_repository
 */
final class ticket_list_warning_filter_test extends \advanced_testcase {
    /** @var int Warning tickets per case: two full pages and a bit. */
    private const WARNINGS = 2 * ticket_service::PAGE_SIZE + 5;

    /** @var \stdClass */
    private \stdClass $requester;

    /** @var \stdClass Agent holding :viewall, the acting user. */
    private \stdClass $agent;

    /** @var int The agent's department. */
    private int $departmentid;

    /** @var int The instant the fixtures are placed around. */
    private int $now;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        set_config('sla_response_hours_normal', 10, 'local_moodesk');
        set_config('sla_resolve_hours_normal', 0, 'local_moodesk');

        $this->requester = $this->getDataGenerator()->create_user();
        $this->agent = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'mhd_agent'], MUST_EXIST);
        role_assign($roleid, $this->agent->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->agent);

        $this->departmentid = (int) $DB->insert_record('local_moodesk_department', (object) [
            'name' => 'Support',
            'isactive' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $this->agent->id,
        ]);
        $DB->insert_record('local_moodesk_dept_member', (object) [
            'departmentid' => $this->departmentid,
            'userid' => $this->agent->id,
            'timecreated' => time(),
        ]);

        $this->now = time();
    }

    protected function tearDown(): void {
        ticket_list_results::set_warning_chunk_for_testing(null);
        parent::tearDown();
    }

    /**
     * Raise one ticket directly, without events or automations.
     *
     * @param int $timecreated
     * @param array $fields Overrides: status, assigneeid.
     * @return int Ticket id.
     */
    private function ticket(int $timecreated, array $fields = []): int {
        global $DB;

        return (int) $DB->insert_record('local_moodesk_ticket', (object) ($fields + [
            'userid' => $this->requester->id,
            'createdby' => $this->requester->id,
            'subject' => 'Ticket',
            'status' => ticket::STATUS_OPEN,
            'priority' => ticket::PRIORITY_NORMAL,
            'source' => ticket::SOURCE_WEB,
            'departmentid' => $this->departmentid,
            'assigneeid' => null,
            'timecreated' => $timecreated,
            'timemodified' => $timecreated,
            'usermodified' => $this->requester->id,
        ]));
    }

    /**
     * Forty ok tickets on top, then WARNINGS warning tickets interleaved with
     * breached ones, every row older than the one before.
     *
     * @param array $fields Applied to every ticket.
     * @return int[] Warning ticket ids, newest first: the order the list shows.
     */
    private function view_with_warnings_past_the_first_page(array $fields = []): array {
        for ($i = 0; $i < 40; $i++) {
            $this->ticket($this->now - HOURSECS - $i, $fields);
        }
        $warnings = [];
        for ($i = 0; $i < self::WARNINGS; $i++) {
            $warnings[] = $this->ticket($this->now - 9 * HOURSECS - 2 * $i, $fields);
            $this->ticket($this->now - 11 * HOURSECS - 2 * $i, $fields);
        }

        return $warnings;
    }

    /**
     * The ticket ids on one page of the warning filter, and the total.
     *
     * @param string $querystring Without sla and page.
     * @param int $page
     * @return array{ids: int[], total: int, rows: array}
     */
    private function warning_page(string $querystring, int $page): array {
        $query = ticket_list_query::from_query_string($querystring . '&sla=2&page=' . $page, true);
        $context = ticket_list_results::create($query, (int) $this->agent->id, true)->get_context();

        return [
            'ids' => array_map(static fn(array $row): int => (int) $row['id'], $context['tickets']),
            'total' => (int) $context['total'],
            'rows' => $context['tickets'],
        ];
    }

    /**
     * Every page of the filter, concatenated, and the total each page reported.
     *
     * @param string $querystring
     * @return array{ids: int[], totals: int[], pages: int[][]}
     */
    private function all_warning_pages(string $querystring): array {
        $ids = [];
        $totals = [];
        $pages = [];
        for ($page = 0; $page < 4; $page++) {
            $result = $this->warning_page($querystring, $page);
            foreach ($result['rows'] as $row) {
                $this->assertTrue($row['sla_warning'], 'Only tickets in warning may be listed');
            }
            $ids = array_merge($ids, $result['ids']);
            $totals[] = $result['total'];
            $pages[] = $result['ids'];
        }

        return ['ids' => $ids, 'totals' => $totals, 'pages' => $pages];
    }

    /**
     * Each view, with its fixture, and the block size of the walk.
     *
     * @return array<string, array{string, array, int|null}>
     */
    public static function view_provider(): array {
        $cases = [];
        foreach ([[null, 'one block'], [7, 'blocks of 7']] as [$chunk, $label]) {
            $cases["all, $label"] = [department::VIEW_ALL, [], $chunk];
            $cases["mine, $label"] = [department::VIEW_MINE, ['assigneeid' => 'agent'], $chunk];
            $cases["unassigned, $label"] = [department::VIEW_UNASSIGNED, [], $chunk];
            $cases["department, $label"] = [department::VIEW_DEPARTMENT, ['assigneeid' => 'agent'], $chunk];
        }

        return $cases;
    }

    /**
     * The total and every page are exact in each view, past the first page of
     * the view and across blocks of the walk.
     *
     * @dataProvider view_provider
     * @param string $view
     * @param array $fields
     * @param int|null $chunk
     */
    public function test_the_whole_view_is_evaluated(string $view, array $fields, ?int $chunk): void {
        ticket_list_results::set_warning_chunk_for_testing($chunk);
        if (($fields['assigneeid'] ?? null) === 'agent') {
            $fields['assigneeid'] = (int) $this->agent->id;
        }
        $warnings = $this->view_with_warnings_past_the_first_page($fields);

        $result = $this->all_warning_pages('view=' . $view);

        $this->assertSame(array_fill(0, 4, self::WARNINGS), $result['totals']);
        $this->assertCount(ticket_service::PAGE_SIZE, $result['pages'][0]);
        $this->assertCount(ticket_service::PAGE_SIZE, $result['pages'][1]);
        $this->assertCount(5, $result['pages'][2]);
        $this->assertCount(0, $result['pages'][3]);
        $this->assertSame($warnings, $result['ids']);
    }

    /**
     * With every ticket raised in the same second, the id tiebreaker keeps each
     * row on exactly one page, and on exactly one block of the walk.
     */
    public function test_tickets_of_the_same_second_are_neither_repeated_nor_lost(): void {
        ticket_list_results::set_warning_chunk_for_testing(7);
        $warnings = [];
        for ($i = 0; $i < self::WARNINGS; $i++) {
            $warnings[] = $this->ticket($this->now - 9 * HOURSECS);
            $this->ticket($this->now - 11 * HOURSECS);
            $this->ticket($this->now - 9 * HOURSECS, ['status' => ticket::STATUS_CLOSED]);
        }

        $result = $this->all_warning_pages('view=all');

        $this->assertSame(self::WARNINGS, $result['totals'][0]);
        $this->assertCount(self::WARNINGS, array_unique($result['ids']));
        rsort($warnings);
        $this->assertSame($warnings, $result['ids']);
    }

    /**
     * The same-second order also holds for the unfiltered list, page by page.
     */
    public function test_the_unfiltered_list_pages_tickets_of_the_same_second_once(): void {
        $ids = [];
        for ($i = 0; $i < 2 * ticket_service::PAGE_SIZE + 3; $i++) {
            $ids[] = $this->ticket($this->now - HOURSECS);
        }

        $listed = [];
        for ($page = 0; $page < 3; $page++) {
            $query = ticket_list_query::from_query_string('view=all&page=' . $page, true);
            $context = ticket_list_results::create($query, (int) $this->agent->id, true)->get_context();
            foreach ($context['tickets'] as $row) {
                $listed[] = (int) $row['id'];
            }
        }

        rsort($ids);
        $this->assertSame($ids, $listed);
    }

    /**
     * The user's status filter narrows the walk; a terminal status leaves
     * nothing to find, because the SLA does not apply to it.
     */
    public function test_a_status_filter_combines_with_warning(): void {
        ticket_list_results::set_warning_chunk_for_testing(7);
        $open = [];
        $pending = [];
        for ($i = 0; $i < 20; $i++) {
            $open[] = $this->ticket($this->now - 9 * HOURSECS - 2 * $i);
            $pending[] = $this->ticket($this->now - 9 * HOURSECS - 2 * $i - 1, ['status' => ticket::STATUS_PENDING]);
            $this->ticket($this->now - 9 * HOURSECS - 2 * $i, ['status' => ticket::STATUS_RESOLVED]);
        }

        $openonly = $this->warning_page('view=all&status[]=' . ticket::STATUS_OPEN, 0);
        $this->assertSame(20, $openonly['total']);
        $this->assertSame($open, $openonly['ids']);

        $both = $this->warning_page('view=all&status[]=' . ticket::STATUS_OPEN . '&status[]=' . ticket::STATUS_PENDING, 0);
        $this->assertSame(40, $both['total']);
        $interleaved = [];
        foreach ($open as $i => $id) {
            $interleaved[] = $id;
            $interleaved[] = $pending[$i];
        }
        $this->assertSame(array_slice($interleaved, 0, ticket_service::PAGE_SIZE), $both['ids']);

        $resolved = $this->warning_page('view=all&status[]=' . ticket::STATUS_RESOLVED, 0);
        $this->assertSame(0, $resolved['total']);
        $this->assertSame([], $resolved['ids']);
    }
}
