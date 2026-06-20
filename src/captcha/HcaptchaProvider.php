<?php

namespace fabianhaef\simpleform\captcha;

use fabianhaef\simpleform\models\Settings;

/**
 * hCaptcha provider. The widget injects its own `h-captcha-response` field;
 * verification posts to hCaptcha's siteverify.
 */
class HcaptchaProvider extends AbstractSiteverifyProvider
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

    protected function verifyUrl(): string
    {
        return self::VERIFY_URL;
    }

    protected function secretKey(Settings $settings): ?string
    {
        return $settings->hcaptchaSecretKey;
    }

    protected function siteKey(Settings $settings): ?string
    {
        return $settings->hcaptchaSiteKey;
    }

    protected function widgetClass(): string
    {
        return 'h-captcha';
    }

    protected function scriptUrl(): string
    {
        return 'https://js.hcaptcha.com/1/api.js';
    }
}
