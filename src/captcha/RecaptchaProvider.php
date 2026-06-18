<?php

namespace fabianhaef\simpleform\captcha;

use Craft;
use craft\helpers\App;
use fabianhaef\simpleform\models\Settings;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Google reCAPTCHA provider (v2 checkbox + v3 invisible). This is the default
 * provider; its behaviour is the plugin's original reCAPTCHA implementation,
 * lifted verbatim out of CaptchaService/TwigExtension so the abstraction is a
 * pure refactor.
 */
class RecaptchaProvider implements CaptchaProviderInterface
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    public const TOKEN_PARAM = 'g-recaptcha-response';

    public static function handle(): string
    {
        return 'recaptcha';
    }

    public static function displayName(): string
    {
        return 'Google reCAPTCHA';
    }

    public function tokenParam(): string
    {
        return self::TOKEN_PARAM;
    }

    public function verify(?string $token, Settings $settings): bool
    {
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

    public function renderWidget(Settings $settings): string
    {
        $siteKey = $settings->getActiveSiteKey();
        if (!$siteKey) {
            return '';
        }
        // Site keys may be stored as env references; resolve before output.
        $siteKey = App::parseEnv($siteKey);
        if (!$siteKey) {
            return '';
        }
        $siteKey = htmlspecialchars((string) $siteKey, ENT_QUOTES);

        if ($settings->captchaType === Settings::CAPTCHA_V2) {
            // The v2 widget injects its own `g-recaptcha-response` field on submit.
            return '<div class="simple-form-group">'
                . '<div class="g-recaptcha" data-sitekey="' . $siteKey . '"></div>'
                . '</div>'
                . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
        }

        // v3 is invisible: keep a fresh token in a hidden field that rides along
        // with the form's existing fetch submit.
        return '<input type="hidden" name="g-recaptcha-response" value="">'
            . '<script src="https://www.google.com/recaptcha/api.js?render=' . $siteKey . '"></script>'
            . '<script>
                (function() {
                    var siteKey = "' . $siteKey . '";
                    function refreshToken() {
                        if (typeof grecaptcha === "undefined") { return; }
                        grecaptcha.ready(function() {
                            grecaptcha.execute(siteKey, { action: "submit" }).then(function(token) {
                                document.querySelectorAll("input[name=\'g-recaptcha-response\']").forEach(function(input) {
                                    input.value = token;
                                });
                            });
                        });
                    }
                    refreshToken();
                    // Tokens expire after ~2 minutes; refresh well before that.
                    setInterval(refreshToken, 90000);
                })();
            </script>';
    }
}
