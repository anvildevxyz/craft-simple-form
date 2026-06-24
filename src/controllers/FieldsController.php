<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\helpers\SiteHelper;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FieldTypeRegistry;
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
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteFromPost($request);

        $formId = $request->getRequiredBodyParam('formId');
        $type = $request->getRequiredBodyParam('type');
        $label = $request->getRequiredBodyParam('label');
        $handle = $request->getRequiredBodyParam('handle');
        $required = (bool)$request->getBodyParam('required');
        $helpText = $request->getBodyParam('helpText', '');
        $config = $this->decodeConfigParam($request);

        $errors = $this->validateFieldInput($type, $label, $handle, $config, (int)$formId, null);
        if (!empty($errors)) {
            return $this->asJsonErrors($errors);
        }

        try {
            $fieldId = Plugin::getInstance()->getFields()->add(
                (int)$formId,
                $type,
                $handle,
                $required,
                $config,
                $label,
                (string)$helpText,
                $this->supportedSiteIds((int)$formId, $site->id),
            );

            return $this->asJsonSuccess(['fieldId' => $fieldId]);
        } catch (\Exception $e) {
            Craft::warning('Error adding field: ' . $e->getMessage(), 'simple-form');
            return $this->asJsonError(Craft::t('simple-form', 'Failed to add field'));
        }
    }

    public function actionEdit(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteFromPost($request);

        $fieldId = $request->getRequiredBodyParam('fieldId');
        $label = $request->getBodyParam('label');
        $handle = $request->getBodyParam('handle');
        $required = (bool)$request->getBodyParam('required');
        $helpText = $request->getBodyParam('helpText', '');
        $config = $this->decodeConfigParam($request);

        // Only formId (cache invalidation) and the immutable type are needed.
        $field = (new Query())->select(['formId', 'type'])->from('{{%simpleform_fields}}')->where(['id' => $fieldId])->one();
        if (!$field) {
            throw new NotFoundHttpException('Field not found');
        }

        $errors = $this->validateFieldInput($field['type'], $label, $handle, $config, (int)$field['formId'], (int)$fieldId);
        if (!empty($errors)) {
            return $this->asJsonErrors($errors);
        }

        try {
            Plugin::getInstance()->getFields()->update(
                (int)$fieldId,
                (int)$field['formId'],
                (int)$site->id,
                (string)$handle,
                $required,
                $config,
                (string)$label,
                (string)$helpText,
            );

            return $this->asJsonSuccess();
        } catch (\Exception $e) {
            Craft::warning('Error updating field: ' . $e->getMessage(), 'simple-form');
            return $this->asJsonError(Craft::t('simple-form', 'Failed to update field'));
        }
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $fieldId = $request->getRequiredBodyParam('fieldId');

        // Only the formId is needed (existence check + cache invalidation).
        $formId = (new Query())->select(['formId'])->from('{{%simpleform_fields}}')->where(['id' => $fieldId])->scalar();
        if ($formId === false) {
            throw new NotFoundHttpException('Field not found');
        }

        try {
            Plugin::getInstance()->getFields()->delete((int)$fieldId, (int)$formId);
            return $this->asJsonSuccess();
        } catch (\Exception $e) {
            Craft::warning('Error deleting field: ' . $e->getMessage(), 'simple-form');
            return $this->asJsonError(Craft::t('simple-form', 'Failed to delete field'));
        }
    }

    /**
     * Decode the posted `config` JSON, bounding its size (F19, CWE-20) so a CP
     * user cannot store a multi-megabyte blob that bloats form-structure cache
     * rebuilds. Oversized or invalid input decodes to an empty config.
     *
     * @return array<string, mixed>
     */
    private function decodeConfigParam(\craft\web\Request $request): array
    {
        $raw = (string) $request->getBodyParam('config', '{}');
        if (strlen($raw) > 65536) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $fields = $request->getRequiredBodyParam('fields');
        if (!is_array($fields)) {
            return $this->asJsonError(Craft::t('simple-form', 'Fields parameter must be an array'));
        }

        $db = Craft::$app->getDb();

        try {
            $ordered = [];
            foreach ($fields as $index => $field) {
                if (isset($field['id'])) {
                    $ordered[(int) $field['id']] = $index + 1;
                }
            }
            $fieldIds = array_keys($ordered);

            // F18 (CWE-639): every submitted field must belong to the same form.
            // Reject mixed/unknown ids so a request can't silently reorder (and
            // corrupt) fields across forms it didn't intend to touch.
            $formIds = (new Query())
                ->select(['formId'])
                ->distinct()
                ->from('{{%simpleform_fields}}')
                ->where(['id' => $fieldIds])
                ->column();
            if ($fieldIds === [] || count($formIds) !== 1) {
                return $this->asJsonError(Craft::t('simple-form', 'All reordered fields must belong to a single form.'));
            }
            $formId = (int) $formIds[0];

            $transaction = $db->beginTransaction();
            try {
                foreach ($ordered as $id => $sortOrder) {
                    $db->createCommand()->update('{{%simpleform_fields}}', [
                        'sortOrder' => $sortOrder,
                        'dateUpdated' => date('Y-m-d H:i:s'),
                    ], ['id' => $id, 'formId' => $formId])->execute();
                }

                $transaction->commit();
            } catch (\Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }

            // Reorder changes the rendered field order, so invalidate the form's
            // cached structure.
            Plugin::getInstance()->getFormStructure()->invalidate($formId);

            return $this->asJsonSuccess();
        } catch (\Exception $e) {
            Craft::warning('Error reordering fields: ' . $e->getMessage(), 'simple-form');
            return $this->asJsonError(Craft::t('simple-form', 'Failed to reorder fields'));
        }
    }

    /**
     * Shared input validation for add/edit. Returns an errors array (empty if valid).
     *
     * @param array<string, mixed> $config
     * @return array<string,string[]>
     */
    private function validateFieldInput(string $type, ?string $label, ?string $handle, array $config, int $formId, ?int $excludeFieldId): array
    {
        $errors = [];

        if (empty($label)) {
            $errors['label'][] = Craft::t('simple-form', 'Label is required');
        }

        if (empty($handle)) {
            $errors['handle'][] = Craft::t('simple-form', 'Handle is required');
        } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
            $errors['handle'][] = Craft::t('simple-form', 'Handle must start with a letter or underscore, and contain only alphanumeric characters and underscores');
        } else {
            $dupQuery = (new Query())
                ->from('{{%simpleform_fields}}')
                ->where(['formId' => $formId, 'name' => $handle]);
            if ($excludeFieldId !== null) {
                $dupQuery->andWhere(['not', ['id' => $excludeFieldId]]);
            }
            if ($dupQuery->exists()) {
                $errors['handle'][] = Craft::t('simple-form', 'A field with this handle already exists in this form');
            }
        }

        if (!in_array($type, Plugin::getInstance()->getFieldTypeRegistry()->typeHandles(), true)) {
            $errors['type'][] = Craft::t('simple-form', 'Invalid field type');
        }

        if (in_array($type, FieldTypeRegistry::OPTION_TYPES, true)
            && (empty($config['options']) || !is_array($config['options']))) {
            $errors['config'][] = Craft::t('simple-form', '{type} fields must have at least one option', ['type' => $type]);
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

        return $form ? ($form->supportedSiteIds() ?: [$currentSiteId]) : [$currentSiteId];
    }
}
