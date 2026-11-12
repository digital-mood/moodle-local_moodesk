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

namespace local_moodesk\form;

use local_moodesk\domain\kb_article;

/**
 * The article form's language layer (KB-06): which controls it shows and
 * which it freezes, by edition and by the row's place in its group.
 *
 * @package   local_moodesk
 * @copyright 2026 Digital Mood
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \local_moodesk\form\kb_article_form
 */
final class kb_article_form_test extends \advanced_testcase {
    /**
     * The form's QuickForm, for inspection.
     *
     * @param array $customdata
     * @return \MoodleQuickForm
     */
    private function definition(array $customdata): \MoodleQuickForm {
        $form = new class (null, $customdata) extends kb_article_form {
            /**
             * The inner form.
             *
             * @return \MoodleQuickForm
             */
            public function inner(): \MoodleQuickForm {
                return $this->_form;
            }
        };
        return $form->inner();
    }

    /** @var array The customdata of a blank Pro form. */
    private const PRO = [
        'id' => 0, 'status' => 0, 'canmanage' => false,
        'categories' => [3 => 'Access'],
        'languages'  => ['en' => 'English', 'es' => 'Español'],
        'shared'     => false,
        'translate'  => false,
    ];

    public function test_starter_has_no_language_field_and_nothing_frozen(): void {
        $mform = $this->definition(['languages' => []] + self::PRO);
        $this->assertFalse($mform->elementExists('lang'));
        $this->assertFalse($mform->elementExists('sharednote'));
        $this->assertFalse($mform->getElement('categoryid')->isFrozen());
        $this->assertFalse($mform->getElement('audience')->isFrozen());
    }

    public function test_pro_offers_the_installed_languages(): void {
        $mform = $this->definition(self::PRO);
        $lang = $mform->getElement('lang');
        $this->assertSame('select', $lang->getType());
        $this->assertSame(['en', 'es'], array_map(static fn(array $o): string => $o['attr']['value'], $lang->_options));
        $this->assertFalse($mform->getElement('categoryid')->isFrozen());
    }

    public function test_a_row_with_siblings_freezes_the_shared_fields_and_says_so(): void {
        $mform = $this->definition(['id' => 7, 'status' => kb_article::STATUS_DRAFT, 'shared' => true] + self::PRO);
        $this->assertTrue($mform->getElement('categoryid')->isFrozen());
        $this->assertTrue($mform->getElement('audience')->isFrozen());
        $this->assertTrue($mform->elementExists('sharednote'));
        // Still editable: the language of this row.
        $this->assertSame('select', $mform->getElement('lang')->getType());
    }

    public function test_a_translation_being_started_hides_the_language_and_freezes_the_shared_fields(): void {
        $mform = $this->definition(['shared' => true, 'translate' => true] + self::PRO);
        $this->assertSame('hidden', $mform->getElement('lang')->getType());
        $this->assertTrue($mform->getElement('categoryid')->isFrozen());
        $this->assertTrue($mform->getElement('audience')->isFrozen());
        $this->assertTrue($mform->elementExists('sharednote'));
    }

    /**
     * A submitted form, built the way the page builds it, for to_service_data().
     *
     * @param array $customdata
     * @param array $submitted The POST, without the fields a frozen element would not send.
     * @return kb_article_form
     */
    private function submitted(array $customdata, array $submitted): kb_article_form {
        // PARAM_LANG keeps only installed packs: the test site has `en` alone.
        $this->resetAfterTest();
        $this->getDataGenerator()->get_plugin_generator('local_moodesk')->create_language_pack(['code' => 'es']);
        kb_article_form::mock_submit($submitted + [
            'title' => 'Title',
            'body'  => ['text' => '<p>Body</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]);
        $form = new kb_article_form(null, $customdata);
        $this->assertNotNull($form->get_data(), 'The mocked submission must validate.');
        return $form;
    }

    public function test_a_frozen_category_or_audience_never_reaches_the_service(): void {
        // The regression found in the v2.28.0 gate: formslib exports the
        // definition DEFAULT ("No category", "Everyone") for a hard-frozen
        // element, and an edit of any row with siblings then re-filed the
        // whole group and made an agents-only article public. The service
        // payload must carry neither when the form did not offer them.
        $form = $this->submitted(
            ['id' => 7, 'status' => kb_article::STATUS_PUBLISHED, 'shared' => true] + self::PRO,
            ['lang' => 'es']
        );
        $formdata = $form->get_data();
        $this->assertSame(0, (int) $formdata->categoryid, 'formslib exports the default for the frozen select');
        $this->assertSame(kb_article::AUDIENCE_EVERYONE, (int) $formdata->audience);

        $data = $form->to_service_data($formdata);
        $this->assertObjectNotHasProperty('categoryid', $data);
        $this->assertObjectNotHasProperty('audience', $data);
        $this->assertSame('es', $data->lang);
        $this->assertSame('Title', $data->title);
        $this->assertSame('<p>Body</p>', $data->body);

        // A translation being started: both frozen, language hidden but carried.
        $form = $this->submitted(['shared' => true, 'translate' => true] + self::PRO, ['lang' => 'es']);
        $data = $form->to_service_data($form->get_data());
        $this->assertObjectNotHasProperty('categoryid', $data);
        $this->assertObjectNotHasProperty('audience', $data);
        $this->assertSame('es', $data->lang);

        // KB-02: a live article for an editor without :managekb — audience frozen, category offered.
        $form = $this->submitted(
            ['id' => 7, 'status' => kb_article::STATUS_PUBLISHED] + self::PRO,
            ['categoryid' => 3, 'lang' => 'en']
        );
        $data = $form->to_service_data($form->get_data());
        $this->assertSame(3, $data->categoryid);
        $this->assertObjectNotHasProperty('audience', $data);
    }

    public function test_offered_category_audience_and_language_do_reach_the_service(): void {
        $form = $this->submitted(self::PRO, ['categoryid' => 3, 'audience' => kb_article::AUDIENCE_AGENTS, 'lang' => 'es']);
        $data = $form->to_service_data($form->get_data());
        $this->assertSame(3, $data->categoryid);
        $this->assertSame(kb_article::AUDIENCE_AGENTS, $data->audience);
        $this->assertSame('es', $data->lang);

        // Starter: no language field, so no lang key however the POST looks.
        $form = $this->submitted(
            ['languages' => []] + self::PRO,
            ['categoryid' => 0, 'audience' => kb_article::AUDIENCE_EVERYONE, 'lang' => 'es']
        );
        $data = $form->to_service_data($form->get_data());
        $this->assertSame(0, $data->categoryid);
        $this->assertObjectNotHasProperty('lang', $data);
    }

    public function test_the_kb02_audience_rule_still_holds(): void {
        // A live article, editor without :managekb: audience frozen, category not.
        $mform = $this->definition(['id' => 7, 'status' => kb_article::STATUS_PUBLISHED] + self::PRO);
        $this->assertTrue($mform->getElement('audience')->isFrozen());
        $this->assertFalse($mform->getElement('categoryid')->isFrozen());
        $this->assertFalse($mform->elementExists('sharednote'));
    }
}
