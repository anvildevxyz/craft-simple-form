<?php

namespace fabianhaef\simpleform\controllers;

use Yii;
use yii\web\Response;

trait SimpleFormControllerTrait
{
    public function beforeAction($action): bool
    {
        // Get permission from child class. Each controller must define: protected const PERMISSION = '...';
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
