<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\web\Response;
use fabianhaef\simpleform\controllers\SubmissionsController;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\Plugin;

/**
 * The spam-review queue: a Spam source (readStatus = spam), the spamReason
 * lifecycle, and the "Mark as not spam" approve action.
 *
 * @group requires-craft
 */
class SpamReviewQueueTest extends SimpleFormTestCase
{
    private function makeSubmission(int $formId, string $status, ?string $reason = null): Submission
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [];
        $sub->readStatus = $status;
        $sub->spamReason = $reason;
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    public function testSpamSourceCriteriaReturnsOnlyFlagged(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Queue', 'spam_queue_form');

        $this->makeSubmission((int) $form->id, SubmissionStatus::NEW);
        $this->makeSubmission((int) $form->id, SubmissionStatus::SPAM, 'akismet');
        $this->makeSubmission((int) $form->id, SubmissionStatus::SPAM, 'manual');

        // This is exactly the criteria the "Spam" index source uses.
        $spam = Submission::find()->formId((int) $form->id)->readStatus(SubmissionStatus::SPAM)->all();
        $this->assertCount(2, $spam);
        foreach ($spam as $s) {
            $this->assertSame(SubmissionStatus::SPAM, $s->readStatus);
            $this->assertContains($s->spamReason, ['akismet', 'manual']);
        }
    }

    public function testMarkNotSpamRestoresStatusAndClearsReason(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Approve', 'spam_approve_form');
        $sub = $this->makeSubmission((int) $form->id, SubmissionStatus::SPAM, 'akismet');

        $this->assertTrue(
            Plugin::getInstance()->getSubmissionService()->updateStatus((int) $sub->id, SubmissionStatus::NEW),
        );

        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $sub->id])->one();
        $this->assertSame(SubmissionStatus::NEW, $row['readStatus']);
        $this->assertNull($row['spamReason'], 'approving must clear the spam reason');
    }

    public function testManualMarkSpamRecordsManualReason(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Manual', 'spam_manual_form');
        $sub = $this->makeSubmission((int) $form->id, SubmissionStatus::NEW);

        Plugin::getInstance()->getSubmissionService()->updateStatus((int) $sub->id, SubmissionStatus::SPAM);

        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $sub->id])->one();
        $this->assertSame(SubmissionStatus::SPAM, $row['readStatus']);
        $this->assertSame('manual', $row['spamReason']);
    }

    private function runAction(string $action, int $submissionId): void
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(['submissionId' => $submissionId]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmissionsController('submissions', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $controller->$action();
    }

    public function testMarkNotSpamControllerApprovesAndClearsReason(): void
    {
        $this->requireCraft();
        $form = $this->createForm('CtrlApprove', 'spam_ctrl_approve');
        $sub = $this->makeSubmission((int) $form->id, SubmissionStatus::SPAM, 'akismet');

        $this->runAction('actionMarkNotSpam', (int) $sub->id);

        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $sub->id])->one();
        $this->assertSame(SubmissionStatus::NEW, $row['readStatus']);
        $this->assertNull($row['spamReason']);
    }

    public function testDeleteControllerSoftDeletesSubmission(): void
    {
        $this->requireCraft();
        $form = $this->createForm('CtrlDelete', 'spam_ctrl_delete');
        $sub = $this->makeSubmission((int) $form->id, SubmissionStatus::SPAM, 'manual');

        $this->runAction('actionDelete', (int) $sub->id);

        // Soft-deleted: gone from normal queries, still recoverable via trashed().
        $this->assertNull(Submission::find()->id($sub->id)->one());
        $this->assertNotNull(Submission::find()->id($sub->id)->trashed()->one());
    }
}
