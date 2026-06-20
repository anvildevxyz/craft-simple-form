<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

class SubmitController extends Controller
{
    /**
     * Public form submissions come from anonymous site visitors, so the action
     * must be reachable without a logged-in user. Without this the framework's
     * default (ALLOW_ANONYMOUS_NEVER) returns 403 to every guest. CSRF is still
     * enforced below, so this is not an open door.
     *
     * @var array<string, int>|bool|int
     */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = true;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $formHandle = (string) $request->getBodyParam('formHandle', '');
        if (empty($formHandle)) {
            return $this->asJson([
                'success' => false,
                'errors' => ['form' => ['Form handle is required']],
            ]);
        }

        $settings = Plugin::getInstance()->getSettings();

        $form = Form::find()
            ->handle($formHandle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        if (!$form) {
            return $this->asJson([
                'success' => false,
                'errors' => ['form' => ['Form not found']],
            ]);
        }

        // createFromRequest is the upload-aware entry point: it resolves field
        // values (including file uploads → Craft assets), the honeypot, and the
        // userId, then routes through the shared submit() path so validation,
        // spam protection, events, and email all run identically to the GraphQL
        // mutation. Building values here would skip file-field handling.
        $result = Plugin::getInstance()->getSubmissionService()->createFromRequest($form, $request);

        // A silently-dropped honeypot hit returns no submission and no errors:
        // report success so bots get no signal, but never persist the row.
        if ($result['submission'] === null && $result['errors'] === null) {
            return $this->asJson([
                'success' => true,
                'message' => $settings->submitMessage,
            ]);
        }

        if (!empty($result['errors'])) {
            return $this->asJson([
                'success' => false,
                'errors' => $result['errors'],
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => $settings->submitMessage,
        ]);
    }
}
