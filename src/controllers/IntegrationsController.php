<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\jobs\SendIntegrationJob;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP management of a form's outbound integrations (#79). Listing, add/edit/
 * delete, enable toggle, and per-submission resend. Gated by manageIntegrations.
 */
class IntegrationsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_INTEGRATIONS;

    private function getFormOrFail(int $formId): Form
    {
        $form = Form::find()->siteId('*')->id($formId)->status(null)->one();
        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }
        return $form;
    }

    /**
     * Global integration management, rendered as the Settings → Integrations tab.
     */
    public function actionSettingsIndex(): Response
    {
        $service = Plugin::getInstance()->getIntegrations();

        return $this->renderTemplate('simple-form/settings/index', [
            'selectedSettingsSubnavItem' => 'integrations',
            'integrations' => $service->getAllIntegrations(),
            'typeNames' => Plugin::getInstance()->getIntegrationTypeRegistry()->getAllTypes(),
        ]);
    }

    /**
     * Legacy `/simple-form/integrations` entry point — integrations now live
     * under Settings.
     */
    public function actionGlobalIndex(): Response
    {
        return $this->redirect('simple-form/settings/integrations');
    }

    /**
     * Per-form screen: every global integration with a toggle controlling
     * whether it is attached to (dispatched for) this form.
     */
    public function actionIndex(int $formId): Response
    {
        $form = $this->getFormOrFail($formId);
        $service = Plugin::getInstance()->getIntegrations();

        return $this->renderTemplate('simple-form/forms/integrations/index', [
            'form' => $form,
            'integrations' => $service->getAllIntegrations(),
            'attachedIds' => $service->getAttachedIntegrationIds($formId),
            'typeNames' => Plugin::getInstance()->getIntegrationTypeRegistry()->getAllTypes(),
        ]);
    }

    /**
     * Create/edit a global integration definition (Settings → Integrations).
     */
    public function actionEdit(?int $integrationId = null): Response
    {
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();

        $integration = null;
        if ($integrationId !== null) {
            $integration = Plugin::getInstance()->getIntegrations()->getIntegrationById($integrationId);
            if ($integration === null) {
                throw new NotFoundHttpException('Integration not found');
            }
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $typeHandle = $integration?->type ?? $request->getQueryParam('type');

        // New integration with no type chosen yet -> show the type picker.
        if ($typeHandle === null) {
            return $this->renderTemplate('simple-form/settings/integrations/edit', [
                'integration' => null,
                'type' => null,
                'availableTypes' => $registry->getAllTypes(),
                'errors' => [],
            ]);
        }

        $type = $registry->getType($typeHandle);
        if ($type === null) {
            throw new NotFoundHttpException('Unknown integration type');
        }

        if ($integration === null) {
            $integration = new IntegrationModel();
            $integration->type = $typeHandle;
            $integration->name = $type::displayName();
        }

        return $this->renderTemplate('simple-form/settings/integrations/edit', [
            'integration' => $integration,
            'type' => $type,
            'availableTypes' => null,
            'errors' => [],
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $integrationId = $request->getBodyParam('integrationId');
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();
        $service = Plugin::getInstance()->getIntegrations();

        $integration = null;
        if ($integrationId) {
            $integration = $service->getIntegrationById((int) $integrationId);
            if ($integration === null) {
                throw new NotFoundHttpException('Integration not found');
            }
        }
        if ($integration === null) {
            $integration = new IntegrationModel();
        }

        $integration->type = (string) $request->getRequiredBodyParam('type');
        $integration->name = (string) $request->getBodyParam('name', $integration->type);
        $integration->enabled = (bool) $request->getBodyParam('enabled', true);
        $settings = $request->getBodyParam('settings', []);
        $integration->settings = is_array($settings) ? $settings : [];

        $type = $registry->getType($integration->type);
        if ($type === null) {
            throw new NotFoundHttpException('Unknown integration type');
        }

        $errors = $service->validateSettings($type, $integration->settings);
        if ($errors !== [] || !$service->saveIntegration($integration)) {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t save integration.'));
            return $this->renderTemplate('simple-form/settings/integrations/edit', [
                'integration' => $integration,
                'type' => $type,
                'availableTypes' => null,
                'errors' => $errors,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Integration saved.'));
        return $this->redirect('simple-form/settings/integrations');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $integrationId = (int) $request->getRequiredBodyParam('integrationId');
        $deleted = Plugin::getInstance()->getIntegrations()->deleteIntegration($integrationId);

        if (!$deleted) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t complete that action.'));
        }

        return $this->asJsonSuccess();
    }

    /**
     * Toggle a global integration's master enabled flag (Settings list).
     */
    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $service = Plugin::getInstance()->getIntegrations();
        $integration = $service->getIntegrationById((int) $request->getRequiredBodyParam('integrationId'));
        if ($integration === null) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t complete that action.'));
        }

        $integration->enabled = !$integration->enabled;
        $service->saveIntegration($integration);

        return $this->asJsonSuccess(['enabled' => $integration->enabled]);
    }

    /**
     * Attach/detach an integration from a form (per-form management screen).
     */
    public function actionToggleForm(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $formId = (int) $request->getRequiredBodyParam('formId');
        $integrationId = (int) $request->getRequiredBodyParam('integrationId');
        $service = Plugin::getInstance()->getIntegrations();

        if ($service->getIntegrationById($integrationId) === null) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t complete that action.'));
        }

        $attached = $service->toggleFormIntegration($formId, $integrationId);

        return $this->asJsonSuccess(['attached' => $attached]);
    }

    /**
     * Re-enqueue dispatch of one integration for one submission, from the
     * submission detail screen.
     */
    public function actionResend(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $integrationId = (int) $request->getRequiredBodyParam('integrationId');
        $submissionId = (int) $request->getRequiredBodyParam('submissionId');

        $service = Plugin::getInstance()->getIntegrations();
        $integration = $service->getIntegrationById($integrationId);
        $submission = Submission::find()->id($submissionId)->one();

        if ($integration === null || $submission === null) {
            throw new NotFoundHttpException('Integration or submission not found');
        }

        Craft::$app->getQueue()->push(new SendIntegrationJob([
            'integrationId' => $integrationId,
            'submissionId' => $submissionId,
        ]));

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Dispatch re-queued.'));
        return $this->redirectToPostedUrl();
    }
}
