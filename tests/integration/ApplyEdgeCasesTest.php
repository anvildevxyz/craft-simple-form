<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\console\controllers\FormsController;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\FieldQueryHelper;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use yii\console\ExitCode;

/**
 * #225 edge cases — "what if somebody changes it along the way." Covers form-level
 * edits, the file-wins content semantics over a CP edit, handle renames, a
 * truncated/empty fields list, invalid JSON, and submission survival.
 *
 * @group requires-craft
 */
class ApplyEdgeCasesTest extends SimpleFormTestCase
{
    private string $dir = '';
    private bool $createdDir = false;
    private string $handle = 'edgeForm';

    protected function _before(): void
    {
        $this->dir = (string) Craft::getAlias('@config') . '/simple-form/forms';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
            $this->createdDir = true;
        }
    }

    protected function _after(): void
    {
        foreach (glob($this->dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        if ($this->createdDir && is_dir($this->dir)) {
            @rmdir($this->dir);
            @rmdir(dirname($this->dir));
        }
    }

    private function controller(): FormsController
    {
        return new FormsController('forms', Craft::$app);
    }

    private function fieldId(int $formId, string $handle): ?int
    {
        $id = (new Query())->select(['id'])->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId, 'name' => $handle])->scalar();
        return $id !== false && $id !== null ? (int) $id : null;
    }

    private function label(int $formId, string $handle): ?string
    {
        foreach (FieldQueryHelper::fieldsForForm($formId, Craft::$app->getSites()->getCurrentSite()->id) as $row) {
            if ((string) $row['name'] === $handle) {
                return (string) $row['label'];
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function exportDoc(int $formId): array
    {
        $form = Form::find()->id($formId)->siteId('*')->status(null)->one();
        return json_decode(Plugin::getInstance()->getPortability()->exportJson($form), true);
    }

    /** @param array<string, mixed> $doc */
    private function write(array $doc): void
    {
        file_put_contents($this->dir . '/' . $this->handle . '.json', json_encode($doc));
    }

    private function seed(): int
    {
        $form = $this->createForm('Edge', $this->handle, emailTo: 'old@example.com');
        $this->createField((int) $form->id, 'text', 'fullName', 'Full Name', true);
        $this->createField((int) $form->id, 'email', 'email', 'Email', true);
        return (int) $form->id;
    }

    // 1. Form-level edits in the file (name, title, recipient) are applied.
    public function testFormLevelChangesAreApplied(): void
    {
        $this->requireCraft();
        $formId = $this->seed();

        $doc = $this->exportDoc($formId);
        $doc['form']['name'] = 'Renamed Form';
        $siteHandle = Craft::$app->getSites()->getCurrentSite()->handle;
        $doc['form']['content'][$siteHandle]['title'] = 'New Title';
        $doc['form']['content'][$siteHandle]['emailTo'] = 'new@example.com';
        $this->write($doc);

        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());

        $form = Form::find()->id($formId)->siteId('*')->status(null)->one();
        $this->assertSame('Renamed Form', $form->name);
        $this->assertSame('New Title', $form->title);
        $this->assertSame('new@example.com', $form->emailTo);
    }

    // 2. The file is authoritative: a CP-side content edit is restored on apply.
    public function testFileWinsOverCpContentEdit(): void
    {
        $this->requireCraft();
        $formId = $this->seed();
        $doc = $this->exportDoc($formId); // label "Full Name"
        $this->write($doc);

        // Simulate an editor changing the label in the CP.
        $fid = $this->fieldId($formId, 'fullName');
        Craft::$app->getDb()->createCommand()->update(
            '{{%simpleform_fields_sites}}',
            ['label' => 'Edited In CP'],
            ['fieldId' => $fid],
        )->execute();
        Plugin::getInstance()->getFormStructure()->invalidate($formId);
        $this->assertSame('Edited In CP', $this->label($formId, 'fullName'));

        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());
        $this->assertSame('Full Name', $this->label($formId, 'fullName'), 'file restores the label');
    }

    // 3. Renaming a handle in the file = a NEW field; the old one is kept (and its
    //    data) unless pruned. This is the documented rename gotcha.
    public function testHandleRenameAddsNewKeepsOld(): void
    {
        $this->requireCraft();
        $formId = $this->seed();

        $doc = $this->exportDoc($formId);
        foreach ($doc['fields'] as &$f) {
            if ($f['handle'] === 'fullName') {
                $f['handle'] = 'name';
            }
        }
        unset($f);
        $this->write($doc);

        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());

        $this->assertNotNull($this->fieldId($formId, 'name'), 'renamed handle is added as a new field');
        $this->assertNotNull($this->fieldId($formId, 'fullName'), 'old handle is kept (data-safe) without --prune');
    }

    // 4. A valid file whose fields list is empty must NOT wipe the form's fields
    //    when not pruning (truncation safety).
    public function testEmptyFieldsListDoesNotWipeWithoutPrune(): void
    {
        $this->requireCraft();
        $formId = $this->seed();
        $doc = $this->exportDoc($formId);
        $doc['fields'] = [];
        $this->write($doc);

        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());

        $this->assertNotNull($this->fieldId($formId, 'fullName'));
        $this->assertNotNull($this->fieldId($formId, 'email'));
    }

    // 5. Invalid JSON is skipped with a warning, never crashes or mutates the DB.
    public function testInvalidJsonIsSkippedSafely(): void
    {
        $this->requireCraft();
        $formId = $this->seed();
        file_put_contents($this->dir . '/' . $this->handle . '.json', '{ this is not: valid json ');

        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());
        // The form is untouched.
        $this->assertNotNull($this->fieldId($formId, 'fullName'));
        $this->assertNotNull($this->fieldId($formId, 'email'));
    }

    // 6. A submission's stored value survives a structural re-apply intact.
    public function testSubmissionValueSurvivesUpdate(): void
    {
        $this->requireCraft();
        $formId = $this->seed();
        $fid = $this->fieldId($formId, 'fullName');

        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = ['field_' . $fid => ['label' => 'Full Name', 'type' => 'text', 'value' => 'Grace Hopper']];
        Craft::$app->getElements()->saveElement($sub);
        $subId = (int) $sub->id;

        // Re-apply with an added field (structural change).
        $doc = $this->exportDoc($formId);
        $doc['fields'][] = ['handle' => 'phone', 'type' => 'phone', 'required' => false, 'sortOrder' => 9, 'config' => [], 'content' => []];
        $this->write($doc);
        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());

        $reloaded = Submission::find()->id($subId)->one();
        $this->assertNotNull($reloaded);
        $this->assertSame('Grace Hopper', $reloaded->data['field_' . $fid]['value'], 'value intact after update');
        $this->assertSame($fid, $this->fieldId($formId, 'fullName'), 'field id unchanged');
    }
}
