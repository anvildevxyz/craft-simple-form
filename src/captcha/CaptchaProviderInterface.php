<?php

namespace anvildev\simpleform\captcha;

use anvildev\simpleform\models\Settings;

/**
 * A captcha provider: renders the frontend widget and verifies the response
 * server-side. Implementations let alternative captchas (Turnstile, hCaptcha,
 * …) slot in without touching the submit path or the form renderer — the
 * {@see \anvildev\simpleform\services\CaptchaService} and Twig renderer
 * delegate to the selected provider.
 */
interface CaptchaProviderInterface
{
    /** Stable machine handle stored in the `selectedCaptchaProvider` setting. */
    public static function handle(): string;

    /** Human-readable name for the settings picker. */
    public static function displayName(): string;

    /** The POST body param the widget submits the token under. */
    public function tokenParam(): string;

    /** Frontend widget markup, or '' when unconfigured. */
    public function renderWidget(Settings $settings): string;

    /**
     * Verify the response token. `$token` defaults to the request's
     * {@see tokenParam()} body param when null. Returns true only on a verified
     * human response.
     */
    public function verify(?string $token, Settings $settings): bool;
}
