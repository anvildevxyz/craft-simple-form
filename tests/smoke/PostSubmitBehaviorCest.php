<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;

/**
 * Post-Submit Behavior Smoke Tests (#133)
 *
 * Exercises the per-form success message override (with placeholder
 * interpolation), the global fallback, and the URL/entry redirect actions
 * through the real /simple-form/submit endpoint's JSON envelope.
 */
class PostSubmitBehaviorCest
{
    private int $siteId;
    private int $formId;
    private string $formHandle;
    private int $fieldId;

    public function _before(FunctionalTester $I): void
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'postsubmit-test-' . uniqid();
        $form->handle = $this->formHandle = 'postSubmit' . uniqid();
        $form->title = 'Post-Submit Test Form';
        $form->emailTo = 'admin@test.com';
        Craft::$app->getElements()->saveElement($form);
        $this->formId = (int) $form->id;

        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'firstName',
            'label' => 'First Name',
            'config' => json_encode([]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $this->fieldId = (int) $db->getLastInsertID();
    }

    public function testPerFormMessageInterpolatesSubmittedValue(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->siteId($this->siteId)->one();
        $form->submitMessage = 'Thanks {firstName}!';
        Craft::$app->getElements()->saveElement($form);

        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $this->fieldId => 'Ada',
        ]);

        $I->seeResponseCodeIs(200);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
        $I->assertSame('Thanks Ada!', $response['message']);
        $I->assertNull($response['redirectUrl'], 'message action has no redirect');
    }

    public function testBlankMessageFallsBackToGlobalDefault(FunctionalTester $I): void
    {
        $global = \fabianhaef\simpleform\Plugin::getInstance()->getSettings()->submitMessage;

        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $this->fieldId => 'Ada',
        ]);

        $I->seeResponseCodeIs(200);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
        $I->assertSame($global, $response['message']);
    }

    public function testUrlActionReturnsEncodedRedirect(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->siteId($this->siteId)->one();
        $form->postSubmitAction = 'url';
        $form->redirectUrl = '/thanks?n={firstName}';
        Craft::$app->getElements()->saveElement($form);

        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $this->fieldId => 'Ada Lovelace',
        ]);

        $I->seeResponseCodeIs(200);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
        $I->assertSame('/thanks?n=Ada%20Lovelace', $response['redirectUrl']);
    }

    public function testEntryActionWithMissingEntryFallsBackToInlineMessage(FunctionalTester $I): void
    {
        $form = Form::find()->id($this->formId)->siteId($this->siteId)->one();
        $form->postSubmitAction = 'entry';
        $form->redirectEntryId = 999999; // no such entry → null redirect
        Craft::$app->getElements()->saveElement($form);

        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $this->fieldId => 'Ada',
        ]);

        $I->seeResponseCodeIs(200);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
        $I->assertNull($response['redirectUrl'], 'missing entry yields inline message');
    }
}
