<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use craft\db\Query;
use SmokeTester;

/**
 * Conditional logic smoke tests (functional).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class ConditionalLogicSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testHiddenRequiredFieldDoesNotBlockSubmission(SmokeTester $I): void
    {
        $form = $this->createForm('Conditional', 'condHidden' . uniqid());
        $accountType = $this->createField((int) $form->id, 'select', 'accountType', 'Account type', false, [
            'options' => [
                ['value' => 'personal', 'label' => 'Personal'],
                ['value' => 'business', 'label' => 'Business'],
            ],
        ]);
        $vatId = $this->createField((int) $form->id, 'text', 'vat', 'VAT', true, [
            'conditional' => [
                'enabled' => true,
                'action' => 'show',
                'match' => 'all',
                'rules' => [
                    ['field' => 'accountType', 'operator' => 'eq', 'value' => 'business'],
                ],
            ],
        ]);

        $result = $this->submitRequest($form->handle, [
            'field_' . $accountType => 'personal',
            'field_' . $vatId => '',
        ]);

        $I->assertNull($result['errors']);
        $I->assertInstanceOf(Submission::class, $result['submission']);
        $I->assertArrayNotHasKey('field_' . $vatId, $result['submission']->data);
    }

    public function testShownRequiredFieldIsEnforced(SmokeTester $I): void
    {
        $form = $this->createForm('Conditional Shown', 'condShown' . uniqid());
        $accountType = $this->createField((int) $form->id, 'select', 'accountType', 'Account type', false, [
            'options' => [
                ['value' => 'personal', 'label' => 'Personal'],
                ['value' => 'business', 'label' => 'Business'],
            ],
        ]);
        $vatId = $this->createField((int) $form->id, 'text', 'vat', 'VAT', true, [
            'conditional' => [
                'enabled' => true,
                'action' => 'show',
                'match' => 'all',
                'rules' => [
                    ['field' => 'accountType', 'operator' => 'eq', 'value' => 'business'],
                ],
            ],
        ]);

        $before = (int) (new Query())->from('{{%simpleform_submissions}}')->count();
        $result = $this->submitRequest($form->handle, [
            'field_' . $accountType => 'business',
            'field_' . $vatId => '',
        ]);
        $after = (int) (new Query())->from('{{%simpleform_submissions}}')->count();

        $I->assertNull($result['submission']);
        $I->assertArrayHasKey('field_' . $vatId, $result['errors']);
        $I->assertSame($before, $after);
    }
}
