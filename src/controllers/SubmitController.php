<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\helpers\FieldQueryHelper;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
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

    /**
     * The coupon preview (#246) is a stateless, read-only lookup — it persists
     * nothing and charges nothing — so it is exempt from CSRF (a cached form page
     * can carry a rotated token, which would otherwise 400 the preview). The
     * authoritative discount is still applied at submit, which keeps CSRF, and
     * the preview is rate-limited to discourage code enumeration.
     */
    public function beforeAction($action): bool
    {
        if ($action->id === 'coupon-validate') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        // The bundled front-end script posts via fetch with X-Requested-With
        // (and Accept: application/json); a visitor with JS disabled/failed
        // posts a plain form. The plain POST must round-trip as HTML — flashed
        // message/errors + redirect — never raw JSON in the browser (#287).
        $wantsJson = $request->getAcceptsJson() || $request->getIsAjax();

        $formHandle = (string) $request->getBodyParam('formHandle', '');
        if (empty($formHandle)) {
            if (!$wantsJson) {
                throw new BadRequestHttpException('Form handle is required');
            }
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
            if (!$wantsJson) {
                return $this->htmlErrors($formHandle, [$message]);
            }
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
            if (!$wantsJson) {
                throw new NotFoundHttpException('Form not found');
            }
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
            if (!$wantsJson) {
                return $this->htmlSuccess($formHandle, (string) $plugin->getSettings()->submitMessage);
            }
            return $this->asJson([
                'success' => true,
                'message' => $plugin->getSettings()->submitMessage,
                'redirectUrl' => null,
            ]);
        }

        if (!empty($result['errors'])) {
            if (!$wantsJson) {
                return $this->htmlErrors($formHandle, $this->flattenErrors($form, $result['errors']));
            }
            return $this->asJson([
                'success' => false,
                'errors' => $result['errors'],
            ]);
        }

        // An offsite / 3-D-Secure payment must finish before the submission is
        // "done": send the visitor straight to the gateway redirect, overriding
        // the normal post-submit behavior (#116).
        if (!empty($result['paymentRedirectUrl'])) {
            if (!$wantsJson) {
                return $this->redirect($result['paymentRedirectUrl']);
            }
            return $this->asJson([
                'success' => true,
                'redirectUrl' => $result['paymentRedirectUrl'],
            ]);
        }

        // Resolve the per-form post-submit behavior (message + optional redirect),
        // sharing the exact resolution the GraphQL path uses.
        $post = $submissions->resolvePostSubmit($form, $result['submission'], $result['data'] ?? []);

        if (!$wantsJson) {
            if (!empty($post['redirectUrl'])) {
                return $this->redirect($post['redirectUrl']);
            }
            return $this->htmlSuccess($formHandle, (string) $post['message']);
        }

        return $this->asJson([
            'success' => true,
            'message' => $post['message'],
            'redirectUrl' => $post['redirectUrl'],
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * No-JS success: flash the resolved message under a per-form key (so two
     * forms on one page can't cross-talk) and send the visitor back to the page
     * they posted from. The form render reads and clears the flash.
     */
    private function htmlSuccess(string $formHandle, string $message): Response
    {
        Craft::$app->getSession()->setFlash("simpleForm:success:$formHandle", $message);
        return $this->redirectBack();
    }

    /**
     * No-JS failure: flash the error list per-form and redirect back so the
     * re-rendered form shows them via the errors partial.
     *
     * @param list<string> $errors
     */
    private function htmlErrors(string $formHandle, array $errors): Response
    {
        Craft::$app->getSession()->setFlash("simpleForm:errors:$formHandle", $errors);
        return $this->redirectBack();
    }

    private function redirectBack(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        return $this->redirect($request->getReferrer() ?: Craft::$app->getSites()->getCurrentSite()->getBaseUrl());
    }

    /**
     * Flatten the per-field error map into displayable lines, prefixing each
     * message with the field's label so a top-of-form list stays attributable
     * ("Email: This field is required."). Form-level errors pass through bare.
     *
     * @param array<string, list<string>> $errors field handle => messages
     * @return list<string>
     */
    private function flattenErrors(Form $form, array $errors): array
    {
        $labels = [];
        foreach (FieldQueryHelper::fieldsForForm((int) $form->id, (int) $form->siteId) as $field) {
            $labels[$field['name']] = $field['label'];
        }

        $lines = [];
        foreach ($errors as $handle => $messages) {
            $label = $labels[$handle] ?? null;
            foreach ((array) $messages as $message) {
                $lines[] = ($label !== null && $label !== '')
                    ? sprintf('%s: %s', $label, $message)
                    : (string) $message;
            }
        }
        return $lines;
    }

    /**
     * Public coupon preview (#246): validate a discount code against a form's
     * payment amount and return the resulting discount/total so the front-end can
     * show what the visitor will pay BEFORE submitting. Purely advisory — the
     * authoritative discount is re-resolved server-side at submit. Rate-limited
     * (shared throttle) to discourage code enumeration.
     */
    public function actionCouponValidate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $plugin = Plugin::getInstance();
        if ($plugin->getSubmissionService()->isCouponRateLimited($request->getUserIP())) {
            $this->response->setStatusCode(429);
            return $this->asJson(['success' => false, 'error' => Craft::t('simple-form', 'Too many attempts. Please wait a moment and try again.')]);
        }

        $form = Form::find()
            ->handle((string) $request->getBodyParam('formHandle', ''))
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();
        if (!$form instanceof Form) {
            return $this->asJson(['success' => false, 'error' => Craft::t('simple-form', 'Form not found')]);
        }

        // Fixed amounts resolve server-side; a field-based price isn't known here,
        // so the field posts its current amount as a preview hint (re-validated at
        // submit, so a tampered hint can't cheat the real charge).
        $amount = $plugin->getPayments()->resolveAmount($form, []);
        if ($amount === null) {
            $posted = $request->getBodyParam('amount');
            $amount = is_numeric($posted) ? round((float) $posted, 2) : null;
        }
        if ($amount === null || $amount <= 0) {
            return $this->asJson(['success' => false, 'error' => Craft::t('simple-form', 'There is nothing to discount.')]);
        }

        $eval = $plugin->getCoupons()->evaluate((string) $request->getBodyParam('couponCode', ''), $amount);
        if ($eval['error'] !== null) {
            return $this->asJson(['success' => false, 'error' => $eval['error']]);
        }

        $formatter = Craft::$app->getFormatter();
        $currency = $plugin->getPayments()->primaryCurrencyIso();
        $money = fn(float $v): string => $currency !== null ? $formatter->asCurrency($v, $currency) : $formatter->asDecimal($v, 2);

        return $this->asJson([
            'success' => true,
            'amount' => $amount,
            'discount' => $eval['discount'],
            'total' => $eval['total'],
            'message' => Craft::t('simple-form', 'Coupon applied: {discount} off. You’ll pay {total}.', [
                'discount' => $money($eval['discount']),
                'total' => $money($eval['total']),
            ]),
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
     * partial via {@see \anvildev\simpleform\services\DraftService}. Distinct
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
