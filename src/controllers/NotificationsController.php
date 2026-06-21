<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\ConditionalEvaluator;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP management of a form's email notifications (#112): admin alerts +
 * autoresponders, optionally gated by a send condition. Gated by manageForms.
 */
class NotificationsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_FORMS;

    public function actionIndex(int $formId): Response
    {
        $form = $this->getFormOrFail($formId);

        return $this->renderTemplate('simple-form/forms/notifications/index', [
            'form' => $form,
            'notifications' => Plugin::getInstance()->getNotifications()->getForForm($formId),
        ]);
    }

    public function actionEdit(int $formId, ?int $notificationId = null): Response
    {
        $form = $this->getFormOrFail($formId);

        $notification = null;
        if ($notificationId !== null) {
            $notification = Plugin::getInstance()->getNotifications()->getById($notificationId);
            if ($notification === null || $notification->formId !== $formId) {
                throw new NotFoundHttpException('Notification not found');
            }
        }
        if ($notification === null) {
            $notification = new NotificationModel();
            $notification->formId = $formId;
            $notification->name = (string) Craft::t('simple-form', 'New notification');
        }

        return $this->renderTemplate('simple-form/forms/notifications/edit', [
            'form' => $form,
            'notification' => $notification,
            'fieldOptions' => $this->fieldOptions($form),
            'operators' => ConditionalEvaluator::OPERATORS,
            'pdfAvailable' => Plugin::getInstance()->getPdf()->isAvailable(),
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
        $service = Plugin::getInstance()->getNotifications();

        $notificationId = $request->getBodyParam('notificationId');
        $notification = null;
        if ($notificationId) {
            $notification = $service->getById((int) $notificationId);
            if ($notification === null || $notification->formId !== $formId) {
                throw new NotFoundHttpException('Notification not found');
            }
        }
        if ($notification === null) {
            $notification = new NotificationModel();
            $notification->formId = $formId;
        }

        $notification->name = (string) $request->getBodyParam('name', '');
        $notification->enabled = (bool) $request->getBodyParam('enabled', true);
        $notification->recipientType = (string) $request->getBodyParam('recipientType', NotificationModel::RECIPIENT_FIXED);
        $notification->recipient = trim((string) $request->getBodyParam('recipient', ''));
        $notification->subject = $this->nullableString($request->getBodyParam('subject'));
        $notification->replyTo = $this->nullableString($request->getBodyParam('replyTo'));
        $notification->body = $this->nullableString($request->getBodyParam('body'));
        $notification->attachPdf = (bool) $request->getBodyParam('attachPdf', false);
        $notification->attachUploads = (bool) $request->getBodyParam('attachUploads', false);
        $notification->conditional = $this->buildConditional($request);

        if (!$service->save($notification)) {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t save notification.'));
            return $this->renderTemplate('simple-form/forms/notifications/edit', [
                'form' => $form,
                'notification' => $notification,
                'fieldOptions' => $this->fieldOptions($form),
                'operators' => ConditionalEvaluator::OPERATORS,
                'pdfAvailable' => Plugin::getInstance()->getPdf()->isAvailable(),
                'errors' => $notification->getErrors(),
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Notification saved.'));
        return $this->redirect("simple-form/forms/{$formId}/notifications");
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $deleted = Plugin::getInstance()->getNotifications()->delete((int) $request->getRequiredBodyParam('notificationId'));
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

        $enabled = Plugin::getInstance()->getNotifications()->toggle((int) $request->getRequiredBodyParam('notificationId'));
        if ($enabled === null) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t complete that action.'));
        }
        return $this->asJsonSuccess(['enabled' => $enabled]);
    }

    /**
     * Assemble a single-rule conditional config from the posted fields, or null
     * when the editor didn't enable a condition.
     *
     * @return array<string, mixed>|null
     */
    private function buildConditional(\craft\web\Request $request): ?array
    {
        if (!$request->getBodyParam('conditionEnabled')) {
            return null;
        }

        $field = trim((string) $request->getBodyParam('conditionField', ''));
        if ($field === '') {
            return null;
        }

        return [
            'enabled' => true,
            'match' => ConditionalEvaluator::MATCH_ALL,
            'action' => ConditionalEvaluator::ACTION_SHOW,
            'rules' => [[
                'field' => $field,
                // F19 (CWE-20): only persist a known operator.
                'operator' => $this->normalizeOperator((string) $request->getBodyParam('conditionOperator', 'eq')),
                'value' => (string) $request->getBodyParam('conditionValue', ''),
            ]],
        ];
    }

    /** Clamp a posted condition operator to the supported set, defaulting to eq. */
    private function normalizeOperator(string $operator): string
    {
        return in_array($operator, ConditionalEvaluator::OPERATORS, true) ? $operator : 'eq';
    }

    /**
     * Field handle => label options for the recipient/condition selects.
     *
     * @return array<string, string>
     */
    private function fieldOptions(Form $form): array
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, (int) $form->siteId);
        $options = [];
        foreach ($fields as $field) {
            $options[(string) $field['name']] = (string) $field['label'];
        }
        return $options;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        return $value === '' ? null : $value;
    }
}
