<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use Craft;
use craft\db\Query;
use SmokeTester;

/**
 * Submission trash smoke tests (#113): a soft delete hides the submission from
 * normal queries while keeping its plugin row (restorable), and a permanent
 * delete cascades the plugin row away.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class SubmissionTrashSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testSoftDeleteHidesThenRestores(SmokeTester $I): void
    {
        $submission = $this->seedSubmission('trashRestore' . uniqid());
        $id = (int) $submission->id;

        $I->assertTrue(Craft::$app->getElements()->deleteElement($submission));
        $I->assertNull(Submission::find()->id($id)->one(), 'trashed submission is excluded from normal queries');
        $I->assertNotNull(Submission::find()->id($id)->trashed(true)->one(), 'trashed submission is found via the trashed query');
        $I->assertTrue($this->rowExists($id), 'the plugin row is retained on soft delete');

        $I->assertTrue(Craft::$app->getElements()->restoreElement($submission));
        $I->assertNotNull(Submission::find()->id($id)->one(), 'restored submission reappears');
    }

    public function testPermanentDeleteCascadesPluginRow(SmokeTester $I): void
    {
        $submission = $this->seedSubmission('trashHard' . uniqid());
        $id = (int) $submission->id;

        Craft::$app->getElements()->deleteElement($submission, true);

        $I->assertNull(Submission::find()->id($id)->trashed(null)->one());
        $I->assertFalse($this->rowExists($id), 'the plugin row is cascade-deleted with the element');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function seedSubmission(string $handle): Submission
    {
        $form = $this->createForm('Trash', $handle);
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        return $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada'])['submission'];
    }

    private function rowExists(int $id): bool
    {
        return (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $id])->exists();
    }
}
