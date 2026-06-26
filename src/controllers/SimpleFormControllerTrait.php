<?php

namespace fabianhaef\simpleform\controllers;

use fabianhaef\simpleform\elements\Form;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;

trait SimpleFormControllerTrait
{
    /**
     * Resolve a form by id across all sites (status-agnostic), or throw a 404.
     * Uses `siteId('*')` so a form is still found from a non-primary site.
     */
    protected function getFormOrFail(int $formId): Form
    {
        $form = Form::find()->siteId('*')->id($formId)->status(null)->one();
        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }
        return $form;
    }

    public function beforeAction($action): bool
    {
        // Each controller must define: protected const PERMISSION = '...';
        $permission = defined('static::PERMISSION') ? static::PERMISSION : '';

        if ($permission && !Yii::$app->getUser()->getIdentity()?->admin) {
            $this->requirePermission($permission);
        }

        return parent::beforeAction($action);
    }

    /**
     * Standard AJAX JSON envelope shared by every CP controller action:
     *
     *     { "success": bool, "error"?: string, "errors"?: { field: string[] } }
     *
     * - Success responses carry `success: true` plus any domain data.
     * - Failures report EITHER a single human-readable `error` OR a
     *   field-keyed `errors` validation map — never both, never neither.
     *   Callers read `error` first, then fall back to `errors`.
     *
     * @param array<string, mixed> $data extra success payload merged alongside `success: true`
     */
    protected function asJsonSuccess(array $data = []): Response
    {
        return $this->asJson(['success' => true] + $data);
    }

    protected function asJsonError(string $error): Response
    {
        return $this->asJson(['success' => false, 'error' => $error]);
    }

    /**
     * @param array<string, list<string>> $errors field-keyed validation messages
     */
    protected function asJsonErrors(array $errors): Response
    {
        return $this->asJson(['success' => false, 'errors' => $errors]);
    }
}
