<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

class SubmitController extends Controller
{
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

        // Route through the shared, transport-agnostic submit path so validation,
        // spam protection, the before/after events, and email all run identically
        // to the GraphQL mutation.
        $userId = Craft::$app->getUser()->getId();
        $result = Plugin::getInstance()->getSubmissionService()->submit($form, $this->fieldValues($request), [
            'honeypot' => (string) $request->getBodyParam('__honeypot', ''),
            'captchaToken' => null,
            'userId' => $userId !== null ? (int) $userId : null,
        ]);

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

    /**
     * Collect the posted field values keyed by `field_<id>` from the request.
     *
     * @return array<string, mixed>
     */
    private function fieldValues(\craft\web\Request $request): array
    {
        $values = [];
        $body = $request->getBodyParams();
        foreach ($body as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'field_')) {
                $values[$key] = $value;
            }
        }
        return $values;
    }
}
