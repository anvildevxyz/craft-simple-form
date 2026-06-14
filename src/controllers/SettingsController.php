<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_SETTINGS;

    /** Settings fields grouped by tab. Drives both rendering and the per-tab save. */
    private const TAB_FIELDS = [
        'general' => ['submitMessage', 'errorMessage', 'storageLocation'],
        'email' => ['defaultEmailSender', 'defaultEmailSenderName'],
        'spam' => [
            'enableHoneypot',
            'enableCaptcha',
            'captchaType',
            'recaptchaV3MinScore',
            'recaptchaV3SiteKey',
            'recaptchaV3SecretKey',
            'recaptchaV2SiteKey',
            'recaptchaV2SecretKey',
        ],
    ];

    private const BOOL_FIELDS = ['enableHoneypot', 'enableCaptcha'];
    private const FLOAT_FIELDS = ['recaptchaV3MinScore'];

    public function actionIndex(): Response
    {
        return $this->renderTab('general');
    }

    public function actionSection(string $tab): Response
    {
        return $this->renderTab($this->normalizeTab($tab));
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $tab = $this->normalizeTab($request->getBodyParam('tab'));

        // Start from the existing values so saving one tab never wipes another
        // tab's fields (e.g. the required defaultEmailSender on the Email tab).
        $values = $settings->getAttributes();

        foreach (self::TAB_FIELDS[$tab] as $field) {
            if (in_array($field, self::BOOL_FIELDS, true)) {
                $values[$field] = (bool) $request->getBodyParam($field);
            } elseif (in_array($field, self::FLOAT_FIELDS, true)) {
                $values[$field] = (float) $request->getBodyParam($field, $values[$field] ?? 0.5);
            } else {
                $values[$field] = $request->getBodyParam($field, $values[$field] ?? null);
            }
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            $settings = $plugin->getSettings();
            $firstErrors = $settings->getFirstErrors();
            $error = $firstErrors
                ? reset($firstErrors)
                : Craft::t('simple-form', 'Couldn’t save settings.');
            Craft::$app->getSession()->setError($error);

            // Re-render the same tab with the invalid model so errors show inline.
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
                'selectedSettingsSubnavItem' => $tab,
            ]);
            return $this->renderTab($tab);
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Settings saved.'));
        return $this->redirect('simple-form/settings/' . $tab);
    }

    private function renderTab(string $tab): Response
    {
        return $this->renderTemplate('simple-form/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
            'selectedSettingsSubnavItem' => $tab,
        ]);
    }

    private function normalizeTab(string|int|float|bool|null $raw): string
    {
        $tab = strtolower(trim((string) $raw));
        return isset(self::TAB_FIELDS[$tab]) ? $tab : 'general';
    }
}
