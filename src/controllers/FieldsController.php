<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\helpers\SiteHelper;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\web\Controller;
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

        // Edition gate (authoritative): adding a field is always a new escalation,
        // so Solo may not add a Standard field type here — the same rule the form-save
        // and MCP authoring paths enforce, which this single-field CP route would
        // otherwise bypass.
        if (!Editions::fieldTypeAllowed((string)$type)) {
            return $this->asJsonError(Craft::t('simple-form', 'The “{type}” field type requires the Standard edition.', ['type' => $type]));
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

            // Delegate the transactional sortOrder writes + cache invalidation to
            // the shared FieldsService. Pinning $formId keeps the single-form
            // guard above authoritative: a stray id can't be reordered elsewhere.
            Plugin::getInstance()->getFields()->reorder($fieldIds, $formId);

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
        $structured = Plugin::getInstance()->getFields()->validateInput($type, $label, $handle, $config, $formId, $excludeFieldId);

        // Render each shared rule's {key, params} through Craft::t for the CP.
        $errors = [];
        foreach ($structured as $input => $list) {
            foreach ($list as $error) {
                $errors[$input][] = Craft::t('simple-form', $error['key'], $error['params']);
            }
        }

        return $errors;
    }

    /**
     * Site IDs the field should exist on, derived from the parent form's
     * propagation method, falling back to the current site. Delegates to the
     * shared {@see \anvildev\simpleform\services\FieldsService::supportedSiteIds()}.
     *
     * @return int[]
     */
    private function supportedSiteIds(int $formId, int $currentSiteId): array
    {
        return Plugin::getInstance()->getFields()->supportedSiteIds($formId, $currentSiteId);
    }
}
