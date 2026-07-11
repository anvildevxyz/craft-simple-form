<?php

namespace anvildev\simpleform;

use anvildev\simpleform\events\SubmissionEvent;
use anvildev\simpleform\fields\FormField;
use anvildev\simpleform\gql\mutations\FormMutations;
use anvildev\simpleform\gql\queries\FormQueries;
use anvildev\simpleform\gql\types\ConditionalRuleType;
use anvildev\simpleform\gql\types\FieldConditionalType;
use anvildev\simpleform\gql\types\FieldOptionType;
use anvildev\simpleform\gql\types\FieldValidationType;
use anvildev\simpleform\gql\types\FormFieldType;
use anvildev\simpleform\gql\types\FormIntegrationType;
use anvildev\simpleform\gql\types\FormType;
use anvildev\simpleform\gql\types\SubmissionErrorType;
use anvildev\simpleform\gql\types\SubmitFormPayloadType;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\mcp\TokenManager;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\services\AkismetService;
use anvildev\simpleform\services\AssetUploadService;
use anvildev\simpleform\services\AuditService;
use anvildev\simpleform\services\CaptchaProviderRegistry;
use anvildev\simpleform\services\CaptchaService;
use anvildev\simpleform\services\CouponsService;
use anvildev\simpleform\services\DenylistService;
use anvildev\simpleform\services\DraftService;
use anvildev\simpleform\services\EmailService;
use anvildev\simpleform\services\FieldsService;
use anvildev\simpleform\services\FieldSyncService;
use anvildev\simpleform\services\FieldTypeRegistry;
use anvildev\simpleform\services\FormCloneService;
use anvildev\simpleform\services\FormPortabilityService;
use anvildev\simpleform\services\FormRenderService;
use anvildev\simpleform\services\FormStructureService;
use anvildev\simpleform\services\IntegrationsService;
use anvildev\simpleform\services\IntegrationTypeRegistry;
use anvildev\simpleform\services\NotificationLogService;
use anvildev\simpleform\services\NotificationsService;
use anvildev\simpleform\services\PaymentsService;
use anvildev\simpleform\services\PdfService;
use anvildev\simpleform\services\QuizScoringService;
use anvildev\simpleform\services\ReportsService;
use anvildev\simpleform\services\RetentionService;
use anvildev\simpleform\services\SafeRenderService;
use anvildev\simpleform\services\SubmissionBodyRenderer;
use anvildev\simpleform\services\SubmissionEditTokenService;
use anvildev\simpleform\services\SubmissionService;
use anvildev\simpleform\services\SubmitMessagesService;
use anvildev\simpleform\services\WorkflowService;
use anvildev\simpleform\stencils\StencilLibrary;
use anvildev\simpleform\web\twig\variables\SimpleFormVariable;
use anvildev\simpleform\widgets\RecentSubmissionsWidget;
use anvildev\simpleform\widgets\SubmissionCountWidget;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlMutationsEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\App;
use craft\services\Dashboard;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EVENT_BEFORE_SUBMISSION_SAVE = 'beforeSubmissionSave';
    public const EVENT_AFTER_SUBMISSION_SAVE = 'afterSubmissionSave';

    /**
     * @event WorkflowTransitionEvent Fired after a submission moves between
     * workflow stages (#248), so handlers can send notifications or dispatch
     * integrations on a transition (see WorkflowTransitionEvent).
     */
    public const EVENT_SUBMISSION_TRANSITIONED = 'submissionTransitioned';

    /**
     * Fired after a passive partial is captured (#244). Carries the captured
     * context ({@see \anvildev\simpleform\events\PartialCaptureEvent}) so
     * integrators can build abandonment follow-up; the plugin sends nothing.
     */
    public const EVENT_PARTIAL_CAPTURED = 'partialCaptured';

    /**
     * @event BeforeValidateSubmissionEvent Fired after the submitted values are
     * resolved but before any field is validated, so a handler can normalize or
     * augment the values (see BeforeValidateSubmissionEvent).
     */
    public const EVENT_BEFORE_VALIDATE = 'beforeValidate';

    /**
     * @event DefineFieldSetEvent Fired so a handler can add/remove/reorder the
     * resolved field rows for a form + site (see DefineFieldSetEvent).
     */
    public const EVENT_DEFINE_FIELD_SET = 'defineFieldSet';

    /**
     * @event ModifyRenderContextEvent Fired so a handler can adjust the Twig
     * render context before a form is rendered (see ModifyRenderContextEvent).
     */
    public const EVENT_MODIFY_RENDER_CONTEXT = 'modifyRenderContext';

    /**
     * @event BeforeSendNotificationEvent Fired before each notification email is
     * sent, so a handler can rewrite recipients or suppress it (see
     * BeforeSendNotificationEvent).
     */
    public const EVENT_BEFORE_SEND_NOTIFICATION = 'beforeSendNotification';

    /**
     * @event BeforeIntegrationDispatchEvent Fired before a single integration
     * dispatch, so a handler can adjust settings or skip it (see
     * BeforeIntegrationDispatchEvent).
     */
    public const EVENT_BEFORE_INTEGRATION_DISPATCH = 'beforeIntegrationDispatch';

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

    /**
     * @event RegisterStencilsEvent Fired so third parties can contribute form
     * stencils (see RegisterStencilsEvent).
     */
    public const EVENT_REGISTER_STENCILS = 'registerStencils';

    /**
     * @event RegisterFieldTypesEvent Fired so third parties can register custom
     * field types (see RegisterFieldTypesEvent).
     */
    public const EVENT_REGISTER_FIELD_TYPES = 'registerFieldTypes';

    /** The lightweight "better contact form" edition. */
    public const EDITION_SOLO = Editions::SOLO;

    /** The full-featured edition. */
    public const EDITION_PRO = Editions::PRO;

    public string $schemaVersion = '2.15.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;
    public bool $hasCpPermissions = true;

    /**
     * @return array<int, string>
     */
    public static function editions(): array
    {
        // Order matters: lowest tier first. `is()` compares by index.
        return [
            self::EDITION_SOLO,
            self::EDITION_PRO,
        ];
    }

    public static function getInstance(): Plugin
    {
        return parent::getInstance();
    }

    public function init(): void
    {
        parent::init();

        $this->_registerLegacyClassAliases();

        $this->setComponents([
            'fieldTypeRegistry' => FieldTypeRegistry::class,
            'safeRender' => SafeRenderService::class,
            'submissionBodyRenderer' => SubmissionBodyRenderer::class,
            'emailService' => EmailService::class,
            'submissionService' => SubmissionService::class,
            'submissionEditTokens' => SubmissionEditTokenService::class,
            'drafts' => DraftService::class,
            'captchaService' => CaptchaService::class,
            'captchaProviderRegistry' => CaptchaProviderRegistry::class,
            'akismetService' => AkismetService::class,
            'denylistService' => DenylistService::class,
            'assetUploadService' => AssetUploadService::class,
            'formStructure' => FormStructureService::class,
            'formRender' => FormRenderService::class,
            'formClone' => FormCloneService::class,
            'stencilLibrary' => StencilLibrary::class,
            'mcpTokenManager' => TokenManager::class,
            'integrationTypeRegistry' => IntegrationTypeRegistry::class,
            'integrations' => IntegrationsService::class,
            'retention' => RetentionService::class,
            'reports' => ReportsService::class,
            'quizScoring' => QuizScoringService::class,
            'coupons' => CouponsService::class,
            'workflow' => WorkflowService::class,
            'notifications' => NotificationsService::class,
            'submitMessages' => SubmitMessagesService::class,
            'audit' => AuditService::class,
            'notificationLog' => NotificationLogService::class,
            'payments' => PaymentsService::class,
            'fields' => FieldsService::class,
            'fieldSync' => FieldSyncService::class,
            'portability' => FormPortabilityService::class,
            'pdf' => PdfService::class,
        ]);

        Craft::$app->getI18n()->translations['simple-form'] ??= [
            'class' => 'yii\i18n\PhpMessageSource',
            'sourceLanguage' => 'en-US',
            'basePath' => '@anvildev/simpleform/translations',
            'forceTranslation' => true,
        ];

        // Element types are auto-registered in Craft 5

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->getView()->registerTwigExtension(new TwigExtension());
        }

        // Edit/resume links carry bearer tokens in the query string (#211, CWE-598).
        // Strip Referer on those pages so the token is not leaked to third parties.
        Event::on(
            Response::class,
            Response::EVENT_BEFORE_SEND,
            static function(): void {
                /** @var \craft\web\Request $request */
                $request = Craft::$app->getRequest();
                if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
                    return;
                }
                if ($request->getQueryParam('t') !== null || $request->getQueryParam('sfresume') !== null) {
                    /** @var \craft\web\Response $response */
                    $response = Craft::$app->getResponse();
                    $response->getHeaders()->set('Referrer-Policy', 'no-referrer');
                }
            },
        );

        // Register the plugin's built-in form partials as a SITE template root so
        // the front-end render path can address them (e.g. `simple-form/form`) and
        // so a site theme's own `templates/<path>/*.twig` overrides win first (#137).
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $event): void {
                $event->roots['simple-form'] = __DIR__ . '/templates/_form';
            }
        );

        // craft.simpleForm.* template API (complements the simpleForm() function).
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $e): void {
                /** @var CraftVariable $variable */
                $variable = $e->sender;
                $variable->set('simpleForm', SimpleFormVariable::class);
            }
        );

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

        // A custom field that embeds a form in any element's field layout.
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $e): void {
                $e->types[] = FormField::class;
            }
        );

        Craft::$app->getUrlManager()->addRules([
            'simple-form/submit' => 'simple-form/submit/index',
            // Coupon validation (#246): a public AJAX endpoint the front-end
            // payment field calls to preview a discount before submit.
            'simple-form/coupons/validate' => 'simple-form/submit/coupon-validate',
            // Embed & share (#247): the standalone shareable form page (also the
            // iframe target for the embed modes) and the embed loader script.
            'simple-form/embed.js' => 'simple-form/form/embed-script',
            'simple-form/form/<handle:[a-zA-Z][\w\-]*>' => 'simple-form/form/standalone',
            // Front-end submission editing (#144): the public update transport.
            // Authorization (token/owner + window + allowEditing) is enforced in
            // the controller; an unauthorized request 403s cleanly.
            'simple-form/submission-edit/update' => 'simple-form/submission-edit/update',
            // MCP transport endpoint (token-authenticated machine API). Mapped
            // unconditionally; the controller itself enforces the off-by-default
            // toggle and bearer auth, so a disabled server still 404s cleanly.
            'simple-form/mcp' => 'simple-form/mcp/index',
        ]);

        // Data-retention housekeeping: prune aged submissions + integration logs
        // on Craft's garbage-collection run (opt-in via settings; 0 = keep forever).
        // Also reconcile payment state: cancel submissions whose payment stayed
        // pending past the TTL (abandoned offsite/redirect checkouts, #116).
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            function(): void {
                $this->getRetention()->runGarbageCollection();
                $this->getPayments()->expirePending();
            }
        );

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

        // When a linked Commerce order completes, mark the submission paid and
        // release its withheld notifications/integrations. Soft dependency: only
        // wired when Commerce is installed.
        if (class_exists(\craft\commerce\elements\Order::class)) {
            Event::on(
                \craft\commerce\elements\Order::class,
                \craft\commerce\elements\Order::EVENT_AFTER_COMPLETE_ORDER,
                function(Event $e): void {
                    /** @var \craft\commerce\elements\Order $order */
                    $order = $e->sender;
                    $this->getPayments()->handleOrderCompleted((int) $order->id);
                }
            );
        }

        // Optionally deploy code-defined forms after `craft up` (#226 follow-up).
        // Off by default; the handler only acts on the `up` index action with the
        // setting on (UpController runs only in console, so this is console-only in
        // practice). Registered unconditionally so it is testable.
        Event::on(
            \craft\console\controllers\UpController::class,
            \craft\console\Controller::EVENT_AFTER_ACTION,
            function(\yii\base\ActionEvent $e): void {
                if ($e->action->id !== 'index' || !$this->getSettings()->applyFormsConfigOnUp) {
                    return;
                }
                try {
                    // Never prunes on the automatic run (safe by default).
                    (new \anvildev\simpleform\console\controllers\FormsController('forms', Craft::$app))->actionApply();
                } catch (\Throwable $ex) {
                    Craft::error('forms/apply after `up` failed: ' . $ex->getMessage(), 'simple-form');
                }
            }
        );
    }

    /**
     * Back-compat for the fabianhaef -> anvildev namespace rename: lazily alias any
     * old `fabianhaef\simpleform\…` class name to its `anvildev\simpleform\…`
     * counterpart on first reference. This is what lets persisted/serialized old
     * FQCNs keep resolving after the rename — most importantly queued jobs in
     * `{{%queue}}` (whose serialized class name a migration can't safely rewrite)
     * and project-config field types on read-only installs the rename migration
     * can't write to. The DB type columns are still normalized by
     * {@see \anvildev\simpleform\migrations\m260628_000001_rename_fqcns}, but
     * read-only (allowAdminChanges=false) installs keep the old FQCN in their
     * deployed YAML, so this alias must stay until every such install has updated
     * its project config — treat removing it as a documented breaking change, not a
     * routine cleanup.
     */
    private function _registerLegacyClassAliases(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(static function(string $class): void {
            $oldPrefix = 'fabianhaef\\simpleform\\';
            if (!str_starts_with($class, $oldPrefix)) {
                return;
            }
            $new = 'anvildev\\simpleform\\' . substr($class, strlen($oldPrefix));
            if (class_exists($new) || interface_exists($new) || trait_exists($new)) {
                class_alias($new, $class);
            }
        });
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

                // Create a submission via the submitForm mutation; edit an existing
                // one via the updateSubmission mutation (#144). Both are scoped
                // separately so an operator can grant submit without granting edit.
                $event->mutations[$label] = [
                    'simpleFormSubmissions:create' => ['label' => Craft::t('simple-form', 'Submit forms')],
                    'simpleFormSubmissions:edit' => ['label' => Craft::t('simple-form', 'Edit submissions')],
                ];
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Seed the required sender address from Craft's system email settings so a
     * fresh install starts with a valid settings model. Without this, saving
     * ANY settings tab fails whole-model validation on the (invisible) blank
     * Email-tab sender (#280). An env reference (`$VAR`) is carried as-is; the
     * email-format rule already skips those.
     */
    protected function afterInstall(): void
    {
        parent::afterInstall();

        /** @var Settings $settings */
        $settings = $this->getSettings();
        if ($settings->defaultEmailSender !== null && $settings->defaultEmailSender !== '') {
            return;
        }

        $mail = App::mailSettings();
        $values = array_filter([
            'defaultEmailSender' => $mail->fromEmail,
            'defaultEmailSenderName' => $mail->fromName,
        ]);
        if (($values['defaultEmailSender'] ?? '') !== '') {
            Craft::$app->getPlugins()->savePluginSettings($this, $values);
        }
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

    public function getDenylistService(): DenylistService
    {
        /** @var DenylistService $service */
        $service = $this->get('denylistService');
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

    public function getSubmissionEditTokens(): SubmissionEditTokenService
    {
        /** @var SubmissionEditTokenService $service */
        $service = $this->get('submissionEditTokens');
        return $service;
    }

    public function getDrafts(): DraftService
    {
        /** @var DraftService $service */
        $service = $this->get('drafts');
        return $service;
    }

    public function getFieldTypeRegistry(): FieldTypeRegistry
    {
        /** @var FieldTypeRegistry $registry */
        $registry = $this->get('fieldTypeRegistry');
        return $registry;
    }

    public function getSafeRender(): SafeRenderService
    {
        /** @var SafeRenderService $service */
        $service = $this->get('safeRender');
        return $service;
    }

    public function getSubmissionBodyRenderer(): SubmissionBodyRenderer
    {
        /** @var SubmissionBodyRenderer $service */
        $service = $this->get('submissionBodyRenderer');
        return $service;
    }

    public function getFormStructure(): FormStructureService
    {
        /** @var FormStructureService $service */
        $service = $this->get('formStructure');
        return $service;
    }

    public function getFormRender(): FormRenderService
    {
        /** @var FormRenderService $service */
        $service = $this->get('formRender');
        return $service;
    }

    public function getFormClone(): FormCloneService
    {
        /** @var FormCloneService $service */
        $service = $this->get('formClone');
        return $service;
    }

    public function getStencilLibrary(): StencilLibrary
    {
        /** @var StencilLibrary $library */
        $library = $this->get('stencilLibrary');
        return $library;
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

    public function getRetention(): RetentionService
    {
        /** @var RetentionService $service */
        $service = $this->get('retention');
        return $service;
    }

    public function getReports(): ReportsService
    {
        /** @var ReportsService $service */
        $service = $this->get('reports');
        return $service;
    }

    public function getCoupons(): CouponsService
    {
        /** @var CouponsService $service */
        $service = $this->get('coupons');
        return $service;
    }

    public function getWorkflow(): WorkflowService
    {
        /** @var WorkflowService $service */
        $service = $this->get('workflow');
        return $service;
    }

    public function getQuizScoring(): QuizScoringService
    {
        /** @var QuizScoringService $service */
        $service = $this->get('quizScoring');
        return $service;
    }

    public function getNotifications(): NotificationsService
    {
        /** @var NotificationsService $service */
        $service = $this->get('notifications');
        return $service;
    }

    public function getSubmitMessages(): SubmitMessagesService
    {
        /** @var SubmitMessagesService $service */
        $service = $this->get('submitMessages');
        return $service;
    }

    public function getPdf(): PdfService
    {
        /** @var PdfService $service */
        $service = $this->get('pdf');
        return $service;
    }

    public function getAudit(): AuditService
    {
        /** @var AuditService $service */
        $service = $this->get('audit');
        return $service;
    }

    public function getNotificationLog(): NotificationLogService
    {
        /** @var NotificationLogService $service */
        $service = $this->get('notificationLog');
        return $service;
    }

    public function getPayments(): PaymentsService
    {
        /** @var PaymentsService $service */
        $service = $this->get('payments');
        return $service;
    }

    public function getFields(): FieldsService
    {
        /** @var FieldsService $service */
        $service = $this->get('fields');
        return $service;
    }

    public function getFieldSync(): FieldSyncService
    {
        /** @var FieldSyncService $service */
        $service = $this->get('fieldSync');
        return $service;
    }

    public function getPortability(): FormPortabilityService
    {
        /** @var FormPortabilityService $service */
        $service = $this->get('portability');
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

        if ($isAdmin || $user?->can(SimpleFormPermissions::VIEW_SUBMISSIONS)) {
            $subnav['dashboard'] = ['label' => Craft::t('simple-form', 'Dashboard'), 'url' => 'simple-form/dashboard'];
        }
        if ($isAdmin || $user?->can(SimpleFormPermissions::MANAGE_FORMS)) {
            $subnav['forms'] = ['label' => Craft::t('simple-form', 'Forms'), 'url' => 'simple-form/forms'];
        }
        if ($isAdmin || $user?->can(SimpleFormPermissions::VIEW_SUBMISSIONS)) {
            $subnav['submissions'] = ['label' => Craft::t('simple-form', 'Submissions'), 'url' => 'simple-form/submissions'];
            $subnav['notifications'] = ['label' => Craft::t('simple-form', 'Notifications'), 'url' => 'simple-form/notifications'];
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
        $event->rules['simple-form'] = 'simple-form/dashboard/index';
        $event->rules['simple-form/dashboard'] = 'simple-form/dashboard/index';
        $event->rules['simple-form/forms'] = 'simple-form/forms/index';
        $event->rules['simple-form/forms/new'] = 'simple-form/forms/edit';
        $event->rules['simple-form/forms/edit/<formId:\d+>'] = 'simple-form/forms/edit';
        $event->rules['simple-form/forms/save'] = 'simple-form/forms/save';
        $event->rules['simple-form/forms/duplicate'] = 'simple-form/forms/duplicate';
        $event->rules['simple-form/forms/new-from-stencil'] = 'simple-form/forms/new-from-stencil';
        $event->rules['simple-form/forms/delete/<formId:\d+>'] = 'simple-form/forms/delete';
        // Portable form definition import/export (#139).
        $event->rules['simple-form/forms/export/<formId:\d+>'] = 'simple-form/forms/export';
        $event->rules['simple-form/forms/import'] = 'simple-form/forms/import';
        // Integrations: global definitions managed under Settings, enabled per form.
        $event->rules['simple-form/integrations/save'] = 'simple-form/integrations/save';
        $event->rules['simple-form/integrations/delete'] = 'simple-form/integrations/delete';
        $event->rules['simple-form/integrations/toggle'] = 'simple-form/integrations/toggle';
        $event->rules['simple-form/integrations/toggle-form'] = 'simple-form/integrations/toggle-form';
        $event->rules['simple-form/integrations/resend'] = 'simple-form/integrations/resend';
        $event->rules['simple-form/integrations/failures'] = 'simple-form/integrations/failures';
        $event->rules['simple-form/integrations/resend-all'] = 'simple-form/integrations/resend-all';
        // Per-form: choose which global integrations are active on a form.
        $event->rules['simple-form/forms/<formId:\d+>/integrations'] = 'simple-form/integrations/index';

        // Per-form email notifications (admin alerts + autoresponders).
        $event->rules['simple-form/notifications/save'] = 'simple-form/notifications/save';
        $event->rules['simple-form/notifications/delete'] = 'simple-form/notifications/delete';
        $event->rules['simple-form/notifications/toggle'] = 'simple-form/notifications/toggle';
        $event->rules['simple-form/forms/<formId:\d+>/notifications'] = 'simple-form/notifications/index';
        $event->rules['simple-form/forms/<formId:\d+>/notifications/new'] = 'simple-form/notifications/edit';
        $event->rules['simple-form/forms/<formId:\d+>/notifications/<notificationId:\d+>'] = 'simple-form/notifications/edit';

        // Per-form survey report (#240).
        $event->rules['simple-form/forms/<formId:\d+>/report'] = 'simple-form/submissions/report';

        // Per-form passive partial capture listing (#242).
        $event->rules['simple-form/forms/<formId:\d+>/partials'] = 'simple-form/partials/index';
        $event->rules['simple-form/partials/delete'] = 'simple-form/partials/delete';

        $event->rules['simple-form/notifications'] = 'simple-form/notification-log/index';
        $event->rules['simple-form/notifications/resend'] = 'simple-form/notification-log/resend';
        $event->rules['simple-form/submissions'] = 'simple-form/submissions/index';
        $event->rules['simple-form/submissions/analytics'] = 'simple-form/submissions/analytics';
        $event->rules['simple-form/submissions/export'] = 'simple-form/submissions/export';
        $event->rules['simple-form/submissions/export-options'] = 'simple-form/submissions/export-options';
        $event->rules['simple-form/submissions/<submissionId:\d+>/pdf'] = 'simple-form/submissions/pdf';
        $event->rules['simple-form/submissions/<submissionId:\d+>'] = 'simple-form/submissions/view';
        $event->rules['simple-form/submissions/toggle-status'] = 'simple-form/submissions/toggle-status';
        $event->rules['simple-form/settings'] = 'simple-form/settings/index';
        $event->rules['simple-form/settings/save'] = 'simple-form/settings/save';
        $event->rules['simple-form/settings/mcp/create-token'] = 'simple-form/settings/create-mcp-token';
        $event->rules['simple-form/settings/mcp/revoke-token'] = 'simple-form/settings/revoke-mcp-token';
        // Workflow (#248) stage/transition management actions.
        $event->rules['simple-form/settings/workflow/add-status'] = 'simple-form/settings/add-workflow-status';
        $event->rules['simple-form/settings/workflow/delete-status'] = 'simple-form/settings/delete-workflow-status';
        $event->rules['simple-form/settings/workflow/add-transition'] = 'simple-form/settings/add-workflow-transition';
        $event->rules['simple-form/settings/workflow/delete-transition'] = 'simple-form/settings/delete-workflow-transition';
        // Integrations management lives under Settings. Specific routes must
        // precede the generic settings/<tab> catch-all below.
        $event->rules['simple-form/settings/audit'] = 'simple-form/audit/index';
        $event->rules['simple-form/settings/integrations'] = 'simple-form/integrations/settings-index';
        $event->rules['simple-form/settings/integrations/new'] = 'simple-form/integrations/edit';
        $event->rules['simple-form/settings/integrations/<integrationId:\d+>'] = 'simple-form/integrations/edit';
        // Coupons management (#246), also under Settings. Specific routes precede
        // the generic settings/<tab> catch-all below.
        $event->rules['simple-form/settings/coupons'] = 'simple-form/coupons/settings-index';
        $event->rules['simple-form/settings/coupons/new'] = 'simple-form/coupons/edit';
        $event->rules['simple-form/settings/coupons/<couponId:\d+>'] = 'simple-form/coupons/edit';
        $event->rules['simple-form/coupons/save'] = 'simple-form/coupons/save';
        $event->rules['simple-form/coupons/delete'] = 'simple-form/coupons/delete';
        $event->rules['simple-form/coupons/toggle'] = 'simple-form/coupons/toggle';
        $event->rules['simple-form/settings/<tab:\w+>'] = 'simple-form/settings/section';

        // Fields AJAX endpoints
        $event->rules['simple-form/fields/add'] = 'simple-form/fields/add';
        $event->rules['simple-form/fields/edit'] = 'simple-form/fields/edit';
        $event->rules['simple-form/fields/delete'] = 'simple-form/fields/delete';
        $event->rules['simple-form/fields/reorder'] = 'simple-form/fields/reorder';
    }
}
