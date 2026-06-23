<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\console\controllers\FormsController;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use yii\console\ExitCode;

/**
 * #225 — id-stable in-place update of an existing form from its config file.
 * Re-applying an edited file must keep the form's element id and every matched
 * field's id (so submissions survive), add new fields, and prune only fields
 * with no submission data.
 *
 * @group requires-craft
 */
class IdStableApplyTest extends SimpleFormTestCase
{
    private string $dir = '';
    private bool $createdDir = false;
    private string $handle = 'idStableForm';

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
        @unlink($this->dir . '/' . $this->handle . '.json');
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

    /** @return array<string, mixed> */
    private function exportDoc(Form $form): array
    {
        return json_decode(Plugin::getInstance()->getPortability()->exportJson($form), true);
    }

    /** @param array<string, mixed> $doc */
    private function writeConfig(array $doc): void
    {
        file_put_contents($this->dir . '/' . $this->handle . '.json', json_encode($doc));
    }

    private function seedFormWithSubmission(): Form
    {
        $form = $this->createForm('Id Stable', $this->handle);
        $nameId = $this->createField((int) $form->id, 'text', 'fullName', 'Full Name', true);
        $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $sub = new Submission();
        $sub->formId = (int) $form->id;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = ['field_' . $nameId => ['label' => 'Full Name', 'type' => 'text', 'value' => 'Ada']];
        Craft::$app->getElements()->saveElement($sub);

        return Form::find()->id($form->id)->siteId('*')->status(null)->one();
    }

    public function testUpdateKeepsFormAndFieldIdsAndSubmissions(): void
    {
        $this->requireCraft();
        $form = $this->seedFormWithSubmission();
        $formId = (int) $form->id;
        $nameId = $this->fieldId($formId, 'fullName');
        $emailId = $this->fieldId($formId, 'email');
        $this->assertNotNull($nameId);

        // Edit the file: add a phone field, keep the rest.
        $doc = $this->exportDoc($form);
        $doc['fields'][] = [
            'handle' => 'phone',
            'type' => 'phone',
            'required' => false,
            'sortOrder' => 99,
            'config' => [],
            'content' => [],
        ];
        $this->writeConfig($doc);

        $this->assertSame(ExitCode::OK, $this->controller()->actionApply());

        // Same form element, same field ids -> submission still resolves.
        $this->assertTrue(Form::find()->id($formId)->siteId('*')->status(null)->exists(), 'form id is stable');
        $this->assertSame($nameId, $this->fieldId($formId, 'fullName'), 'fullName field id is stable');
        $this->assertSame($emailId, $this->fieldId($formId, 'email'), 'email field id is stable');
        $this->assertNotNull($this->fieldId($formId, 'phone'), 'new field was added');

        $sub = Submission::find()->formId($formId)->one();
        $this->assertNotNull($sub, 'submission survives the update');
        $this->assertArrayHasKey('field_' . $nameId, $sub->data);
    }

    public function testPruneKeepsFieldThatHasSubmissionData(): void
    {
        $this->requireCraft();
        $form = $this->seedFormWithSubmission(); // submission has data for fullName
        $formId = (int) $form->id;

        // File without fullName (the data-bearing field).
        $doc = $this->exportDoc($form);
        $doc['fields'] = array_values(array_filter(
            $doc['fields'],
            static fn(array $f): bool => $f['handle'] !== 'fullName',
        ));
        $this->writeConfig($doc);

        $c = $this->controller();
        $c->prune = true;
        $this->assertSame(ExitCode::OK, $c->actionApply());

        $this->assertNotNull(
            $this->fieldId($formId, 'fullName'),
            'a field with submission data must be kept even with --prune',
        );
    }

    public function testPruneRemovesFieldWithoutData(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Id Stable', $this->handle);
        $this->createField((int) $form->id, 'text', 'keep', 'Keep', true);
        $this->createField((int) $form->id, 'text', 'unused', 'Unused', false);
        $form = Form::find()->id($form->id)->siteId('*')->status(null)->one();
        $formId = (int) $form->id;

        // File without 'unused'; no submissions exist, so prune may remove it.
        $doc = $this->exportDoc($form);
        $doc['fields'] = array_values(array_filter(
            $doc['fields'],
            static fn(array $f): bool => $f['handle'] !== 'unused',
        ));
        $this->writeConfig($doc);

        $c = $this->controller();
        $c->prune = true;
        $this->assertSame(ExitCode::OK, $c->actionApply());

        $this->assertNull($this->fieldId($formId, 'unused'), 'an empty field is pruned');
        $this->assertNotNull($this->fieldId($formId, 'keep'), 'kept field remains');
    }
}
