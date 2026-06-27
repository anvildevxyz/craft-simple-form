<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * Passive partial capture (#242): a capture stores a passive partial and creates
 * no Submission (and so fires no notifications/integrations); completing the
 * captured attempt deletes the matching partial, leaving exactly one Submission
 * and zero partials.
 *
 * @group requires-craft
 */
class PartialCaptureTest extends SimpleFormTestCase
{
    private int $siteId;

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }

    private function captureForm(string $handle): Form
    {
        $form = $this->createForm('Lead', $handle, 'Lead');
        $form->capturePartials = true;
        Craft::$app->getElements()->saveElement($form);
        return $form;
    }

    private function draftRow(string $tokenHash): ?array
    {
        $row = (new Query())->from('{{%simpleform_form_drafts}}')->where(['tokenHash' => $tokenHash])->one();
        return is_array($row) ? $row : null;
    }

    public function testCaptureStoresPassivePartialWithoutCreatingASubmission(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->captureForm('partialCapture');
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $key = 'field_' . $fieldId;

        $token = Plugin::getInstance()->getDrafts()->save((int) $form->id, $this->siteId, [$key => 'Ada'], null, true);

        // Stored as a passive partial, visible in the CP listing.
        $partials = Plugin::getInstance()->getDrafts()->listPassive((int) $form->id, $this->siteId);
        $this->assertCount(1, $partials);
        $this->assertSame(1, $partials[0]['fieldCount']);
        $row = $this->draftRow(hash('sha256', $token));
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['passive']);

        // A capture is not a submission: nothing in submissions or the dispatch log.
        $this->assertSame(0, (int) Submission::find()->formId((int) $form->id)->status(null)->count());
        $this->assertSame(0, (int) (new Query())->from('{{%simpleform_integration_logs}}')->where(['submissionId' => null])->count());
    }

    public function testRepeatCaptureUpdatesRatherThanDuplicates(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->captureForm('partialDedup');
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $key = 'field_' . $fieldId;
        $drafts = Plugin::getInstance()->getDrafts();

        $token = $drafts->save((int) $form->id, $this->siteId, [$key => 'Ad'], null, true);
        $again = $drafts->save((int) $form->id, $this->siteId, [$key => 'Ada Lovelace'], $token, true);

        $this->assertSame($token, $again, 'same token reused');
        $partials = $drafts->listPassive((int) $form->id, $this->siteId);
        $this->assertCount(1, $partials, 'updated in place, not duplicated');
        $this->assertSame('Ada Lovelace', $partials[0]['data'][$key]);
    }

    public function testCompletingACapturedAttemptDeletesTheMatchingPartial(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->captureForm('partialComplete');
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $key = 'field_' . $fieldId;
        $drafts = Plugin::getInstance()->getDrafts();

        $token = $drafts->save((int) $form->id, $this->siteId, [$key => 'Ada'], null, true);
        $this->assertNotNull($this->draftRow(hash('sha256', $token)));

        // Final submit carries the partial token → the partial is removed.
        $result = $this->submissionService()->submit($form, [$fieldId => 'Ada'], [
            'skipCaptcha' => true,
            'partialToken' => $token,
        ]);

        $this->assertInstanceOf(Submission::class, $result['submission']);
        $this->assertSame(1, (int) Submission::find()->formId((int) $form->id)->status(null)->count(), 'exactly one Submission');
        $this->assertNull($this->draftRow(hash('sha256', $token)), 'matching partial deleted');
        $this->assertSame([], $drafts->listPassive((int) $form->id, $this->siteId), 'zero partials remain');
    }

    public function testManualDeleteIsScopedToTheForm(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->captureForm('partialDelete');
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $drafts = Plugin::getInstance()->getDrafts();

        $token = $drafts->save((int) $form->id, $this->siteId, ['field_' . $fieldId => 'Ada'], null, true);
        $partials = $drafts->listPassive((int) $form->id, $this->siteId);
        $this->assertCount(1, $partials);

        // A foreign formId must not delete this form's partial.
        $drafts->deletePassiveById($partials[0]['id'], (int) $form->id + 99999);
        $this->assertCount(1, $drafts->listPassive((int) $form->id, $this->siteId), 'wrong form id is a no-op');

        $drafts->deletePassiveById($partials[0]['id'], (int) $form->id);
        $this->assertSame([], $drafts->listPassive((int) $form->id, $this->siteId));
        $this->assertNull($this->draftRow(hash('sha256', $token)));
    }
}
