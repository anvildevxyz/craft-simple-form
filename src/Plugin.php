<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlMutationsEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use fabianhaef\simpleform\events\SubmissionEvent;
use fabianhaef\simpleform\gql\mutations\FormMutations;
use fabianhaef\simpleform\gql\queries\FormQueries;
use fabianhaef\simpleform\gql\types\ConditionalRuleType;
use fabianhaef\simpleform\gql\types\FieldConditionalType;
use fabianhaef\simpleform\gql\types\FieldOptionType;
use fabianhaef\simpleform\gql\types\FieldValidationType;
use fabianhaef\simpleform\gql\types\FormFieldType;
use fabianhaef\simpleform\gql\types\FormIntegrationType;
use fabianhaef\simpleform\gql\types\FormType;
use fabianhaef\simpleform\gql\types\SubmissionErrorType;
use fabianhaef\simpleform\gql\types\SubmitFormPayloadType;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\mcp\TokenManager;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\services\AkismetService;
use fabianhaef\simpleform\services\AssetUploadService;
use fabianhaef\simpleform\services\CaptchaProviderRegistry;
use fabianhaef\simpleform\services\CaptchaService;
use fabianhaef\simpleform\services\EmailService;
use fabianhaef\simpleform\services\FieldTypeRegistry;
use fabianhaef\simpleform\services\FormStructureService;
use fabianhaef\simpleform\services\IntegrationsService;
use fabianhaef\simpleform\services\IntegrationTypeRegistry;
use fabianhaef\simpleform\services\SubmissionService;
use fabianhaef\simpleform\widgets\RecentSubmissionsWidget;
use fabianhaef\simpleform\widgets\SubmissionCountWidget;
use yii\base\Event;

/**
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EVENT_BEFORE_SUBMISSION_SAVE = 'beforeSubmissionSave';
    public const EVENT_AFTER_SUBMISSION_SAVE = 'afterSubmissionSave';

    /**
     * @event RegisterIntegrationTypesEvent Fired so third parties can register
     * outbound-integration connectors (see RegisterIntegrationTypesEvent).
     */
    public const EVENT_REGISTER_INTEGRATION_TYPES = 'registerIntegrationTypes';

    /**
     * @event RegisterCaptchaProvidersEvent Fired so third parties can register
     * captcha providers (see RegisterCaptchaProvidersEvent).
     */
    public const EVENT_REGISTER_CAPTCHA_PROVIDERS = 'registerCaptchaProviders';

    public string $schemaVersion = '2.5.0';
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
            'captchaProviderRegistry' => CaptchaProviderRegistry::class,
            'akismetService' => AkismetService::class,
            'assetUploadService' => AssetUploadService::class,
            'formStructure' => FormStructureService::class,
            'mcpTokenManager' => TokenManager::class,
            'integrationTypeRegistry' => IntegrationTypeRegistry::class,
            'integrations' => IntegrationsService::class,
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

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $e): void {
                $e->types[] = SubmissionCountWidget::class;
                $e->types[] = RecentSubmissionsWidget::class;
            }
        );

        Craft::$app->getUrlManager()->addRules([
            'simple-form/submit' => 'simple-form/submit/index',
            // MCP transport endpoint (token-authenticated machine API). Mapped
            // unconditionally; the controller itself enforces the off-by-default
            // toggle and bearer auth, so a disabled server still 404s cleanly.
            'simple-form/mcp' => 'simple-form/mcp/index',
        ]);

        $this->registerGraphQl();

        // Dispatch outbound integrations after a submission is saved. Queued by
        // default (see IntegrationsService) so third-party latency/outages never
        // block or fail the visitor's submission.
        $this->on(
            self::EVENT_AFTER_SUBMISSION_SAVE,
            function(SubmissionEvent $e): void {
                $this->getIntegrations()->dispatchForSubmission($e->submission);
            }
        );
    }

    /**
     * Register the plugin's GraphQL types, the form-schema queries, the
     * submitForm mutation, and the schema components (scopes) that gate them.
     */
    private function registerGraphQl(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function(RegisterGqlTypesEvent $event): void {
                array_push(
                    $event->types,
                    ConditionalRuleType::class,
                    FieldConditionalType::class,
                    FieldOptionType::class,
                    FieldValidationType::class,
                    FormFieldType::class,
                    FormIntegrationType::class,
                    FormType::class,
                    SubmissionErrorType::class,
                    SubmitFormPayloadType::class,
                );
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event): void {
                $event->queries = array_merge($event->queries, FormQueries::getQueries());
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            static function(RegisterGqlMutationsEvent $event): void {
                $event->mutations = array_merge($event->mutations, FormMutations::getMutations());
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            static function(RegisterGqlSchemaComponentsEvent $event): void {
                $label = Craft::t('simple-form', 'Simple Form');

                // Read the form schema (metadata + fields). Submission data is
                // never exposed, so there is intentionally no read scope for it.
                $event->queries[$label] = [
                    'simpleForms:read' => ['label' => Craft::t('simple-form', 'View form schemas')],
                ];

                // Create a submission via the submitForm mutation.
                $event->mutations[$label] = [
                    'simpleFormSubmissions:create' => ['label' => Craft::t('simple-form', 'Submit forms')],
                ];
            }
        );
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

    public function getCaptchaProviderRegistry(): CaptchaProviderRegistry
    {
        /** @var CaptchaProviderRegistry $registry */
        $registry = $this->get('captchaProviderRegistry');
        return $registry;
    }

    public function getAkismetService(): AkismetService
    {
        /** @var AkismetService $service */
        $service = $this->get('akismetService');
        return $service;
    }

    public function getAssetUploadService(): AssetUploadService
    {
        /** @var AssetUploadService $service */
        $service = $this->get('assetUploadService');
        return $service;
    }

    public function getEmailService(): EmailService
    {
        /** @var EmailService $service */
        $service = $this->get('emailService');
        return $service;
    }

    public function getSubmissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = $this->get('submissionService');
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

    public function getMcpTokenManager(): TokenManager
    {
        /** @var TokenManager $manager */
        $manager = $this->get('mcpTokenManager');
        return $manager;
    }

    public function getIntegrationTypeRegistry(): IntegrationTypeRegistry
    {
        /** @var IntegrationTypeRegistry $registry */
        $registry = $this->get('integrationTypeRegistry');
        return $registry;
    }

    public function getIntegrations(): IntegrationsService
    {
        /** @var IntegrationsService $service */
        $service = $this->get('integrations');
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
            $subnav['forms'] = ['label' => Craft::t('simple-form', 'Forms'), 'url' => 'simple-form/forms'];
        }
        if ($isAdmin || $user?->can(SimpleFormPermissions::VIEW_SUBMISSIONS)) {
            $subnav['submissions'] = ['label' => Craft::t('simple-form', 'Submissions'), 'url' => 'simple-form/submissions'];
        }
        if ($isAdmin || $user?->can(SimpleFormPermissions::MANAGE_SETTINGS)) {
            $subnav['settings'] = ['label' => Craft::t('simple-form', 'Settings'), 'url' => 'simple-form/settings'];
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
        // Integrations: global definitions managed under Settings, enabled per form.
        $event->rules['simple-form/integrations'] = 'simple-form/integrations/global-index';
        $event->rules['simple-form/integrations/save'] = 'simple-form/integrations/save';
        $event->rules['simple-form/integrations/delete'] = 'simple-form/integrations/delete';
        $event->rules['simple-form/integrations/toggle'] = 'simple-form/integrations/toggle';
        $event->rules['simple-form/integrations/toggle-form'] = 'simple-form/integrations/toggle-form';
        $event->rules['simple-form/integrations/resend'] = 'simple-form/integrations/resend';
        // Per-form: choose which global integrations are active on a form.
        $event->rules['simple-form/forms/<formId:\d+>/integrations'] = 'simple-form/integrations/index';

        $event->rules['simple-form/submissions'] = 'simple-form/submissions/index';
        $event->rules['simple-form/submissions/export'] = 'simple-form/submissions/export';
        $event->rules['simple-form/submissions/<submissionId:\d+>'] = 'simple-form/submissions/view';
        $event->rules['simple-form/submissions/toggle-status'] = 'simple-form/submissions/toggle-status';
        $event->rules['simple-form/settings'] = 'simple-form/settings/index';
        $event->rules['simple-form/settings/save'] = 'simple-form/settings/save';
        $event->rules['simple-form/settings/mcp/create-token'] = 'simple-form/settings/create-mcp-token';
        $event->rules['simple-form/settings/mcp/revoke-token'] = 'simple-form/settings/revoke-mcp-token';
        // Integrations management lives under Settings. Specific routes must
        // precede the generic settings/<tab> catch-all below.
        $event->rules['simple-form/settings/integrations'] = 'simple-form/integrations/settings-index';
        $event->rules['simple-form/settings/integrations/new'] = 'simple-form/integrations/edit';
        $event->rules['simple-form/settings/integrations/<integrationId:\d+>'] = 'simple-form/integrations/edit';
        $event->rules['simple-form/settings/<tab:\w+>'] = 'simple-form/settings/section';

        // Fields AJAX endpoints
        $event->rules['simple-form/fields/add'] = 'simple-form/fields/add';
        $event->rules['simple-form/fields/edit'] = 'simple-form/fields/edit';
        $event->rules['simple-form/fields/delete'] = 'simple-form/fields/delete';
        $event->rules['simple-form/fields/reorder'] = 'simple-form/fields/reorder';
    }
}
