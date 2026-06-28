<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\models\CouponModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP management of payment coupons (#246): list, add/edit/delete, and an
 * enable toggle, rendered as the Settings → Coupons tab. Gated by manageSettings.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class CouponsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_SETTINGS;

    private function service(): \anvildev\simpleform\services\CouponsService
    {
        return Plugin::getInstance()->getCoupons();
    }

    /**
     * Coupon list, rendered as the Settings → Coupons tab.
     */
    public function actionSettingsIndex(): Response
    {
        $payments = Plugin::getInstance()->getPayments();

        return $this->renderTemplate('simple-form/settings/index', [
            'selectedSettingsSubnavItem' => 'coupons',
            'coupons' => $this->service()->getAll(),
            'commerceAvailable' => $payments->commerceAvailable(),
            'currency' => $payments->primaryCurrencyIso(),
        ]);
    }

    /**
     * Create/edit a coupon (Settings → Coupons → New / a row).
     */
    public function actionEdit(?int $couponId = null): Response
    {
        $coupon = null;
        if ($couponId !== null && ($coupon = $this->service()->getById($couponId)) === null) {
            throw new NotFoundHttpException('Coupon not found');
        }
        $coupon ??= new CouponModel();

        return $this->renderTemplate('simple-form/settings/coupons/edit', [
            'coupon' => $coupon,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $couponId = $request->getBodyParam('couponId');
        $coupon = null;
        if ($couponId && ($coupon = $this->service()->getById((int) $couponId)) === null) {
            throw new NotFoundHttpException('Coupon not found');
        }
        $coupon ??= new CouponModel();

        $coupon->code = (string) $request->getBodyParam('code', '');
        $coupon->type = (string) $request->getBodyParam('type', CouponModel::TYPE_FIXED);
        $coupon->amount = (float) $request->getBodyParam('amount', 0);
        $coupon->maxUsages = $this->intOrNull($request->getBodyParam('maxUsages'));
        $coupon->enabled = (bool) $request->getBodyParam('enabled', true);

        $expiry = $request->getBodyParam('expiryDate');
        $coupon->expiryDate = !empty($expiry) ? DateTimeHelper::toDateTime($expiry) ?: null : null;

        if (!$this->service()->save($coupon)) {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t save coupon.'));
            Craft::$app->getUrlManager()->setRouteParams(['coupon' => $coupon]);
            return $this->renderTemplate('simple-form/settings/coupons/edit', [
                'coupon' => $coupon,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Coupon saved.'));
        return $this->redirect('simple-form/settings/coupons');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        if (!$this->service()->delete((int) $request->getRequiredBodyParam('couponId'))) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t complete that action.'));
        }

        return $this->asJsonSuccess();
    }

    /**
     * Toggle a coupon's enabled flag (the Settings list).
     */
    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $coupon = $this->service()->getById((int) $request->getRequiredBodyParam('couponId'));
        if ($coupon === null) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t complete that action.'));
        }

        $coupon->enabled = !$coupon->enabled;
        $this->service()->save($coupon);

        return $this->asJsonSuccess(['enabled' => $coupon->enabled]);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
