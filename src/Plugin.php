<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use craft\web\twig\variables\Cp;
use fabianhaef\simpleform\services\EmailService;
use fabianhaef\simpleform\services\FieldTypeRegistry;
use fabianhaef\simpleform\services\SubmissionService;

class Plugin extends BasePlugin
{
    public const EVENT_BEFORE_SUBMISSION_SAVE = 'beforeSubmissionSave';
    public const EVENT_AFTER_SUBMISSION_SAVE = 'afterSubmissionSave';

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
            'submissionService' => SubmissionService::class,
        ]);

        Craft::$app->getI18n()->translations['simple-form'] ??= [
            'class' => 'yii\i18n\PhpMessageSource',
            'sourceLanguage' => 'en-US',
            'basePath' => '@fabianhaef/simpleform/translations',
            'forceTranslation' => true,
        ];

        // Element types are auto-registered in Craft 5

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->getView()->registerTwigExtension(new TwigExtension());
        }

        Craft::$app->getUrlManager()->on(
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            [$this, 'registerCpUrlRules']
        );

        Craft::$app->getView()->on(
            Cp::EVENT_REGISTER_CP_NAV_ITEMS,
            [$this, 'registerCpNavItems']
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

    public function registerCpNavItems(RegisterCpNavItemsEvent $event): void
    {
        $event->navItems[] = [
            'url' => 'simple-form',
            'label' => $this->getName(),
            'icon' => '@fabianhaef/simpleform/icon.svg',
            'subnav' => [
                'forms' => [
                    'label' => 'Forms',
                    'url' => 'simple-form/forms',
                ],
                'submissions' => [
                    'label' => 'Submissions',
                    'url' => 'simple-form/submissions',
                ],
                'settings' => [
                    'label' => 'Settings',
                    'url' => 'simple-form/settings',
                ],
            ],
        ];
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
        $event->rules['simple-form/settings'] = 'simple-form/settings/index';
        $event->rules['simple-form/settings/save'] = 'simple-form/settings/save';

        // Fields AJAX endpoints
        $event->rules['simple-form/fields/add'] = 'simple-form/fields/add';
        $event->rules['simple-form/fields/edit'] = 'simple-form/fields/edit';
        $event->rules['simple-form/fields/delete'] = 'simple-form/fields/delete';
        $event->rules['simple-form/fields/reorder'] = 'simple-form/fields/reorder';
    }

}
