<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;

/**
 * Front-end submission editing smoke tests (#144): a tokenized edit round-trips
 * through the public update endpoint, a tampered token is refused, and the edit
 * window is enforced server-side.
 */
class SubmissionEditingCest
{
    private $formId;
    private $siteId;
    private $formHandle;
    private $fieldId;

    public function _before(FunctionalTester $I)
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'edit-test-' . uniqid();
        $form->handle = $this->formHandle = 'editTest' . uniqid();
        $form->title = 'Edit Test Form';
        $form->allowEditing = true;
        Craft::$app->getElements()->saveElement($form);
        $this->formId = $form->id;

        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'name',
            'label' => 'Name',
            'config' => json_encode([]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $this->fieldId = (int) $db->getLastInsertID();
    }

    /** Submit the form anonymously and return the stored submission. */
    private function seedSubmission(string $value): Submission
    {
        $result = Plugin::getInstance()->getSubmissionService()->submit(
            Form::find()->id($this->formId)->one(),
            [$this->fieldId => $value],
            ['skipCaptcha' => true, 'siteId' => $this->siteId],
        );
        return $result['submission'];
    }

    public function testTokenizedEditUpdatesTheSameSubmission(FunctionalTester $I)
    {
        $submission = $this->seedSubmission('Ada');
        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($submission);

        $I->sendPost('/simple-form/submission-edit/update', [
            'submissionId' => $submission->id,
            't' => $token,
            'field_' . $this->fieldId => 'Grace',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success'] ?? false, 'A valid token should authorize the edit');

        $final = Submission::find()->id($submission->id)->one();
        $I->assertEquals('Grace', $final->data['field_' . $this->fieldId]['value']);
        $I->assertEquals($submission->id, $final->id, 'The same submission is updated, not a new one');
    }

    public function testTamperedTokenIsRefused(FunctionalTester $I)
    {
        $submission = $this->seedSubmission('Ada');
        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($submission);

        $I->sendPost('/simple-form/submission-edit/update', [
            'submissionId' => $submission->id,
            't' => $token . 'tamper',
            'field_' . $this->fieldId => 'Hacked',
        ]);

        $I->seeResponseCodeIs(403);
        $unchanged = Submission::find()->id($submission->id)->one();
        $I->assertEquals('Ada', $unchanged->data['field_' . $this->fieldId]['value']);
    }

    public function testEditOutsideWindowIsRefused(FunctionalTester $I)
    {
        $form = Form::find()->id($this->formId)->one();
        $form->editWindowMinutes = 10;
        Craft::$app->getElements()->saveElement($form);

        $submission = $this->seedSubmission('Ada');
        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($submission);

        // Back-date the submission beyond the window.
        Craft::$app->getDb()->createCommand()->update(
            '{{%elements}}',
            ['dateCreated' => date('Y-m-d H:i:s', time() - 1200)],
            ['id' => $submission->id],
        )->execute();

        $I->sendPost('/simple-form/submission-edit/update', [
            'submissionId' => $submission->id,
            't' => $token,
            'field_' . $this->fieldId => 'Late',
        ]);

        $I->seeResponseCodeIs(403);
        $unchanged = Submission::find()->id($submission->id)->one();
        $I->assertEquals('Ada', $unchanged->data['field_' . $this->fieldId]['value']);
    }
}
