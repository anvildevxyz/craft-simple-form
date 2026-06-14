<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\helpers\SiteHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FieldsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_FORMS;
    public $enableCsrfValidation = true;

    public function actionAdd(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteFromPost($request);

        $formId = $request->getRequiredBodyParam('formId');
        $type = $request->getRequiredBodyParam('type');
        $label = $request->getRequiredBodyParam('label');
        $handle = $request->getRequiredBodyParam('handle');
        $required = (bool)$request->getBodyParam('required');
        $helpText = $request->getBodyParam('helpText', '');
        $config = json_decode($request->getBodyParam('config', '{}'), true) ?? [];

        $errors = $this->validateFieldInput($type, $label, $handle, $config, (int)$formId, null);
        if (!empty($errors)) {
            return $this->asJson(['success' => false, 'errors' => $errors]);
        }

        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $maxSort = (new Query())
            ->select(['sortOrder'])
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->max('sortOrder') ?? 0;

        try {
            // Structural (shared) row
            $db->createCommand()->insert('{{%simpleform_fields}}', [
                'formId' => $formId,
                'type' => $type,
                'name' => $handle,
                'required' => $required,
                // Pass the array; Craft's json column encodes it once. json_encode()ing
                // here would double-encode (the value becomes the string "[]").
                'config' => $config,
                'sortOrder' => $maxSort + 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $fieldId = (int)$db->getLastInsertID();

            // Per-site (translatable) rows — one per supported site, seeded with the entered label/helpText.
            foreach ($this->supportedSiteIds((int)$formId, $site->id) as $siteId) {
                $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
                    'fieldId' => $fieldId,
                    'siteId' => $siteId,
                    'label' => $label,
                    'helpText' => $helpText ?: null,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])->execute();
            }

            return $this->asJson([
                'success' => true,
                'fieldId' => $fieldId,
                'message' => 'Field added successfully',
            ]);
        } catch (\Exception $e) {
            Craft::warning('Error adding field: ' . $e->getMessage(), 'simple-form');
            return $this->asJson(['success' => false, 'error' => 'Failed to add field']);
        }
    }

    public function actionEdit(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteFromPost($request);

        $fieldId = $request->getRequiredBodyParam('fieldId');
        $label = $request->getBodyParam('label');
        $handle = $request->getBodyParam('handle');
        $required = (bool)$request->getBodyParam('required');
        $helpText = $request->getBodyParam('helpText', '');
        $config = json_decode($request->getBodyParam('config', '{}'), true) ?? [];

        $db = Craft::$app->getDb();
        $field = (new Query())->from('{{%simpleform_fields}}')->where(['id' => $fieldId])->one();
        if (!$field) {
            throw new NotFoundHttpException('Field not found');
        }

        $errors = $this->validateFieldInput($field['type'], $label, $handle, $config, (int)$field['formId'], (int)$fieldId);
        if (!empty($errors)) {
            return $this->asJson(['success' => false, 'errors' => $errors]);
        }

        $now = date('Y-m-d H:i:s');

        try {
            // Structural (shared) columns — updated once, no site filter.
            $db->createCommand()->update('{{%simpleform_fields}}', [
                'name' => $handle,
                'required' => $required,
                // Pass the array; Craft's json column encodes it once (avoid double-encoding).
                'config' => $config,
                'dateUpdated' => $now,
            ], ['id' => $fieldId])->execute();

            // Per-site (translatable) label/helpText — only for the current site.
            $db->createCommand()->upsert('{{%simpleform_fields_sites}}', [
                'fieldId' => $fieldId,
                'siteId' => $site->id,
                'label' => $label,
                'helpText' => $helpText ?: null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                'label' => $label,
                'helpText' => $helpText ?: null,
                'dateUpdated' => $now,
            ])->execute();

            return $this->asJson(['success' => true, 'message' => 'Field updated successfully']);
        } catch (\Exception $e) {
            Craft::warning('Error updating field: ' . $e->getMessage(), 'simple-form');
            return $this->asJson(['success' => false, 'error' => 'Failed to update field']);
        }
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $fieldId = $request->getRequiredBodyParam('fieldId');

        $db = Craft::$app->getDb();
        $field = (new Query())->from('{{%simpleform_fields}}')->where(['id' => $fieldId])->one();
        if (!$field) {
            throw new NotFoundHttpException('Field not found');
        }

        try {
            // _sites rows cascade via FK.
            $db->createCommand()->delete('{{%simpleform_fields}}', ['id' => $fieldId])->execute();
            return $this->asJson(['success' => true, 'message' => 'Field deleted successfully']);
        } catch (\Exception $e) {
            Craft::warning('Error deleting field: ' . $e->getMessage(), 'simple-form');
            return $this->asJson(['success' => false, 'error' => 'Failed to delete field']);
        }
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $fields = $request->getRequiredBodyParam('fields');
        if (!is_array($fields)) {
            return $this->asJson(['success' => false, 'error' => 'Fields parameter must be an array']);
        }

        $db = Craft::$app->getDb();

        try {
            foreach ($fields as $index => $field) {
                if (!isset($field['id'])) {
                    continue;
                }
                $db->createCommand()->update('{{%simpleform_fields}}', [
                    'sortOrder' => $index + 1,
                    'dateUpdated' => date('Y-m-d H:i:s'),
                ], ['id' => $field['id']])->execute();
            }

            return $this->asJson(['success' => true, 'message' => 'Fields reordered successfully']);
        } catch (\Exception $e) {
            Craft::warning('Error reordering fields: ' . $e->getMessage(), 'simple-form');
            return $this->asJson(['success' => false, 'error' => 'Failed to reorder fields']);
        }
    }

    /**
     * Shared input validation for add/edit. Returns an errors array (empty if valid).
     *
     * @return array<string,string[]>
     */
    private function validateFieldInput(string $type, ?string $label, ?string $handle, array $config, int $formId, ?int $excludeFieldId): array
    {
        $errors = [];

        if (empty($label)) {
            $errors['label'][] = 'Label is required';
        }

        if (empty($handle)) {
            $errors['handle'][] = 'Handle is required';
        } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
            $errors['handle'][] = 'Handle must start with a letter or underscore, and contain only alphanumeric characters and underscores';
        } else {
            $dupQuery = (new Query())
                ->from('{{%simpleform_fields}}')
                ->where(['formId' => $formId, 'name' => $handle]);
            if ($excludeFieldId !== null) {
                $dupQuery->andWhere(['not', ['id' => $excludeFieldId]]);
            }
            if ($dupQuery->exists()) {
                $errors['handle'][] = 'A field with this handle already exists in this form';
            }
        }

        $validTypes = ['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'date', 'number'];
        if (!in_array($type, $validTypes, true)) {
            $errors['type'][] = 'Invalid field type';
        }

        if (in_array($type, ['select', 'checkbox', 'radio'], true)) {
            if (empty($config['options']) || !is_array($config['options']) || count($config['options']) === 0) {
                $errors['config'][] = $type . ' fields must have at least one option';
            }
        }

        return $errors;
    }

    /**
     * Site IDs the field should exist on, derived from the parent form's propagation method.
     * Falls back to the current site if the form can't be loaded.
     *
     * @return int[]
     */
    private function supportedSiteIds(int $formId, int $currentSiteId): array
    {
        $form = Form::find()->id($formId)->siteId('*')->status(null)->one();
        if (!$form) {
            return [$currentSiteId];
        }

        $ids = [];
        foreach ($form->getSupportedSites() as $entry) {
            $ids[] = is_array($entry) ? (int)$entry['siteId'] : (int)$entry;
        }
        return $ids ?: [$currentSiteId];
    }
}
