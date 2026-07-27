<?php

namespace anvildev\simpleform\console\controllers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * `simple-form/cache/*` — warm or clear the cached form structure (#106).
 */
class CacheController extends Controller
{
    /**
     * Pre-build the form-structure cache for every form (all sites).
     */
    public function actionWarm(): int
    {
        $service = Plugin::getInstance()->getFormStructure();
        $forms = 0;
        $sets = 0;
        foreach (Form::find()->siteId('*')->ids() as $formId) {
            $sets += $service->warm((int) $formId);
            $forms++;
        }

        $this->stdout("Warmed $sets field set(s) across $forms form(s).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Invalidate the cached structure for every form.
     */
    public function actionClear(): int
    {
        $service = Plugin::getInstance()->getFormStructure();
        $forms = 0;
        foreach (Form::find()->siteId('*')->ids() as $formId) {
            $service->invalidate((int) $formId);
            $forms++;
        }

        $this->stdout("Cleared cached structure for $forms form(s).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
