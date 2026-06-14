<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\base\Plugin as BasePlugin;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public function init(): void
    {
        parent::init();

        Craft::$app->getElements()->registerElementType(Form::class);
        Craft::$app->getElements()->registerElementType(Submission::class);

        $this->registerCpRoutes();
    }

    public function getName(): string
    {
        return Craft::t('simple-form', 'Simple Form');
    }

    private function registerCpRoutes(): void
    {
        Craft::$app->getUrlManager()->addRules([
            'simple-form/forms' => 'simple-form/forms/index',
            'simple-form/forms/edit/<formId:\d+>' => 'simple-form/forms/edit',
            'simple-form/forms/save' => 'simple-form/forms/save',
            'simple-form/forms/delete/<formId:\d+>' => 'simple-form/forms/delete',
            'simple-form/submissions' => 'simple-form/submissions/index',
            'simple-form/submissions/<submissionId:\d+>' => 'simple-form/submissions/view',
        ]);
    }
}
