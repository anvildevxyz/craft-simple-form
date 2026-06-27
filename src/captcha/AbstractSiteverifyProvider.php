<?php

namespace anvildev\simpleform\captcha;

use anvildev\simpleform\models\Settings;
use Craft;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Base for the captcha providers that follow the "siteverify" pattern — a
 * single hidden token posted by a script-injected widget, verified server-side
 * with a `secret`/`response`/`remoteip` POST. hCaptcha and Cloudflare Turnstile
 * share this contract verbatim; subclasses only supply the provider's URLs,
 * setting keys, and widget class. (reCAPTCHA differs enough — v3 scoring,
 * per-context key encoding — that it implements the interface directly.)
 */
abstract class AbstractSiteverifyProvider implements CaptchaProviderInterface
{
    /** The provider's siteverify endpoint. */
    abstract protected function verifyUrl(): string;

    /** The configured secret key (possibly an env reference, possibly unset). */
    abstract protected function secretKey(Settings $settings): ?string;

    /** The configured site key (possibly an env reference, possibly unset). */
    abstract protected function siteKey(Settings $settings): ?string;

    /** The DOM class the provider's script hydrates into a widget. */
    abstract protected function widgetClass(): string;

    /** The provider's frontend api.js URL. */
    abstract protected function scriptUrl(): string;

    public function verify(?string $token, Settings $settings): bool
    {
        $secret = $this->parse($this->secretKey($settings));
        if ($secret === null) {
            Craft::warning(static::displayName() . ' is enabled but no secret key is configured.', 'simple-form');
            return false;
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        if ($token === null) {
            $token = (string) $request->getBodyParam($this->tokenParam(), '');
        }
        if ($token === '') {
            return false;
        }

        try {
            $response = $this->httpClient()->post($this->verifyUrl(), [
                'form_params' => [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->getUserIP(),
                ],
            ]);
            $result = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            Craft::warning(static::displayName() . ' verification request failed: ' . $e->getMessage(), 'simple-form');
            return false;
        }

        return is_array($result) && !empty($result['success']);
    }

    public function renderWidget(Settings $settings): string
    {
        $siteKey = $this->parse($this->siteKey($settings));
        if ($siteKey === null) {
            return '';
        }
        $siteKey = htmlspecialchars($siteKey, ENT_QUOTES);

        return '<div class="simple-form-group">'
            . '<div class="' . $this->widgetClass() . '" data-sitekey="' . $siteKey . '"></div>'
            . '</div>'
            . '<script src="' . $this->scriptUrl() . '" async defer></script>';
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
