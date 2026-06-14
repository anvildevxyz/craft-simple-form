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

    public function actionIndex(): Response
    {
        return $this->renderTemplate('simple-form/settings/index', [
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $values = [
            'defaultEmailSender' => $request->getBodyParam('defaultEmailSender'),
            'defaultEmailSenderName' => $request->getBodyParam('defaultEmailSenderName'),
            'enableHoneypot' => (bool) $request->getBodyParam('enableHoneypot'),
            'enableCaptcha' => (bool) $request->getBodyParam('enableCaptcha'),
            'captchaType' => $request->getBodyParam('captchaType', 'recaptcha-v3'),
            'recaptchaV3MinScore' => (float) $request->getBodyParam('recaptchaV3MinScore', 0.5),
            'recaptchaV3SiteKey' => $request->getBodyParam('recaptchaV3SiteKey'),
            'recaptchaV3SecretKey' => $request->getBodyParam('recaptchaV3SecretKey'),
            'recaptchaV2SiteKey' => $request->getBodyParam('recaptchaV2SiteKey'),
            'recaptchaV2SecretKey' => $request->getBodyParam('recaptchaV2SecretKey'),
            'storageLocation' => $request->getBodyParam('storageLocation', 'database'),
            'submitMessage' => $request->getBodyParam('submitMessage', 'Thank you! Your submission has been received.'),
            'errorMessage' => $request->getBodyParam('errorMessage', 'There was an error submitting your form. Please try again.'),
        ];

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            $settings = $plugin->getSettings();
            $firstErrors = $settings->getFirstErrors();
            $error = $firstErrors
                ? reset($firstErrors)
                : Craft::t('simple-form', 'Couldn’t save settings.');
            Craft::$app->getSession()->setError($error);

            // Re-render the index with the invalid model so errors show inline.
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
            ]);
            return $this->actionIndex();
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Settings saved.'));
        return $this->redirect('simple-form/settings');
    }
}
