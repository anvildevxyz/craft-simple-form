<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Plugin;
use Craft;
use SmokeTester;

/**
 * Authored field default values (#295): a field's `config.defaultValue` prefills
 * the input on a fresh render, but any resume / query / submitted value takes
 * precedence so a visitor's own input always wins.
 *
 * @author Fabian Haefliger
 * @since 2.17.0
 */
class FieldDefaultValueCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * A configured default prefills the input the first time the form renders.
     */
    public function testDefaultValuePrefillsFreshRender(SmokeTester $I): void
    {
        $form = $this->createForm('Defaults ' . uniqid(), 'defaults' . uniqid());
        $this->createField((int) $form->id, 'text', 'colour', 'Colour', false, ['defaultValue' => 'blue']);

        $html = $this->renderForm($form->handle);

        $I->assertStringContainsString('value="blue"', $html, 'the default prefills the input on a fresh render');
    }

    /**
     * A field with no default renders with no prefilled value — the default is
     * strictly opt-in.
     */
    public function testFieldWithoutDefaultRendersEmpty(SmokeTester $I): void
    {
        $form = $this->createForm('NoDefault ' . uniqid(), 'noDefault' . uniqid());
        $this->createField((int) $form->id, 'text', 'plain', 'Plain');

        $html = $this->renderForm($form->handle);

        $I->assertStringContainsString('name="field_', $html, 'the field renders');
        $I->assertStringNotContainsString('value="blue"', $html, 'no stray default appears');
    }

    /**
     * On an edit render, the submitted value is shown — never overwritten by the
     * authored default.
     */
    public function testSubmittedValueOverridesDefaultOnEdit(SmokeTester $I): void
    {
        $form = $this->createForm('DefEdit ' . uniqid(), 'defEdit' . uniqid());
        $form->allowEditing = true;
        Craft::$app->getElements()->saveElement($form);
        $fieldId = $this->createField((int) $form->id, 'text', 'colour', 'Colour', false, ['defaultValue' => 'blue']);

        $submission = $this->submitRequest($form->handle, ['field_' . $fieldId => 'red'])['submission'];
        $I->assertNotNull($submission);

        $html = Plugin::getInstance()->getFormRender()->renderEditForm($submission, []);

        $I->assertStringContainsString('value="red"', $html, 'the submitted value shows on edit');
        $I->assertStringNotContainsString('value="blue"', $html, 'the default never overrides the submitted value');
    }
}
