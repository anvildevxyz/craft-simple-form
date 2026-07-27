<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\SubmitController;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\test\TestMailer;
use craft\web\Response;
use yii\mail\MessageInterface;

/**
 * Shared seeding + request helpers for the functional smoke Cests.
 *
 * The smoke suite boots a real Craft via the `\craft\test\Craft` module inside a
 * per-test DB transaction (configured in the root codeception.yml). The
 * `\Helper\Integration` module pins a front-end web request so the public
 * render/submit paths run as they would on the site under the CLI SAPI.
 *
 * These Cests deliberately seed forms + fields through the data layer (mirroring
 * how FieldsController persists a field) rather than clicking the JS form
 * builder, then exercise the public submit path through
 * {@see SubmissionService::createFromRequest()} — genuinely new HTTP/Twig/
 * controller coverage versus the service-only integration suite.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
abstract class BaseSmokeCest
{
    // =========================================================================
    // PROTECTED METHODS
    // =========================================================================

    /**
     * Create and persist a Form element for the current (or given) site.
     */
    protected function createForm(
        string $name,
        string $handle,
        ?string $emailTo = null,
        ?int $siteId = null,
    ): Form {
        $form = new Form();
        $form->name = $name;
        $form->handle = $handle;
        $form->title = $name;
        $form->emailTo = $emailTo;
        $form->siteId = $siteId ?? Craft::$app->getSites()->getPrimarySite()->id;

        Craft::$app->getElements()->saveElement($form);

        return $form;
    }

    /**
     * Insert a field for a form (structural row + per-site label/helpText rows),
     * mirroring how FieldsController persists a field. Returns the new field id.
     *
     * @param array<string, mixed> $config
     */
    protected function createField(
        int $formId,
        string $type,
        string $name,
        string $label,
        bool $required = false,
        array $config = [],
        string $helpText = '',
    ): int {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $formId,
            'type' => $type,
            'name' => $name,
            'required' => $required,
            'config' => $config,
            'sortOrder' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $fieldId = (int)$db->getLastInsertID();

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
                'fieldId' => $fieldId,
                'siteId' => $site->id,
                'label' => $label,
                'helpText' => $helpText ?: null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        return $fieldId;
    }

    /**
     * Submit a form through the shared upload-aware request path, exactly like
     * the public SubmitController does. Field values post under `field_<id>`.
     *
     * @param array<string, mixed> $bodyParams
     * @return array{submission: \anvildev\simpleform\elements\Submission|null, errors: array<string, mixed>|null, data?: array<string, mixed>}
     */
    protected function submitRequest(string $formHandle, array $bodyParams): array
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(array_merge(['formHandle' => $formHandle], $bodyParams));

        $form = Form::find()
            ->handle($formHandle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        return $this->service()->createFromRequest($form, $request);
    }

    /**
     * Render a form's public HTML through the shared FormRender service — the
     * exact entry point the `simpleForm()` Twig function and the
     * `craft.simpleForm.form()` variable both delegate to. The Twig function
     * itself isn't registered under the console-booted test app, so this calls
     * the service directly for identical output.
     *
     * @param array<string, mixed> $options
     */
    protected function renderForm(string $handle, array $options = []): string
    {
        return Plugin::getInstance()->getFormRender()->renderForm($handle, $options);
    }

    /**
     * Reload a form through the element query so per-site columns are fresh.
     */
    protected function reloadForm(Form $form): Form
    {
        return Form::find()->id($form->id)->one();
    }

    /**
     * Submit through the transport-agnostic service entry point (captcha skipped).
     *
     * @param array<int|string, mixed> $values keyed by field id or `field_<id>`
     * @param array<string, mixed>     $context
     * @return array{submission: \anvildev\simpleform\elements\Submission|null, errors: array<string, mixed>|null, data?: array<string, mixed>}
     */
    protected function submitDirect(Form $form, array $values, array $context = []): array
    {
        return $this->service()->submit($form, $values, array_merge(['skipCaptcha' => true], $context));
    }

    /**
     * Create a global integration definition and return the saved model.
     *
     * @param array<string, mixed> $settings
     */
    protected function createIntegration(
        string $type,
        string $name,
        array $settings = [],
        bool $enabled = true,
    ): IntegrationModel {
        $model = new IntegrationModel();
        $model->type = $type;
        $model->name = $name;
        $model->enabled = $enabled;
        $model->settings = $settings;

        Plugin::getInstance()->getIntegrations()->saveIntegration($model);

        return $model;
    }

    /**
     * Attach a global integration to a form's dispatch set.
     */
    protected function attachIntegration(int $formId, int $integrationId): void
    {
        Plugin::getInstance()->getIntegrations()->toggleFormIntegration($formId, $integrationId);
    }

    /**
     * Fetch integration dispatch log rows for a submission.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function integrationLogs(int $submissionId): array
    {
        return (new Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $submissionId])
            ->all();
    }

    /**
     * Run work with synchronous side effects (integrations + notification email).
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    protected function withSyncSideEffects(callable $work): mixed
    {
        $settings = Plugin::getInstance()->getSettings();
        $previous = $settings->dispatchIntegrationsSynchronously;
        $settings->dispatchIntegrationsSynchronously = true;

        try {
            return $work();
        } finally {
            $settings->dispatchIntegrationsSynchronously = $previous;
        }
    }

    /**
     * Call the save-draft endpoint the front-end save-and-resume button uses.
     *
     * @param array<string, mixed> $fields
     */
    protected function callSaveDraft(string $handle, array $fields): ?string
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => $handle] + $fields);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmitController('submit', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $data = $controller->actionSaveDraft()->data;

        return (is_array($data) && ($data['success'] ?? false)) ? (string) $data['token'] : null;
    }

    /**
     * Run $work with the test mailer's callback wrapped to collect messages.
     *
     * @return list<MessageInterface>
     */
    protected function captureSentMessages(callable $work): array
    {
        $mailer = Craft::$app->getMailer();
        $collected = [];

        if ($mailer instanceof TestMailer) {
            $original = $mailer->callback;
            $mailer->callback = function(MessageInterface $message) use (&$collected, $original): void {
                $collected[] = $message;
                if (is_callable($original)) {
                    $original($message);
                }
            };
            try {
                $work();
            } finally {
                $mailer->callback = $original;
            }
        } else {
            $work();
        }

        return $collected;
    }

    /**
     * Extract the body from a Craft test mail message.
     */
    protected function messageBody(MessageInterface $message): string
    {
        if (method_exists($message, 'getSwiftMessage')) {
            return (string) $message;
        }

        return (string) $message;
    }

    /**
     * Clear the rate-limit counter for the current request IP (test isolation).
     */
    protected function resetSubmitRateLimitForCurrentIp(): void
    {
        $ip = Craft::$app->getRequest()->getUserIP();
        if ($ip !== null && $ip !== '') {
            Craft::$app->getCache()->delete('simple-form:ratelimit:submit:' . $ip);
        }
    }

    /**
     * The shared submission service.
     */
    protected function service(): SubmissionService
    {
        return Plugin::getInstance()->getSubmissionService();
    }
}
