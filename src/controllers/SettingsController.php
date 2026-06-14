<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use yii\web\Response;

class SettingsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_SETTINGS;
    public function actionIndex(): Response
    {
        $settings = Craft::$app->getProjectConfig()->get('plugins.simple-form') ?? [];

        return $this->renderTemplate('simple-form/settings/index', [
            'settings' => $settings,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $settings = [
            'defaultEmailSender' => $request->getBodyParam('defaultEmailSender'),
            'defaultEmailSenderName' => $request->getBodyParam('defaultEmailSenderName'),
            'enableHoneypot' => (bool) $request->getBodyParam('enableHoneypot'),
            'enableCaptcha' => (bool) $request->getBodyParam('enableCaptcha'),
            'captchaType' => $request->getBodyParam('captchaType', 'recaptcha-v3'),
            'recaptchaV3SiteKey' => $request->getBodyParam('recaptchaV3SiteKey'),
            'recaptchaV3SecretKey' => $request->getBodyParam('recaptchaV3SecretKey'),
            'recaptchaV2SiteKey' => $request->getBodyParam('recaptchaV2SiteKey'),
            'recaptchaV2SecretKey' => $request->getBodyParam('recaptchaV2SecretKey'),
            'storageLocation' => $request->getBodyParam('storageLocation', 'database'),
            'submitMessage' => $request->getBodyParam('submitMessage', 'Thank you! Your submission has been received.'),
            'errorMessage' => $request->getBodyParam('errorMessage', 'There was an error submitting your form. Please try again.'),
        ];

        // Validate settings
        if (empty($settings['defaultEmailSender'])) {
            Craft::$app->getSession()->setError('Default email sender is required');
            return $this->redirect($request->getReferrer() ?? 'simple-form/settings');
        }

        // Save to project config
        Craft::$app->getProjectConfig()->set('plugins.simple-form', $settings);

        Craft::$app->getSession()->setNotice('Settings saved successfully');
        return $this->redirect('simple-form/settings');
    }
}
