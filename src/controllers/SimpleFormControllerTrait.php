<?php

namespace fabianhaef\simpleform\controllers;

use Yii;
use yii\base\Action;

trait SimpleFormControllerTrait
{
    protected const PERMISSION = '';

    public function beforeAction($action): bool
    {
        if (static::PERMISSION && !Yii::$app->getUser()->getIdentity()?->admin) {
            $this->requirePermission(static::PERMISSION);
        }

        return parent::beforeAction($action);
    }
}
