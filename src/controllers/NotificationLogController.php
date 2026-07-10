<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\NotificationLogService;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP index of outbound notification emails sent for form submissions.
 *
 * @author Fabian Haefliger
 */
class NotificationLogController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::VIEW_SUBMISSIONS;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Resending is a mutating action — it re-dispatches outbound email — so
     * it additionally requires MANAGE_SUBMISSIONS on top of the class-level
     * VIEW_SUBMISSIONS gate, matching {@see SubmissionsController}.
     *
     * @param \yii\base\Action $action
     * @throws \yii\web\ForbiddenHttpException if the user lacks the required permission
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if ($action->id === 'resend') {
            $this->requirePermission(SimpleFormPermissions::MANAGE_SUBMISSIONS);
        }

        return true;
    }

    public function actionIndex(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $formId = $request->getQueryParam('formId');
        $formId = is_numeric($formId) ? (int) $formId : null;
        $status = (string) $request->getQueryParam('status', '');
        $status = in_array($status, [NotificationLogService::STATUS_SUCCESS, NotificationLogService::STATUS_FAILED], true)
            ? $status
            : null;

        $log = Plugin::getInstance()->getNotificationLog();

        return $this->renderTemplate('simple-form/notification-log/index', [
            'entries' => $log->recent(200, $formId, $status),
            'forms' => Form::find()->siteId('*')->orderBy(['name' => SORT_ASC])->status(null)->all(),
            'formId' => $formId,
            'status' => $status ?? '',
            'stats' => $log->stats($formId),
            'hasAny' => $log->count() > 0,
            'hasFilters' => $formId !== null || $status !== null,
        ]);
    }

    /**
     * Re-dispatch the notifications behind one log row (#318). Reuses the
     * existing `SendNotifications` queue job and writes a fresh log row that
     * references the original send, so the retry is auditable.
     *
     * @throws \yii\web\BadRequestHttpException if the request is not a POST
     */
    public function actionResend(): Response
    {
        $this->requirePostRequest();

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $logId = (int) $request->getRequiredBodyParam('logId');

        $resent = Plugin::getInstance()->getEmailService()->resendFromLog($logId);

        if (!$resent) {
            $message = Craft::t('simple-form', 'Could not resend — the original submission is no longer available.');
            if ($request->getAcceptsJson()) {
                return $this->asJsonError($message);
            }
            $this->setFailFlash($message);

            return $this->redirectToPostedUrl();
        }

        $message = Craft::t('simple-form', 'Notification queued for resend.');
        if ($request->getAcceptsJson()) {
            return $this->asJsonSuccess(['message' => $message]);
        }
        $this->setSuccessFlash($message);

        return $this->redirectToPostedUrl();
    }
}
