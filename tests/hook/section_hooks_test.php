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
 * Tests for the panel hooks: dashboard_panels, health_panels, kb_article_view_extend.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moodesk\hook;

use local_moodesk\domain\dashboard_filter;
use local_moodesk\output\health_page;
use local_moodesk\output\kb_article_view;
use local_moodesk\service\dashboard_service;

/**
 * Sections plugins add are rendered in their page, and nothing changes without them.
 *
 * @covers \local_moodesk\hook\section_collection
 * @covers \local_moodesk\hook\dashboard_panels
 * @covers \local_moodesk\hook\health_panels
 * @covers \local_moodesk\hook\kb_article_view_extend
 * @covers \local_moodesk\output\health_page
 * @covers \local_moodesk\output\kb_article_view
 */
final class section_hooks_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    protected function tearDown(): void {
        $this->stopHookRedirections();
        parent::tearDown();
    }

    /**
     * The core renderer.
     *
     * @return \renderer_base
     */
    private function renderer(): \renderer_base {
        global $PAGE;
        return $PAGE->get_renderer('core');
    }

    public function test_dashboard_panels_render_in_order(): void {
        $seen = null;
        $this->redirectHook(dashboard_panels::class, function (dashboard_panels $hook) use (&$seen): void {
            $seen = [$hook->viewmode, $hook->userid];
            $hook->add_section('core/notification_info', ['message' => 'PANEL-ONE']);
            $hook->add_section('core/notification_info', ['message' => 'PANEL-TWO']);
        });

        $hook = new dashboard_panels(dashboard_filter::from_days(30), dashboard_service::VIEW_MANAGER, 5);
        $html = $hook->dispatch_and_render($this->renderer());

        $this->assertSame([dashboard_service::VIEW_MANAGER, 5], $seen);
        $this->assertCount(2, $html);
        $this->assertStringContainsString('PANEL-ONE', $html[0]['html']);
        $this->assertStringContainsString('PANEL-TWO', $html[1]['html']);
    }

    public function test_dashboard_template_prints_plugin_panels_only_when_given(): void {
        $render = fn(array $panels): string => $this->renderer()->render_from_template('local_moodesk/dashboard', [
            'pluginpanels' => $panels,
            'haspluginpanels' => !empty($panels),
        ]);

        $this->assertStringNotContainsString('PANEL-ONE', $render([]));
        $this->assertStringContainsString('PANEL-ONE', $render([['html' => '<p>PANEL-ONE</p>']]));
    }

    public function test_health_page_without_a_section_is_unchanged(): void {
        $this->redirectHook(health_panels::class, static function (): void {
        });
        $ctx = (new health_page())->export('/local/moodesk/health.php');
        $this->assertSame([], $ctx['pluginsections']);
        $this->assertFalse($ctx['haspluginsections']);
    }

    public function test_health_page_renders_plugin_sections(): void {
        $this->redirectHook(health_panels::class, function (health_panels $hook): void {
            $hook->add_section('core/notification_info', ['message' => 'HEALTH-' . ($hook->canmanage ? 'admin' : 'other')]);
        });

        $ctx = (new health_page())->export('/local/moodesk/health.php');

        $this->assertTrue($ctx['haspluginsections']);
        $this->assertStringContainsString('HEALTH-admin', $ctx['pluginsections'][0]['html']);
        $html = $this->renderer()->render_from_template('local_moodesk/admin_health', $ctx);
        $this->assertSame(1, substr_count($html, 'HEALTH-admin'));
    }

    public function test_kb_article_renders_plugin_sections_after_its_body(): void {
        $article = $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_kb_article([
            'title' => 'Reset your password', 'body' => '<p>ARTICLE-BODY</p>', 'status' => 'published',
        ]);

        $this->redirectHook(kb_article_view_extend::class, static function (): void {
        });
        $without = kb_article_view::create($article, (int) get_admin()->id)->export();
        $this->assertFalse($without['haspluginsections']);

        $seen = null;
        $this->redirectHook(kb_article_view_extend::class, function (kb_article_view_extend $hook) use (&$seen): void {
            $seen = [(int) $hook->article->id, $hook->actorid];
            $hook->add_section('core/notification_info', ['message' => 'KB-SECTION']);
        });
        $ctx = kb_article_view::create($article, (int) get_admin()->id)->export();

        $this->assertSame([(int) $article->id, (int) get_admin()->id], $seen);
        $html = $this->renderer()->render_from_template('local_moodesk/kb_article', $ctx);
        $this->assertLessThan(strpos($html, 'KB-SECTION'), strpos($html, 'ARTICLE-BODY'));
    }

    public function test_a_section_needs_a_template(): void {
        $hook = new health_panels(true);
        $this->expectException(\coding_exception::class);
        $hook->add_section('');
    }
}
