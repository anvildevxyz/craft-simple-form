<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SubmissionCsv;
use fabianhaef\simpleform\Plugin;

/**
 * #127 — Content & layout blocks smoke scenarios.
 *
 * Builds a form mixing the three value-less layout blocks (Heading, Section
 * Divider, HTML block) with two inputs, then asserts the public render shows the
 * presentational markup in order, a submission writes only the input values
 * (never a layout block), and the CSV export carries no layout columns. The
 * HTML block is rendered through the forced sandbox + purifier, so script
 * vectors never reach the page.
 */
class LayoutBlocksCest
{
    private int $formId;
    private int $siteId;
    private string $formHandle;
    private int $nameFieldId;
    private int $emailFieldId;

    public function _before(FunctionalTester $I): void
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'layout-blocks-' . uniqid();
        $form->handle = $this->formHandle = 'layoutBlocks' . uniqid();
        $form->title = 'Layout Blocks Smoke';
        $form->emailTo = 'admin@test.com';
        Craft::$app->getElements()->saveElement($form);
        $this->formId = (int) $form->id;

        // Heading → Name input → Divider → Email input → HTML block.
        $this->field('heading', 'sectionA', 'Personal details', ['level' => 'h2']);
        $this->nameFieldId = $this->field('text', 'fullName', 'Full Name');
        $this->field('divider', 'div1', 'And more');
        $this->emailFieldId = $this->field('email', 'email', 'Email');
        $this->field('html', 'note', '', [], '<p>Read the <a href="https://example.com">policy</a>.</p><script>alert(1)</script>');
    }

    public function testPublicFormRendersLayoutBlocksSafelyInOrder(FunctionalTester $I): void
    {
        $html = Craft::$app->getView()->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('<h2 class="simple-form-heading">Personal details</h2>', $html);
        $I->assertStringContainsString('simple-form-divider__label', $html);
        $I->assertStringContainsString('And more', $html);
        $I->assertStringContainsString('simple-form-html', $html);
        $I->assertStringContainsString('href="https://example.com"', $html);
        // The script vector in the HTML block is purified away.
        $I->assertStringNotContainsString('<script', $html);

        // Order: heading before the name input.
        $I->assertLessThan(
            strpos($html, 'name="field_' . $this->nameFieldId . '"'),
            strpos($html, '<h2')
        );
    }

    public function testSubmissionAndExportSkipLayoutBlocks(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->siteId($this->siteId)->one();
        $result = Plugin::getInstance()->getSubmissionService()->submit($form, [
            'field_' . $this->nameFieldId => 'Ada',
            'field_' . $this->emailFieldId => 'ada@example.test',
            // Forged values against the layout blocks must be ignored.
            'field_999999' => '<script>',
        ], ['skipCaptcha' => true]);

        $I->assertNull($result['errors']);
        $submission = $result['submission'];
        $I->assertNotNull($submission);

        $I->assertEqualsCanonicalizing(
            ['field_' . $this->nameFieldId, 'field_' . $this->emailFieldId],
            array_keys($submission->data)
        );

        $submissions = Submission::find()->formId($this->formId)->all();
        $csv = SubmissionCsv::fromSubmissions($submissions);
        $header = explode("\n", trim($csv))[0];
        $I->assertStringContainsString('Full Name', $header);
        $I->assertStringNotContainsString('Personal details', $header);
        $I->assertStringNotContainsString('note', $header);
    }

    /**
     * Insert a field with its per-site label/helpText, returning its id. Layout
     * blocks carry their per-site content in label (heading text / divider
     * label) and helpText (HTML body), so the smoke seeds those columns directly.
     *
     * @param array<string,mixed> $config
     */
    private function field(string $type, string $handle, string $label, array $config = [], string $helpText = ''): int
    {
        static $sort = 0;
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => $type,
            'name' => $handle,
            'config' => json_encode($config),
            'sortOrder' => ++$sort,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        $fieldId = (int) $db->getLastInsertID();

        $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
            'fieldId' => $fieldId,
            'siteId' => $this->siteId,
            'label' => $label,
            'helpText' => $helpText ?: null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return $fieldId;
    }
}
