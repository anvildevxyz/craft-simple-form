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
}
