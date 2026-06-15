<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * Server-side enforcement of field conditionals through the real submission
 * entry point (the same path SubmitController + the GraphQL mutation use).
 *
 * Covers the security/correctness guarantees: a hidden field is neither
 * validated nor persisted, a hidden required field cannot block submission,
 * and a conditionally-required visible field IS enforced.
 *
 * @group requires-craft
 */
class ConditionalSubmissionTest extends SimpleFormTestCase
{
    public function testHiddenRequiredFieldDoesNotBlockSubmission(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Cond Hidden', 'condHiddenForm', 'Cond Hidden');
        $accountType = $this->createField($form->id, 'select', 'accountType', 'Account type', false, [
            'options' => [
                ['value' => 'personal', 'label' => 'Personal'],
                ['value' => 'business', 'label' => 'Business'],
            ],
        ]);
        // VAT is required, but only shown when account type is "business".
        $vatFieldId = $this->createField($form->id, 'text', 'vat', 'VAT number', true, [
            'conditional' => [
                'enabled' => true,
                'action' => 'show',
                'match' => 'all',
                'rules' => [
                    ['fieldId' => $accountType, 'operator' => 'eq', 'value' => 'business'],
                ],
            ],
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'condHiddenForm',
            'field_' . $accountType => 'personal', // VAT field stays hidden
            'field_' . $vatFieldId => '',          // blank, but hidden -> not required
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['errors'], 'Hidden required field must not produce a validation error');
        $this->assertInstanceOf(Submission::class, $result['submission']);

        // The hidden field's value must NOT be persisted.
        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $result['submission']->id])->one();
        $decoded = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
        $this->assertArrayNotHasKey('field_' . $vatFieldId, $decoded, 'Hidden field value must be stripped from stored data');
        $this->assertArrayHasKey('field_' . $accountType, $decoded);
    }

    public function testShownRequiredFieldIsEnforced(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Cond Shown', 'condShownForm', 'Cond Shown');
        $accountType = $this->createField($form->id, 'select', 'accountType', 'Account type', false, [
            'options' => [
                ['value' => 'personal', 'label' => 'Personal'],
                ['value' => 'business', 'label' => 'Business'],
            ],
        ]);
        $vatFieldId = $this->createField($form->id, 'text', 'vat', 'VAT number', true, [
            'conditional' => [
                'enabled' => true,
                'action' => 'show',
                'match' => 'all',
                'rules' => [
                    ['fieldId' => $accountType, 'operator' => 'eq', 'value' => 'business'],
                ],
            ],
        ]);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'condShownForm',
            'field_' . $accountType => 'business', // VAT field is now shown...
            'field_' . $vatFieldId => '',          // ...and required, left blank -> error
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('field_' . $vatFieldId, $result['errors']);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after, 'No row should be stored when a shown required field fails');
    }

    public function testConditionalRequiredEnforcedIndependentlyOfVisibility(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Cond Req', 'condReqForm', 'Cond Req');
        $reason = $this->createField($form->id, 'select', 'reason', 'Reason', false, [
            'options' => [
                ['value' => 'general', 'label' => 'General'],
                ['value' => 'other', 'label' => 'Other'],
            ],
        ]);
        // Always visible, but required only when reason is "other".
        $detailFieldId = $this->createField($form->id, 'text', 'detail', 'Please specify', false, [
            'conditional' => [
                'enabled' => true,
                'required' => [
                    'enabled' => true,
                    'match' => 'all',
                    'rules' => [
                        ['fieldId' => $reason, 'operator' => 'eq', 'value' => 'other'],
                    ],
                ],
            ],
        ]);

        // reason = other, detail blank -> conditional-required fires.
        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'condReqForm',
            'field_' . $reason => 'other',
            'field_' . $detailFieldId => '',
        ]);
        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('field_' . $detailFieldId, $result['errors']);

        // reason = general, detail blank -> not required, submits fine.
        $request2 = Craft::$app->getRequest();
        $request2->setBodyParams([
            'formHandle' => 'condReqForm',
            'field_' . $reason => 'general',
            'field_' . $detailFieldId => '',
        ]);
        $result2 = $this->submissionService()->createFromRequest($form, $request2);
        $this->assertNull($result2['errors']);
        $this->assertInstanceOf(Submission::class, $result2['submission']);
    }

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}
