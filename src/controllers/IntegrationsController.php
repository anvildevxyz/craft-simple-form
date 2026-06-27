<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\jobs\SendIntegrationJob;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Controller;
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
            'failedDispatchCount' => $service->countFailedDispatches(),
        ]);
    }

    private function service(): \anvildev\simpleform\services\IntegrationsService
    {
        return Plugin::getInstance()->getIntegrations();
    }

    /**
     * The integration types offerable as a *new* integration. Solo is limited to
     * the always-available handles; Pro gets everything.
     *
     * @return array<string, string>
     */
    private function availableTypesFor(\anvildev\simpleform\services\IntegrationTypeRegistry $registry): array
    {
        $all = $registry->getAllTypes();
        if (Editions::isPro()) {
            return $all;
        }

        return array_intersect_key($all, array_flip(Editions::SOLO_INTEGRATIONS));
    }

    private function proIntegrationMessage(?\anvildev\simpleform\integrations\IntegrationTypeInterface $type): string
    {
        return Craft::t('simple-form', 'The {type} integration requires the Pro edition.', [
            'type' => $type !== null ? $type::displayName() : Craft::t('simple-form', 'selected'),
        ]);
    }

    /**
     * Per-form screen: every global integration with a toggle controlling
     * whether it is attached to (dispatched for) this form.
     */
    public function actionIndex(int $formId): Response
    {
        $form = $this->getFormOrFail($formId);
        $service = $this->service();

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
        if ($integrationId !== null && ($integration = $this->service()->getIntegrationById($integrationId)) === null) {
            throw new NotFoundHttpException('Integration not found');
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $typeHandle = $integration?->type ?? $request->getQueryParam('type');

        // Resolve the chosen type (from the dropdown / an existing record). When
        // none is chosen yet the template just shows the type dropdown.
        $type = $typeHandle !== null ? $registry->getType($typeHandle) : null;
        if ($typeHandle !== null && $type === null) {
            throw new NotFoundHttpException('Unknown integration type');
        }

        if ($type !== null && $integration === null) {
            $integration = new IntegrationModel();
            $integration->type = $typeHandle;
            $integration->name = $type::displayName();
        }

        // Solo may only add the always-available integrations. Editing an
        // existing Pro integration on a downgraded Solo install is still allowed
        // (no-new-escalation); creating a new one is bounced before the form.
        if (
            $integration?->id === null
            && $typeHandle !== null
            && !Editions::integrationAllowed($typeHandle)
        ) {
            Craft::$app->getSession()->setError($this->proIntegrationMessage($type));
            return $this->redirect('simple-form/settings/integrations');
        }

        return $this->renderTemplate('simple-form/settings/integrations/edit', [
            'integration' => $integration,
            'type' => $type,
            'availableTypes' => $this->availableTypesFor($registry),
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
        $service = $this->service();

        $integration = null;
        if ($integrationId && ($integration = $service->getIntegrationById((int) $integrationId)) === null) {
            throw new NotFoundHttpException('Integration not found');
        }
        $integration ??= new IntegrationModel();

        $integration->type = (string) $request->getRequiredBodyParam('type');
        $integration->name = (string) $request->getBodyParam('name', $integration->type);
        $integration->enabled = (bool) $request->getBodyParam('enabled', true);
        $settings = $request->getBodyParam('settings', []);
        $integration->settings = is_array($settings) ? $settings : [];

        $type = $registry->getType($integration->type);
        if ($type === null) {
            throw new NotFoundHttpException('Unknown integration type');
        }

        // Edition gate (authoritative): Solo may not create a new integration of
        // a Pro type. Existing ones (loaded by id) stay editable after downgrade.
        if (!$integrationId && !Editions::integrationAllowed($integration->type)) {
            Craft::$app->getSession()->setError($this->proIntegrationMessage($type));
            return $this->renderTemplate('simple-form/settings/integrations/edit', [
                'integration' => $integration,
                'type' => $type,
                'availableTypes' => $this->availableTypesFor($registry),
                'errors' => [],
            ]);
        }

        $errors = $service->validateSettings($type, $integration->settings);
        if ($errors !== [] || !$service->saveIntegration($integration)) {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t save integration.'));
            return $this->renderTemplate('simple-form/settings/integrations/edit', [
                'integration' => $integration,
                'type' => $type,
                'availableTypes' => $this->availableTypesFor($registry),
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

        if (!$this->service()->deleteIntegration($integrationId)) {
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

        $service = $this->service();
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
        $service = $this->service();

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

        $integration = $this->service()->getIntegrationById($integrationId);
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

    /**
     * Dead-letter view: every dispatch whose most recent attempt failed, so an
     * operator can see what didn't get delivered and resend it.
     */
    public function actionFailures(): Response
    {
        return $this->renderTemplate('simple-form/settings/integrations/failures', [
            'selectedSettingsSubnavItem' => 'integrations',
            'failures' => $this->service()->getFailedDispatches(),
            'typeNames' => Plugin::getInstance()->getIntegrationTypeRegistry()->getAllTypes(),
        ]);
    }

    /**
     * Re-queue every currently-failed dispatch in one go.
     */
    public function actionResendAll(): Response
    {
        $this->requirePostRequest();

        $queue = Craft::$app->getQueue();
        $count = 0;
        foreach ($this->service()->getFailedDispatches() as $failure) {
            if ($failure['submissionId'] === null) {
                continue;
            }
            $queue->push(new SendIntegrationJob([
                'integrationId' => $failure['integrationId'],
                'submissionId' => $failure['submissionId'],
            ]));
            $count++;
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', '{count} dispatch(es) re-queued.', ['count' => $count]));
        return $this->redirectToPostedUrl();
    }
}
