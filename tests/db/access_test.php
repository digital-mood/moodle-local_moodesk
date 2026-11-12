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
 * Regression tests for the capability risk bitmasks.
 *
 * Moodle shows a capability's risk bitmask to an administrator in the role
 * editor, which is the only place most operators ever learn what granting one
 * actually permits. The plugin shipped nineteen capabilities and zero masks
 * until 2.24.0 (audit finding R1), so this file exists to keep two things true.
 *
 * **Every capability declares the key.** Not "every capability declares a
 * non-zero mask" — four of them are deliberately `0`, and a rule that forbade
 * that would push a false bit onto an honest capability. What the rule forbids
 * is arriving without a decision: a new capability with no `riskbitmask` key at
 * all fails here rather than silently defaulting to no warning.
 *
 * **The masks that were reasoned about stay put.** The expectations below are
 * the conclusions of the R1 analysis, and several of them are narrower than the
 * obvious guess. They are pinned so that a later edit that widens or drops one
 * has to be deliberate.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\db;

/**
 * Tests for db/access.php.
 *
 * @coversNothing
 */
final class access_test extends \advanced_testcase {
    /**
     * Read the capability definitions straight from db/access.php.
     *
     * Deliberately the file and not the `capabilities` table: the table is
     * whatever the last upgrade wrote, and the point of these tests is the
     * source of truth an administrator's next upgrade will apply.
     *
     * @return array The $capabilities array as declared.
     */
    private function declared_capabilities(): array {
        global $CFG;

        $capabilities = [];
        require($CFG->dirroot . '/local/moodesk/db/access.php');

        return $capabilities;
    }

    /**
     * Every capability declares a riskbitmask key.
     *
     * A missing key is indistinguishable from "no risk" once Moodle reads it,
     * so a capability added without one warns nobody about anything. Zero is a
     * permitted answer; not answering is not.
     */
    public function test_every_capability_declares_a_riskbitmask(): void {
        $missing = [];

        foreach ($this->declared_capabilities() as $name => $definition) {
            if (!array_key_exists('riskbitmask', $definition)) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'These capabilities declare no riskbitmask. Zero is a valid answer and several '
                . 'capabilities here use it, but the key has to be present so that adding a '
                . 'capability forces the decision: ' . implode(', ', $missing)
        );
    }

    /**
     * A risk bit that is set must be one Moodle defines.
     *
     * Cheap, and it catches a typo'd constant, which in PHP would otherwise be
     * an undefined-constant error or, worse, silently wrong arithmetic.
     */
    public function test_no_capability_declares_an_unknown_risk_bit(): void {
        $known = RISK_MANAGETRUST | RISK_CONFIG | RISK_XSS
            | RISK_PERSONAL | RISK_SPAM | RISK_DATALOSS;

        foreach ($this->declared_capabilities() as $name => $definition) {
            // Absence is reported by test_every_capability_declares_a_riskbitmask,
            // which says something useful about it. Reading it as 0 here keeps
            // this test from turning that into an undefined-key error instead.
            $mask = (int) ($definition['riskbitmask'] ?? 0);

            $this->assertSame(
                0,
                $mask & ~$known,
                "$name declares a risk bit Moodle does not define."
            );
        }
    }

    /**
     * The masks decided by the R1 analysis, pinned.
     *
     * Covers all nineteen rather than a sample: a mask silently widened is as
     * much a regression as one dropped, and with twenty-two capabilities the
     * exhaustive list is also the readable one.
     */
    public function test_the_declared_masks_are_the_ones_that_were_reasoned_about(): void {
        $expected = [
            // Requester.
            'local/moodesk:submit'            => RISK_SPAM,
            'local/moodesk:viewown'           => 0,
            // Agent.
            'local/moodesk:agent'             => 0,
            'local/moodesk:viewall'           => RISK_PERSONAL,
            'local/moodesk:reply'             => RISK_SPAM,
            'local/moodesk:addnote'           => 0,
            'local/moodesk:manage'            => RISK_PERSONAL | RISK_SPAM,
            // Manager.
            'local/moodesk:managecategory'    => RISK_CONFIG,
            'local/moodesk:managesettings'    => RISK_CONFIG,
            'local/moodesk:managedepartment'  => RISK_CONFIG,
            'local/moodesk:viewhealth'        => 0,
            'local/moodesk:manageagents'      => RISK_MANAGETRUST | RISK_PERSONAL,
            // Knowledge base (KB-01). Zero on all three: the body is HTML but
            // it is cleaned on render — see test_no_capability_claims_xss and
            // tests/kb_html_rendering_test.php for the two guards.
            'local/moodesk:viewkb'            => 0,
            'local/moodesk:editkb'            => 0,
            'local/moodesk:managekb'          => 0,
        ];

        $declared = $this->declared_capabilities();

        $this->assertSame(
            array_keys($expected),
            array_keys($declared),
            'The capability list changed. Add the new capability to this expectation with the '
                . 'mask its behaviour justifies, rather than adjusting the assertion to pass.'
        );

        foreach ($expected as $name => $mask) {
            $this->assertSame(
                $mask,
                (int) ($declared[$name]['riskbitmask'] ?? 0),
                "$name no longer declares the mask the R1 analysis settled on."
            );
        }
    }

    /**
     * No capability claims RISK_XSS.
     *
     * Stated as its own test because it is the bit most likely to be added back
     * by someone reasoning from the capability names rather than the code. Both
     * ticket composers are plain textareas rendered through FORMAT_PLAIN, so
     * there is no path by which a holder of :submit, :reply or :addnote gets
     * markup in front of another user. The knowledge base (KB-01) does store
     * HTML, and its bit is zero on a different guarantee: the body is rendered
     * through format_text(FORMAT_HTML) with neither `noclean` nor `trusttext`,
     * so HTML Purifier stands between an author and every reader.
     * tests/kb_html_rendering_test.php holds that guarantee. If either changes
     * — an unclean render, a trusttext editor — this test is the thing that
     * should fail, and the mask on :editkb should change with it.
     */
    public function test_no_capability_claims_xss(): void {
        foreach ($this->declared_capabilities() as $name => $definition) {
            $this->assertSame(
                0,
                (int) ($definition['riskbitmask'] ?? 0) & RISK_XSS,
                "$name claims RISK_XSS, but the plugin renders every user-authored body "
                    . 'through format_text() without noclean or trusttext. Either the mask is '
                    . 'wrong or the rendering changed.'
            );
        }
    }

    /**
     * Everything that can reach another person's ticket says so.
     *
     * ticket_access_policy::can_view() is satisfied by :viewall or :manage, so
     * both read tickets belonging to other people and both have to carry
     * RISK_PERSONAL. This pins the pair to the policy rather than to a list.
     */
    public function test_the_capabilities_that_grant_ticket_reach_declare_personal_risk(): void {
        $declared = $this->declared_capabilities();

        foreach (['local/moodesk:viewall', 'local/moodesk:manage'] as $name) {
            $this->assertSame(
                RISK_PERSONAL,
                (int) ($declared[$name]['riskbitmask'] ?? 0) & RISK_PERSONAL,
                "$name satisfies ticket_access_policy::can_view() and so reads other "
                    . "people's tickets; it must declare RISK_PERSONAL."
            );
        }
    }

    /**
     * The only capability that assigns a role declares RISK_MANAGETRUST.
     *
     * agent_service is the sole caller of role_assign/role_unassign in the
     * plugin, and :manageagents is what gates it.
     */
    public function test_the_role_granting_capability_declares_managetrust(): void {
        $declared = $this->declared_capabilities();

        $this->assertSame(
            RISK_MANAGETRUST,
            (int) ($declared['local/moodesk:manageagents']['riskbitmask'] ?? 0) & RISK_MANAGETRUST,
            ':manageagents grants and revokes the mhd_agent role and must declare '
                . 'RISK_MANAGETRUST.'
        );
    }

    /**
     * Every capability is still declared at system context.
     *
     * The whole risk analysis assumes one context. A capability introduced at a
     * different level would need its masks reconsidered, so it should not slip
     * in unnoticed.
     */
    public function test_every_capability_is_declared_at_system_context(): void {
        foreach ($this->declared_capabilities() as $name => $definition) {
            $this->assertSame(
                CONTEXT_SYSTEM,
                $definition['contextlevel'],
                "$name is not declared at CONTEXT_SYSTEM; the R1 risk analysis assumed it was."
            );
        }
    }
}
