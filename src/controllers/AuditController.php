<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Read-only audit-log viewer, rendered as the Settings → Audit tab (#114).
 */
class AuditController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_SETTINGS;

    public function actionIndex(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $action = (string) $request->getQueryParam('auditAction', '');

        $entries = Plugin::getInstance()->getAudit()->recent(200, $action ?: null);

        // Resolve actor names once.
        $users = Craft::$app->getUsers();
        $userNames = [];
        foreach ($entries as $entry) {
            if ($entry['userId'] === null) {
                continue;
            }
            $uid = (int) $entry['userId'];
            $userNames[$uid] ??= ($user = $users->getUserById($uid))
                ? ($user->fullName ?: $user->username)
                : ('#' . $uid);
        }

        return $this->renderTemplate('simple-form/settings/index', [
            'selectedSettingsSubnavItem' => 'audit',
            'auditEntries' => $entries,
            'auditUserNames' => $userNames,
            'auditAction' => $action,
        ]);
    }
}
