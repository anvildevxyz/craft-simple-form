<?php

namespace fabianhaef\simpleform\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;

/**
 * Simple Form plugin settings.
 *
 * Stored in project config under `plugins.simple-form.settings`. Secret/key
 * values support env-variable references (e.g. `$RECAPTCHA_SECRET`) so they can
 * live in `.env` rather than as plaintext in project config; use the parsed
 * getters when consuming them.
 */
class Settings extends Model
{
    public const CAPTCHA_V3 = 'recaptcha-v3';
    public const CAPTCHA_V2 = 'recaptcha-v2';

    public ?string $defaultEmailSender = null;
    public ?string $defaultEmailSenderName = null;
    public bool $enableHoneypot = true;
    public bool $enableCaptcha = false;
    public string $captchaType = self::CAPTCHA_V3;
    public ?string $recaptchaV3SiteKey = null;
    public ?string $recaptchaV3SecretKey = null;
    public ?string $recaptchaV2SiteKey = null;
    public ?string $recaptchaV2SecretKey = null;
    public string $storageLocation = 'database';
    public string $submitMessage = 'Thank you! Your submission has been received.';
    public string $errorMessage = 'There was an error submitting your form. Please try again.';

    /**
     * Whether the resolved form structure (decoded field config + per-site
     * labels/help text) is cached via Craft's cache component and reused across
     * renders until the form is saved/deleted.
     *
     * Caching is always bypassed when `devMode` is on or Craft's cache is a
     * dummy/disabled cache, so local development never serves stale structure.
     * Set this to false to force a fresh DB read on every render regardless of
     * environment.
     */
    public bool $cacheFormStructure = true;

    /**
     * Whether the form's CSS/JS are delivered inline in the rendered markup
     * instead of via a registered, cache-bustable Craft asset bundle.
     *
     * Defaults to false (bundle). Set to true as an escape hatch for
     * environments that need fully self-contained inline output (e.g. email
     * previews or pages rendered outside the normal web request/asset pipeline).
     */
    public bool $inlineFormAssets = false;

    /**
     * Minimum score (0.0–1.0) a reCAPTCHA v3 response must meet to pass.
     */
    public float $recaptchaV3MinScore = 0.5;

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'defaultEmailSender',
                    'recaptchaV3SiteKey',
                    'recaptchaV3SecretKey',
                    'recaptchaV2SiteKey',
                    'recaptchaV2SecretKey',
                ],
            ],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return [
            [['defaultEmailSender'], 'required'],
            // Skip the email format check for env references (`$VAR`), which only
            // resolve at runtime.
            [
                ['defaultEmailSender'],
                'email',
                'when' => fn(): bool => !$this->isEnvReference($this->defaultEmailSender),
            ],
            [['enableHoneypot', 'enableCaptcha', 'cacheFormStructure', 'inlineFormAssets'], 'boolean'],
            [['captchaType'], 'in', 'range' => [self::CAPTCHA_V3, self::CAPTCHA_V2]],
            [['storageLocation'], 'in', 'range' => ['database']],
            [['recaptchaV3MinScore'], 'number', 'min' => 0, 'max' => 1],
            [
                ['recaptchaV3SiteKey', 'recaptchaV3SecretKey'],
                'required',
                'when' => fn(): bool => $this->enableCaptcha && $this->captchaType === self::CAPTCHA_V3,
            ],
            [
                ['recaptchaV2SiteKey', 'recaptchaV2SecretKey'],
                'required',
                'when' => fn(): bool => $this->enableCaptcha && $this->captchaType === self::CAPTCHA_V2,
            ],
        ];
    }

    /**
     * Parsed "from" email for outgoing notifications, or null if unset.
     */
    public function getSenderEmail(): ?string
    {
        return $this->parseValue($this->defaultEmailSender);
    }

    /**
     * Parsed "from" name for outgoing notifications, or null if unset.
     */
    public function getSenderName(): ?string
    {
        return $this->parseValue($this->defaultEmailSenderName);
    }

    /**
     * The raw (unparsed) site key for the active captcha type.
     */
    public function getActiveSiteKey(): ?string
    {
        return $this->captchaType === self::CAPTCHA_V2
            ? $this->recaptchaV2SiteKey
            : $this->recaptchaV3SiteKey;
    }

    /**
     * The raw (unparsed) secret key for the active captcha type.
     */
    public function getActiveSecretKey(): ?string
    {
        return $this->captchaType === self::CAPTCHA_V2
            ? $this->recaptchaV2SecretKey
            : $this->recaptchaV3SecretKey;
    }

    /**
     * The parsed secret key for the active captcha type, for server-side verification.
     */
    public function getParsedSecretKey(): ?string
    {
        return $this->parseValue($this->getActiveSecretKey());
    }

    private function parseValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = App::parseEnv($value);

        // parseEnv can return a bool for boolean env references; only string
        // values are meaningful here.
        return is_string($parsed) && $parsed !== '' ? $parsed : null;
    }

    private function isEnvReference(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, '$');
    }
}
