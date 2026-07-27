<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use SmokeTester;

/**
 * Front-end submission editing smoke tests (functional).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SubmissionEditingSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testEditTokenIssueAndVerify(SmokeTester $I): void
    {
        $form = $this->editableForm('token' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submission = $this->submitDirect($form, ['field_' . $fieldId => 'Ada'])['submission'];

        $tokens = Plugin::getInstance()->getSubmissionEditTokens();
        $token = $tokens->issue($submission);

        $I->assertNotSame('', $token);

        $stored = (new Query())
            ->select(['editTokenHash'])
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $submission->id])
            ->scalar();
        $I->assertSame(hash('sha256', $token), $stored);

        $reloaded = Submission::find()->id($submission->id)->one();
        $I->assertTrue($tokens->verify($reloaded, $token));
        $I->assertFalse($tokens->verify($reloaded, $token . 'x'));
    }

    public function testTokenAuthorizesEdit(SmokeTester $I): void
    {
        $form = $this->editableForm('auth' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submission = $this->submitDirect($form, ['field_' . $fieldId => 'Ada'])['submission'];

        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($submission);
        $reloaded = Submission::find()->id($submission->id)->one();

        $I->assertSame(
            'token',
            Plugin::getInstance()->getSubmissionService()->authorizeEdit($reloaded, $token, null),
        );
    }

    public function testUpdatePreservesIdentityAndChangesValue(SmokeTester $I): void
    {
        $form = $this->editableForm('update' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submission = $this->submitDirect($form, ['field_' . $fieldId => 'Ada'])['submission'];
        $originalId = $submission->id;

        $reloaded = Submission::find()->id($submission->id)->one();
        $result = Plugin::getInstance()->getSubmissionService()->update($reloaded, [$fieldId => 'Grace'], [
            'skipCaptcha' => true,
            'actor' => 'token',
        ]);

        $I->assertNull($result['errors']);
        $I->assertSame($originalId, $result['submission']->id);
        $I->assertSame('Grace', $result['submission']->data['field_' . $fieldId]['value']);
    }

    public function testEditingDisabledFormRefusesToken(SmokeTester $I): void
    {
        $form = $this->createForm('Locked', 'locked' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submission = $this->submitDirect($form, ['field_' . $fieldId => 'Ada'])['submission'];

        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($submission);
        $reloaded = Submission::find()->id($submission->id)->one();

        $I->assertNull(
            Plugin::getInstance()->getSubmissionService()->authorizeEdit($reloaded, $token, null),
        );
    }

    // =========================================================================
    // PROTECTED METHODS
    // =========================================================================

    protected function editableForm(string $handle): Form
    {
        $form = $this->createForm('Editable', $handle);
        $form->allowEditing = true;
        Craft::$app->getElements()->saveElement($form);

        return $form;
    }
}
