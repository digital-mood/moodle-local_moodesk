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
 * Tests for the texts about agent membership.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\service;

use local_moodesk\hook\agent_groups;

/**
 * The base's membership texts name departments only, unless a plugin says
 * what its groups are called (agent_groups::set_group_type()).
 *
 * @covers \local_moodesk\service\agent_service
 * @covers \local_moodesk\hook\agent_groups
 */
final class agent_membership_text_test extends \advanced_testcase {
    /** The three texts, and the number the first carries. */
    private const TEXTS = [
        'agents_attention_nomembership'      => 3,
        'agents_attention_nomembership_hint' => null,
        'agents_offboard_confirm_body'       => null,
    ];

    /**
     * Every case reads strings and dispatches the hook.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Base only: no plugin answers, and no text names teams.
     */
    public function test_base_only_texts_name_departments_only(): void {
        $this->redirectHook(agent_groups::class, static function (agent_groups $hook): void {
        });

        $type = agent_service::create()->group_type();
        $this->assertNull($type);

        foreach (self::TEXTS as $identifier => $count) {
            $text = agent_service::membership_text($identifier, $type, $count);
            $this->assertSame(get_string($identifier, 'local_moodesk', $count), $text);
            $this->assertStringNotContainsStringIgnoringCase('team', $text, $identifier);
            $this->assertStringContainsString('department', $text, $identifier);
        }
        $this->assertStringContainsString('3 agent(s)', agent_service::membership_text('agents_attention_nomembership', null, 3));
    }

    /**
     * Base with the add-on 3.0.2: it names its groups, and every text says
     * them alongside departments.
     */
    public function test_a_named_group_type_appears_in_every_text(): void {
        $this->redirectHook(agent_groups::class, static function (agent_groups $hook): void {
            $hook->set_group_type('team');
        });

        $type = agent_service::create()->group_type([42]);
        $this->assertSame('team', $type);

        foreach (self::TEXTS as $identifier => $count) {
            $text = agent_service::membership_text($identifier, $type, $count);
            $this->assertStringContainsString('department', $text, $identifier);
            $this->assertStringContainsString('team', $text, $identifier);
            $this->assertStringNotContainsString('{$a', $text, $identifier);
        }
        $this->assertStringContainsString('3 agent(s)', agent_service::membership_text('agents_attention_nomembership', $type, 3));
    }

    /**
     * The offboard page asks for the agent being removed, so a plugin knows
     * whose groups the text is about.
     */
    public function test_the_hook_is_asked_about_the_agents_given(): void {
        $asked = null;
        $this->redirectHook(agent_groups::class, static function (agent_groups $hook) use (&$asked): void {
            $asked = $hook->userids;
        });

        agent_service::create()->group_type([7]);

        $this->assertSame([7], $asked);
    }
}
