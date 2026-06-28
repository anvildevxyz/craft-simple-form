<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use Craft;
use craft\db\Query;

/**
 * Submission soft-delete / trash + restore (#113), and the elements→submission
 * cascade that keeps the plugin row in sync on permanent delete.
 */
class SubmissionTrashTest extends SimpleFormTestCase
{
    private function makeSubmission(int $formId): Submission
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = ['field_1' => ['label' => 'Name', 'value' => 'Ada']];
        $sub->readStatus = SubmissionStatus::NEW;
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    private function rowExists(int $id): bool
    {
        return (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $id])->exists();
    }

    public function testSoftDeleteHidesButKeepsRowThenRestores(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'trash_form');
        $submission = $this->makeSubmission((int) $form->id);
        $id = (int) $submission->id;

        // Soft delete (default): excluded from normal queries, present as trashed,
        // plugin row retained.
        $this->assertTrue(Craft::$app->getElements()->deleteElement($submission));
        $this->assertNull(Submission::find()->id($id)->one(), 'trashed submission excluded from normal query');
        $this->assertNotNull(Submission::find()->id($id)->trashed(true)->one(), 'trashed submission found via trashed query');
        $this->assertTrue($this->rowExists($id), 'plugin row retained on soft delete');

        // Restore: reappears in normal queries.
        $this->assertTrue(Craft::$app->getElements()->restoreElement($submission));
        $this->assertNotNull(Submission::find()->id($id)->one(), 'restored submission reappears');
    }

    public function testPermanentDeleteCascadesPluginRow(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'trash_hard');
        $submission = $this->makeSubmission((int) $form->id);
        $id = (int) $submission->id;

        Craft::$app->getElements()->deleteElement($submission, true);

        $this->assertNull(Submission::find()->id($id)->trashed(null)->one());
        $this->assertFalse($this->rowExists($id), 'plugin row cascade-deleted with the element');
    }
}
