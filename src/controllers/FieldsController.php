<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use yii\web\BadRequestHttpException;
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

        $formId = $request->getRequiredBodyParam('formId');
        $type = $request->getRequiredBodyParam('type');
        $label = $request->getRequiredBodyParam('label');
        $handle = $request->getRequiredBodyParam('handle');
        $required = (bool) $request->getBodyParam('required');
        $helpText = $request->getBodyParam('helpText', '');
        $config = json_decode($request->getBodyParam('config', '{}'), true) ?? [];

        // Validate inputs
        $errors = [];

        if (empty($label)) {
            $errors['label'][] = 'Label is required';
        }

        if (empty($handle)) {
            $errors['handle'][] = 'Handle is required';
        } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
            $errors['handle'][] = 'Handle must start with a letter or underscore, and contain only alphanumeric characters and underscores';
        } else {
            // Check for duplicate handle within this form
            $db = Craft::$app->getDb();
            $existingHandle = $db->createCommand(
                'SELECT id FROM {{%simpleform_fields}} WHERE formId = :formId AND name = :handle',
                [':formId' => $formId, ':handle' => $handle]
            )->queryScalar();

            if ($existingHandle) {
                $errors['handle'][] = 'A field with this handle already exists in this form';
            }
        }

        $validTypes = ['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'date', 'number'];
        if (!in_array($type, $validTypes)) {
            $errors['type'][] = 'Invalid field type';
        }

        // Type-specific validation
        if (in_array($type, ['select', 'checkbox', 'radio'])) {
            if (empty($config['options']) || !is_array($config['options']) || count($config['options']) === 0) {
                $errors['config'][] = $type . ' fields must have at least one option';
            }
        }

        if (!empty($errors)) {
            return $this->asJson([
                'success' => false,
                'errors' => $errors,
            ]);
        }

        // Get max sortOrder for this form
        $db = Craft::$app->getDb();
        $maxSort = $db->createCommand(
            'SELECT MAX(sortOrder) FROM {{%simpleform_fields}} WHERE formId = :formId',
            [':formId' => $formId]
        )->queryScalar() ?? 0;

        // Insert field
        try {
            $db->createCommand()->insert('simpleform_fields', [
                'formId' => $formId,
                'type' => $type,
                'name' => $handle,
                'label' => $label,
                'helpText' => $helpText ?: null,
                'config' => json_encode($config),
                'sortOrder' => $maxSort + 1,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => Craft::$app->getSecurity()->generateRandomString(36),
            ])->execute();

            $fieldId = $db->getLastInsertID();

            return $this->asJson([
                'success' => true,
                'fieldId' => $fieldId,
                'message' => 'Field added successfully',
            ]);
        } catch (\Exception $e) {
            Craft::warning('Error adding field: ' . $e->getMessage(), 'simple-form');
            return $this->asJson([
                'success' => false,
                'error' => 'Failed to add field',
            ]);
        }
    }

    public function actionEdit(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $fieldId = $request->getRequiredBodyParam('fieldId');
        $label = $request->getBodyParam('label');
        $handle = $request->getBodyParam('handle');
        $required = (bool) $request->getBodyParam('required');
        $helpText = $request->getBodyParam('helpText', '');
        $config = json_decode($request->getBodyParam('config', '{}'), true) ?? [];

        // Get field to verify it exists
        $db = Craft::$app->getDb();
        $field = $db->createCommand(
            'SELECT * FROM {{%simpleform_fields}} WHERE id = :id',
            [':id' => $fieldId]
        )->queryOne();

        if (!$field) {
            throw new NotFoundHttpException('Field not found');
        }

        // Validate inputs
        $errors = [];

        if (empty($label)) {
            $errors['label'][] = 'Label is required';
        }

        if (empty($handle)) {
            $errors['handle'][] = 'Handle is required';
        } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
            $errors['handle'][] = 'Handle must start with a letter or underscore, and contain only alphanumeric characters and underscores';
        } else {
            // Check for duplicate handle within this form (excluding current field)
            $existingHandle = $db->createCommand(
                'SELECT id FROM {{%simpleform_fields}} WHERE formId = :formId AND name = :handle AND id != :fieldId',
                [':formId' => $field['formId'], ':handle' => $handle, ':fieldId' => $fieldId]
            )->queryScalar();

            if ($existingHandle) {
                $errors['handle'][] = 'A field with this handle already exists in this form';
            }
        }

        // Type-specific validation
        if (in_array($field['type'], ['select', 'checkbox', 'radio'])) {
            if (empty($config['options']) || !is_array($config['options']) || count($config['options']) === 0) {
                $errors['config'][] = $field['type'] . ' fields must have at least one option';
            }
        }

        if (!empty($errors)) {
            return $this->asJson([
                'success' => false,
                'errors' => $errors,
            ]);
        }

        // Update field
        try {
            $db->createCommand()->update('simpleform_fields', [
                'label' => $label,
                'name' => $handle,
                'helpText' => $helpText ?: null,
                'config' => json_encode($config),
                'dateUpdated' => date('Y-m-d H:i:s'),
            ], ['id' => $fieldId])->execute();

            return $this->asJson([
                'success' => true,
                'message' => 'Field updated successfully',
            ]);
        } catch (\Exception $e) {
            Craft::warning('Error updating field: ' . $e->getMessage(), 'simple-form');
            return $this->asJson([
                'success' => false,
                'error' => 'Failed to update field',
            ]);
        }
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $fieldId = $request->getRequiredBodyParam('fieldId');

        $db = Craft::$app->getDb();
        $field = $db->createCommand(
            'SELECT * FROM {{%simpleform_fields}} WHERE id = :id',
            [':id' => $fieldId]
        )->queryOne();

        if (!$field) {
            throw new NotFoundHttpException('Field not found');
        }

        try {
            $db->createCommand()->delete('simpleform_fields', ['id' => $fieldId])->execute();

            return $this->asJson([
                'success' => true,
                'message' => 'Field deleted successfully',
            ]);
        } catch (\Exception $e) {
            Craft::warning('Error deleting field: ' . $e->getMessage(), 'simple-form');
            return $this->asJson([
                'success' => false,
                'error' => 'Failed to delete field',
            ]);
        }
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $fields = $request->getRequiredBodyParam('fields');
        if (!is_array($fields)) {
            return $this->asJson([
                'success' => false,
                'error' => 'Fields parameter must be an array',
            ]);
        }

        $db = Craft::$app->getDb();

        try {
            foreach ($fields as $index => $field) {
                if (!isset($field['id'])) {
                    continue;
                }

                $db->createCommand()->update('simpleform_fields', [
                    'sortOrder' => $index + 1,
                    'dateUpdated' => date('Y-m-d H:i:s'),
                ], ['id' => $field['id']])->execute();
            }

            return $this->asJson([
                'success' => true,
                'message' => 'Fields reordered successfully',
            ]);
        } catch (\Exception $e) {
            Craft::warning('Error reordering fields: ' . $e->getMessage(), 'simple-form');
            return $this->asJson([
                'success' => false,
                'error' => 'Failed to reorder fields',
            ]);
        }
    }
}
