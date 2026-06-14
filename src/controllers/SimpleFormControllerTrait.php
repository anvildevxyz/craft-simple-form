<?php

namespace fabianhaef\simpleform\controllers;

use Yii;

trait SimpleFormControllerTrait
{
    public function beforeAction($action): bool
    {
        // Get permission from child class. Each controller must define: protected const PERMISSION = '...';
        try {
            $permission = static::PERMISSION;
        } catch (\Error $e) {
            $permission = '';
        }

        if ($permission && !Yii::$app->getUser()->getIdentity()?->admin) {
            $this->requirePermission($permission);
        }

        return parent::beforeAction($action);
    }
}
