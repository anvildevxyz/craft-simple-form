<?php

namespace fabianhaef\simpleform\captcha;

use Craft;
use craft\helpers\App;
use fabianhaef\simpleform\models\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * hCaptcha provider. The widget injects its own `h-captcha-response` field;
 * verification posts to hCaptcha's siteverify.
 */
class HcaptchaProvider implements CaptchaProviderInterface
{
    public const VERIFY_URL = 'https://hcaptcha.com/siteverify';
    public const TOKEN_PARAM = 'h-captcha-response';

    public static function handle(): string
    {
        return 'hcaptcha';
    }

    public static function displayName(): string
    {
        return 'hCaptcha';
    }

    public function tokenParam(): string
    {
        return self::TOKEN_PARAM;
    }

    public function verify(?string $token, Settings $settings): bool
    {
        $secret = $this->parse($settings->hcaptchaSecretKey);
        if ($secret === null) {
            Craft::warning('hCaptcha is enabled but no secret key is configured.', 'simple-form');
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
            $response = $this->httpClient()->post(self::VERIFY_URL, [
                'form_params' => [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->getUserIP(),
                ],
            ]);
            $result = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            Craft::warning('hCaptcha verification request failed: ' . $e->getMessage(), 'simple-form');
            return false;
        }

        return is_array($result) && !empty($result['success']);
    }

    public function renderWidget(Settings $settings): string
    {
        $siteKey = $this->parse($settings->hcaptchaSiteKey);
        if ($siteKey === null) {
            return '';
        }
        $siteKey = htmlspecialchars($siteKey, ENT_QUOTES);

        return '<div class="simple-form-group">'
            . '<div class="h-captcha" data-sitekey="' . $siteKey . '"></div>'
            . '</div>'
            . '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>';
    }

    private function parse(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = App::parseEnv($value);
        return (is_string($parsed) && $parsed !== '') ? $parsed : null;
    }

    protected function httpClient(): Client
    {
        return Craft::createGuzzleClient();
    }
}
