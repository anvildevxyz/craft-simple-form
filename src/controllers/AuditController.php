<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\Plugin;
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
        $userNames = [];
        foreach ($entries as $entry) {
            $uid = $entry['userId'] !== null ? (int) $entry['userId'] : null;
            if ($uid !== null && !isset($userNames[$uid])) {
                $user = Craft::$app->getUsers()->getUserById($uid);
                $userNames[$uid] = $user ? ($user->fullName ?: $user->username) : ('#' . $uid);
            }
        }

        return $this->renderTemplate('simple-form/settings/index', [
            'selectedSettingsSubnavItem' => 'audit',
            'auditEntries' => $entries,
            'auditUserNames' => $userNames,
            'auditAction' => $action,
        ]);
    }
}
