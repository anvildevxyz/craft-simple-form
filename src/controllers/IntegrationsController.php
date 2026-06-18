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
     * Global index: every integration across all forms (CP subnav entry point).
     */
    public function actionGlobalIndex(): Response
    {
        $service = Plugin::getInstance()->getIntegrations();
        $integrations = $service->getAllIntegrations();

        // Resolve each integration's form (id => title) for the Form column.
        $forms = [];
        foreach ($integrations as $integration) {
            $fid = (int) $integration->formId;
            if (!isset($forms[$fid])) {
                $form = Form::find()->siteId('*')->id($fid)->status(null)->one();
                $forms[$fid] = $form instanceof Form ? ($form->title ?? $form->name) : ('#' . $fid);
            }
        }

        return $this->renderTemplate('simple-form/integrations/index', [
            'integrations' => $integrations,
            'formTitles' => $forms,
            'typeNames' => Plugin::getInstance()->getIntegrationTypeRegistry()->getAllTypes(),
        ]);
    }

    public function actionIndex(int $formId): Response
    {
        $form = $this->getFormOrFail($formId);
        $service = Plugin::getInstance()->getIntegrations();

        return $this->renderTemplate('simple-form/forms/integrations/index', [
            'form' => $form,
            'integrations' => $service->getIntegrationsForForm($formId),
            'typeNames' => Plugin::getInstance()->getIntegrationTypeRegistry()->getAllTypes(),
        ]);
    }

    public function actionEdit(int $formId, ?int $integrationId = null): Response
    {
        $form = $this->getFormOrFail($formId);
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();

        $integration = null;
        if ($integrationId !== null) {
            $integration = Plugin::getInstance()->getIntegrations()->getIntegrationById($integrationId);
            if ($integration === null || $integration->formId !== $formId) {
                throw new NotFoundHttpException('Integration not found');
            }
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $typeHandle = $integration?->type ?? $request->getQueryParam('type');

        // New integration with no type chosen yet -> show the type picker.
        if ($typeHandle === null) {
            return $this->renderTemplate('simple-form/forms/integrations/edit', [
                'form' => $form,
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
            $integration->formId = $formId;
            $integration->type = $typeHandle;
            $integration->name = $type::displayName();
        }

        return $this->renderTemplate('simple-form/forms/integrations/edit', [
            'form' => $form,
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

        $formId = (int) $request->getRequiredBodyParam('formId');
        $form = $this->getFormOrFail($formId);
        $integrationId = $request->getBodyParam('integrationId');
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();
        $service = Plugin::getInstance()->getIntegrations();

        $integration = null;
        if ($integrationId) {
            $integration = $service->getIntegrationById((int) $integrationId);
            if ($integration === null || $integration->formId !== $formId) {
                throw new NotFoundHttpException('Integration not found');
            }
        }
        if ($integration === null) {
            $integration = new IntegrationModel();
            $integration->formId = $formId;
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
            return $this->renderTemplate('simple-form/forms/integrations/edit', [
                'form' => $form,
                'integration' => $integration,
                'type' => $type,
                'availableTypes' => null,
                'errors' => $errors,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Integration saved.'));
        return $this->redirect("simple-form/forms/{$formId}/integrations");
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
