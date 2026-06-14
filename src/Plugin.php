<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\services\CaptchaService;
use fabianhaef\simpleform\services\EmailService;
use fabianhaef\simpleform\services\FieldTypeRegistry;
use fabianhaef\simpleform\services\FormStructureService;
use fabianhaef\simpleform\services\SubmissionService;
use yii\base\Event;

/**
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EVENT_BEFORE_SUBMISSION_SAVE = 'beforeSubmissionSave';
    public const EVENT_AFTER_SUBMISSION_SAVE = 'afterSubmissionSave';

    public string $schemaVersion = '2.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;
    public bool $hasCpPermissions = true;

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
            'captchaService' => CaptchaService::class,
            'formStructure' => FormStructureService::class,
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

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $e) {
                $e->permissions[] = SimpleFormPermissions::definitions();
            }
        );

        Craft::$app->getUrlManager()->addRules([
            'simple-form/submit' => 'simple-form/submit/index',
        ]);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getCaptchaService(): CaptchaService
    {
        /** @var CaptchaService $service */
        $service = $this->get('captchaService');
        return $service;
    }

    public function getFieldTypeRegistry(): FieldTypeRegistry
    {
        /** @var FieldTypeRegistry $registry */
        $registry = $this->get('fieldTypeRegistry');
        return $registry;
    }

    public function getFormStructure(): FormStructureService
    {
        /** @var FormStructureService $service */
        $service = $this->get('formStructure');
        return $service;
    }

    public function getName(): string
    {
        return Craft::t('simple-form', 'Simple Form');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser()->getIdentity();
        $isAdmin = $user?->admin;
        $subnav = [];

        if ($isAdmin || $user?->can(SimpleFormPermissions::MANAGE_FORMS)) {
            $subnav['forms'] = ['label' => 'Forms', 'url' => 'simple-form/forms'];
        }
        if ($isAdmin || $user?->can(SimpleFormPermissions::VIEW_SUBMISSIONS)) {
            $subnav['submissions'] = ['label' => 'Submissions', 'url' => 'simple-form/submissions'];
        }
        if ($isAdmin || $user?->can(SimpleFormPermissions::MANAGE_SETTINGS)) {
            $subnav['settings'] = ['label' => 'Settings', 'url' => 'simple-form/settings'];
        }

        if (empty($subnav)) {
            return null;
        }

        $item['subnav'] = $subnav;
        return $item;
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
        $event->rules['simple-form/settings/<tab:\w+>'] = 'simple-form/settings/section';

        // Fields AJAX endpoints
        $event->rules['simple-form/fields/add'] = 'simple-form/fields/add';
        $event->rules['simple-form/fields/edit'] = 'simple-form/fields/edit';
        $event->rules['simple-form/fields/delete'] = 'simple-form/fields/delete';
        $event->rules['simple-form/fields/reorder'] = 'simple-form/fields/reorder';
    }
}
