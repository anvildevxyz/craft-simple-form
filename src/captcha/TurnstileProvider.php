<?php

namespace anvildev\simpleform\captcha;

use anvildev\simpleform\models\Settings;

/**
 * Cloudflare Turnstile captcha provider. The widget injects its own
 * `cf-turnstile-response` field; verification posts to Cloudflare's siteverify.
 */
class TurnstileProvider extends AbstractSiteverifyProvider
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    public const TOKEN_PARAM = 'cf-turnstile-response';

    public static function handle(): string
    {
        return 'turnstile';
    }

    public static function displayName(): string
    {
        return 'Cloudflare Turnstile';
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
        return $settings->turnstileSecretKey;
    }

    protected function siteKey(Settings $settings): ?string
    {
        return $settings->turnstileSiteKey;
    }

    protected function widgetClass(): string
    {
        return 'cf-turnstile';
    }

    protected function scriptUrl(): string
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    }
}
