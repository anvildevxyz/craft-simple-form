<?php

namespace fabianhaef\simpleform\services;

use Craft;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;
use GuzzleHttp\Exception\GuzzleException;
use yii\base\Component;

/**
 * Server-side verification of reCAPTCHA responses.
 */
class CaptchaService extends Component
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Body param the frontend submits the captcha token under.
     */
    public const TOKEN_PARAM = 'g-recaptcha-response';

    /**
     * Verify the captcha token on the current request.
     *
     * Returns true when captcha is disabled, so callers can verify unconditionally.
     */
    public function verify(?string $token = null): bool
    {
        $settings = $this->getSettings();

        if (!$settings->enableCaptcha) {
            return true;
        }

        $secret = $settings->getParsedSecretKey();
        if (!$secret) {
            Craft::warning('Captcha is enabled but no secret key is configured.', 'simple-form');
            return false;
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        if ($token === null) {
            $token = (string) $request->getBodyParam(self::TOKEN_PARAM, '');
        }

        if ($token === '') {
            return false;
        }

        try {
            $response = Craft::createGuzzleClient()->post(self::VERIFY_URL, [
                'form_params' => [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->getUserIP(),
                ],
            ]);
            $result = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            Craft::warning('Captcha verification request failed: ' . $e->getMessage(), 'simple-form');
            return false;
        }

        if (!is_array($result) || empty($result['success'])) {
            return false;
        }

        // v3 returns a confidence score; enforce the configured threshold.
        if ($settings->captchaType === Settings::CAPTCHA_V3 && isset($result['score'])) {
            return (float) $result['score'] >= $settings->recaptchaV3MinScore;
        }

        return true;
    }

    private function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
