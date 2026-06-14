<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\services\EmailService;
use fabianhaef\simpleform\services\FieldTypeRegistry;

class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public static function getInstance(): Plugin
    {
        return parent::getInstance();
    }

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'fieldTypeRegistry' => FieldTypeRegistry::class,
            'emailService' => EmailService::class,
        ]);

        Craft::$app->getI18n()->translations['simple-form'] ??= [
            'class' => 'yii\i18n\PhpMessageSource',
            'sourceLanguage' => 'en-US',
            'basePath' => '@fabianhaef/simpleform/translations',
            'forceTranslation' => true,
        ];

        Craft::$app->getElements()->registerElementType(Form::class);
        Craft::$app->getElements()->registerElementType(Submission::class);

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->getView()->registerTwigExtension(new TwigExtension());
        }

        Craft::$app->getUrlManager()->on(
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            [$this, 'registerCpUrlRules']
        );

        Craft::$app->getUrlManager()->addRules([
            'simple-form/submit' => 'simple-form/submit/index',
        ]);
    }

    public function getFieldTypeRegistry(): FieldTypeRegistry
    {
        return $this->get('fieldTypeRegistry');
    }

    public function getName(): string
    {
        return Craft::t('simple-form', 'Simple Form');
    }

    public function registerCpUrlRules(RegisterUrlRulesEvent $event): void
    {
        $event->rules['simple-form'] = 'simple-form/forms/index';
        $event->rules['simple-form/forms'] = 'simple-form/forms/index';
        $event->rules['simple-form/forms/new'] = 'simple-form/forms/edit';
        $event->rules['simple-form/forms/edit/<formId:\d+>'] = 'simple-form/forms/edit';
        $event->rules['simple-form/forms/save'] = 'simple-form/forms/save';
        $event->rules['simple-form/forms/delete/<formId:\d+>'] = 'simple-form/forms/delete';
        $event->rules['simple-form/submissions'] = 'simple-form/submissions/index';
        $event->rules['simple-form/submissions/<submissionId:\d+>'] = 'simple-form/submissions/view';
        $event->rules['simple-form/submissions/toggle-status'] = 'simple-form/submissions/toggle-status';
    }

}
