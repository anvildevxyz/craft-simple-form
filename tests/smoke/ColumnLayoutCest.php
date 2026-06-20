<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;

/**
 * Multi-Column Row Layout Smoke Tests (issue #136)
 *
 * Verifies that adjacent fields sharing a `config.row` render as a responsive
 * CSS-grid row, that single-column forms stay byte-for-byte unchanged, that
 * columns compose within multi-step pages, and that the grid CSS (including the
 * mobile collapse) ships with the form.
 */
class ColumnLayoutCest
{
    private $formId;
    private $siteId;
    private $formHandle;

    public function _before(FunctionalTester $I)
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'column-layout-' . uniqid();
        $form->handle = $this->formHandle = 'columnLayout' . uniqid();
        $form->title = 'Column Layout Test';
        $form->emailTo = 'admin@test.com';

        Craft::$app->getElements()->saveElement($form);
        $this->formId = $form->id;
    }

    private function addField(string $type, string $name, string $label, array $config, int $sortOrder): void
    {
        Craft::$app->getDb()->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => $type,
            'name' => $name,
            'label' => $label,
            'config' => json_encode($config),
            'sortOrder' => $sortOrder,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
    }

    private function render(): string
    {
        return Craft::$app->getView()->renderString('{{ simpleForm("' . $this->formHandle . '") }}');
    }

    public function testTwoFieldsRenderSideBySide(FunctionalTester $I)
    {
        $this->addField('text', 'firstName', 'First Name', ['row' => 1], 1);
        $this->addField('text', 'lastName', 'Last Name', ['row' => 1], 2);

        $html = $this->render();

        $I->assertStringContainsString('class="simple-form-row" data-cols="2"', $html);
        $I->assertSame(2, substr_count($html, 'class="simple-form-col"'));
        $I->assertStringContainsString('First Name', $html);
        $I->assertStringContainsString('Last Name', $html);
    }

    public function testGridCollapsesOnMobileViaCss(FunctionalTester $I)
    {
        $this->addField('text', 'firstName', 'First Name', ['row' => 1], 1);
        $this->addField('text', 'lastName', 'Last Name', ['row' => 1], 2);

        $html = $this->render();

        // Pure-CSS responsive collapse (works with JS off).
        $I->assertStringContainsString('.simple-form-row', $html);
        $I->assertStringContainsString('grid-template-columns: repeat(2, 1fr)', $html);
        $I->assertStringContainsString('@media (max-width: 600px)', $html);
    }

    public function testSingleColumnFormHasNoRowWrapper(FunctionalTester $I)
    {
        $this->addField('text', 'fullName', 'Full Name', [], 1);
        $this->addField('email', 'email', 'Email', [], 2);

        $html = $this->render();

        // Existing single-column markup is unchanged: no grid wrapper emitted.
        $I->assertStringNotContainsString('simple-form-row" data-cols', $html);
        $I->assertStringNotContainsString('class="simple-form-col"', $html);
    }

    public function testColumnsComposeWithinSteps(FunctionalTester $I)
    {
        $this->addField('text', 'firstName', 'First', ['page' => 1, 'row' => 1], 1);
        $this->addField('text', 'lastName', 'Last', ['page' => 1, 'row' => 1], 2);
        $this->addField('textarea', 'comment', 'Comment', ['page' => 2], 3);

        $html = $this->render();

        $I->assertStringContainsString('data-sf-step="0"', $html);
        $I->assertStringContainsString('data-sf-step="1"', $html);
        // Exactly one grid row, and it lives in step 1.
        $I->assertSame(1, substr_count($html, 'class="simple-form-row"'));
    }

    public function testRowCapsAtFourColumns(FunctionalTester $I)
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->addField('text', 'f' . $i, 'Field ' . $i, ['row' => 1], $i);
        }

        $html = $this->render();

        // First four columns group; the fifth spills out of the grid wrapper.
        $I->assertStringContainsString('data-cols="4"', $html);
        $I->assertSame(4, substr_count($html, 'class="simple-form-col"'));
    }
}
