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

        $plugin = Plugin::getInstance();
        $submissions = $plugin->getSubmissionService();

        // Abuse throttle (shared with the GraphQL submit path). Over the limit
        // returns 429 with the standard error envelope; 0 disables it.
        if ($submissions->isRateLimited($request->getUserIP())) {
            $message = Craft::t('simple-form', 'Too many submissions. Please wait a moment and try again.');
            $this->response->setStatusCode(429);
            return $this->asJson([
                'success' => false,
                'message' => $message,
                'errors' => ['form' => [$message]],
            ]);
        }

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
        $result = $submissions->createFromRequest($form, $request);

        // A silently-dropped honeypot hit returns no submission and no errors:
        // report success so bots get no signal, but never persist the row. No row
        // means no per-form resolution, so fall back to the global message.
        if ($result['submission'] === null && $result['errors'] === null) {
            return $this->asJson([
                'success' => true,
                'message' => $plugin->getSettings()->submitMessage,
                'redirectUrl' => null,
            ]);
        }

        if (!empty($result['errors'])) {
            return $this->asJson([
                'success' => false,
                'errors' => $result['errors'],
            ]);
        }

        // An offsite / 3-D-Secure payment must finish before the submission is
        // "done": send the visitor straight to the gateway redirect, overriding
        // the normal post-submit behavior (#116).
        if (!empty($result['paymentRedirectUrl'])) {
            return $this->asJson([
                'success' => true,
                'redirectUrl' => $result['paymentRedirectUrl'],
            ]);
        }

        // Resolve the per-form post-submit behavior (message + optional redirect),
        // sharing the exact resolution the GraphQL path uses.
        $post = $submissions->resolvePostSubmit($form, $result['submission'], $result['data'] ?? []);

        return $this->asJson([
            'success' => true,
            'message' => $post['message'],
            'redirectUrl' => $post['redirectUrl'],
        ]);
    }

    /**
     * Save a partial submission (save-&-resume) and return a resume token. Only
     * works for forms that opted into drafts. File fields are not drafted; every
     * other `field_*` value entered so far is stored. Passing an existing token
     * updates that draft in place so the resume URL stays stable.
     */
    public function actionSaveDraft(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = Form::find()
            ->handle((string) $request->getBodyParam('formHandle', ''))
            ->siteId($siteId)
            ->one();

        if (!$form instanceof Form || !$form->allowSaveResume) {
            return $this->asJson(['success' => false]);
        }

        $plugin = Plugin::getInstance();
        if ($plugin->getSubmissionService()->isRateLimited($request->getUserIP())) {
            $this->response->setStatusCode(429);

            return $this->asJson(['success' => false]);
        }

        $values = array_filter(
            $request->getBodyParams(),
            static fn($key) => is_string($key) && str_starts_with($key, 'field_'),
            ARRAY_FILTER_USE_KEY,
        );

        $existingToken = (string) $request->getBodyParam('sfresume', '');
        $token = $plugin->getDrafts()->save(
            (int) $form->id,
            $siteId,
            $values,
            $existingToken !== '' ? $existingToken : null,
        );

        return $this->asJson(['success' => true, 'token' => $token]);
    }

    /**
     * Passive partial capture (#242): the front end posts the values entered so
     * far (debounced, on blur / step change) and they are stored as a passive
     * partial via {@see \fabianhaef\simpleform\services\DraftService}. Distinct
     * from save-and-continue: never surfaced to the visitor, fires no
     * notifications/integrations/payments/spam, and is best-effort — any failure
     * returns a quiet `success:false` rather than an error, so a capture problem
     * never blocks or disrupts the public form.
     */
    public function actionCapture(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        try {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;

            $form = Form::find()
                ->handle((string) $request->getBodyParam('formHandle', ''))
                ->siteId($siteId)
                ->one();

            if (!$form instanceof Form || !$form->capturePartials) {
                return $this->asJson(['success' => false]);
            }

            $plugin = Plugin::getInstance();
            if ($plugin->getSubmissionService()->isRateLimited($request->getUserIP())) {
                return $this->asJson(['success' => false]);
            }

            $values = array_filter(
                $request->getBodyParams(),
                static fn($key) => is_string($key) && str_starts_with($key, 'field_'),
                ARRAY_FILTER_USE_KEY,
            );
            if ($values === []) {
                return $this->asJson(['success' => false]);
            }

            $existingToken = (string) $request->getBodyParam('partialToken', '');
            // capturePartial applies the consent gate (#244) and fires the
            // capture event; a blocked/empty capture returns null → quiet no-op.
            $token = $plugin->getDrafts()->capturePartial(
                $form,
                $values,
                $siteId,
                $existingToken !== '' ? $existingToken : null,
            );

            if ($token === null) {
                return $this->asJson(['success' => false]);
            }

            return $this->asJson(['success' => true, 'token' => $token]);
        } catch (\Throwable $e) {
            // Best-effort: never surface a capture failure to the visitor.
            Craft::warning('Partial capture failed: ' . $e->getMessage(), 'simple-form');
            return $this->asJson(['success' => false]);
        }
    }
}
