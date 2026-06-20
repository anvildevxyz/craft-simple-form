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
    /**
     * The selected captcha provider handle (see CaptchaProviderRegistry).
     * Defaults to reCAPTCHA so existing installs behave identically.
     */
    public string $selectedCaptchaProvider = 'recaptcha';
    public string $captchaType = self::CAPTCHA_V3;
    public ?string $recaptchaV3SiteKey = null;
    public ?string $recaptchaV3SecretKey = null;
    public ?string $recaptchaV2SiteKey = null;
    public ?string $recaptchaV2SecretKey = null;
    public ?string $turnstileSiteKey = null;
    public ?string $turnstileSecretKey = null;
    public ?string $hcaptchaSiteKey = null;
    public ?string $hcaptchaSecretKey = null;

    /**
     * Whether GraphQL `submitForm` mutations bypass captcha (F8). Off by default
     * so a leaked/public GraphQL token cannot submit at machine speed when
     * captcha is enabled — headless clients should pass a `captchaToken` arg
     * instead. Turn on only for trusted server-to-server callers that cannot
     * obtain a browser captcha token (ideally paired with a scoped token).
     */
    public bool $allowGraphqlCaptchaBypass = false;

    public const AKISMET_FLAG = 'flag';
    public const AKISMET_BLOCK = 'block';

    /** Content spam scoring via Akismet (complements captcha). */
    public bool $enableAkismet = false;
    public ?string $akismetApiKey = null;
    /** What to do with a spam verdict: flag (save as spam) or block (drop). */
    public string $akismetMode = self::AKISMET_FLAG;
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
     * Whether outbound integrations run inline during the submission request
     * instead of being pushed to the queue.
     *
     * Defaults to false (queue): a slow or failing third party must never block
     * or fail the visitor's submission. Set to true only for local/dev debugging
     * where you want the dispatch (and its errors) to surface synchronously.
     */
    public bool $dispatchIntegrationsSynchronously = false;

    /**
     * Minimum score (0.0–1.0) a reCAPTCHA v3 response must meet to pass.
     */
    public float $recaptchaV3MinScore = 0.5;

    /**
     * Whether the MCP (Model Context Protocol) server endpoint is enabled.
     *
     * OFF BY DEFAULT (security): the endpoint at `simple-form/mcp` returns 404
     * and processes nothing until an operator explicitly opts in. This is a
     * remotely-reachable, token-authenticated API surface, so it must never be
     * live without a deliberate decision.
     */
    public bool $enableMcp = false;

    /**
     * Data retention. Submissions (and integration dispatch logs) older than the
     * configured number of days are pruned on Craft's garbage-collection run.
     * 0 = keep forever.
     */
    public int $retainSubmissionsDays = 0;
    public int $retainIntegrationLogsDays = 90;
    public int $retainAuditLogDays = 365;

    /**
     * When pruning submissions, scrub their data + user reference in place instead
     * of deleting the row, so aggregate counts/stats survive while PII does not.
     */
    public bool $anonymizeInsteadOfDelete = false;

    /**
     * Configured MCP access tokens, stored as hash-only arrays (see
     * {@see \fabianhaef\simpleform\mcp\McpToken}). The plaintext secret is NEVER
     * stored here — only its keyed hash. Shape per entry:
     * {id, label, hash, scopes[], dateCreated, lastUsed}.
     *
     * @var array<int, array{id?:string,label?:string,hash?:string,scopes?:list<string>,dateCreated?:?string,lastUsed?:?string}>
     */
    public array $mcpTokens = [];

    /**
     * @return array<string, array{class: class-string, attributes: list<string>}>
     */
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
                    'turnstileSiteKey',
                    'turnstileSecretKey',
                    'hcaptchaSiteKey',
                    'hcaptchaSecretKey',
                    'akismetApiKey',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<array-key, mixed>|\yii\validators\Validator>
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
            [['enableHoneypot', 'enableCaptcha', 'cacheFormStructure', 'inlineFormAssets', 'enableMcp', 'dispatchIntegrationsSynchronously', 'enableAkismet', 'anonymizeInsteadOfDelete', 'allowGraphqlCaptchaBypass'], 'boolean'],
            [['retainSubmissionsDays', 'retainIntegrationLogsDays', 'retainAuditLogDays'], 'integer', 'min' => 0],
            [['akismetMode'], 'in', 'range' => [self::AKISMET_FLAG, self::AKISMET_BLOCK]],
            [['akismetApiKey'], 'required', 'when' => fn(): bool => $this->enableAkismet],
            [['mcpTokens'], 'safe'],
            [['captchaType'], 'in', 'range' => [self::CAPTCHA_V3, self::CAPTCHA_V2]],
            [['selectedCaptchaProvider'], 'string'],
            [['storageLocation'], 'in', 'range' => ['database']],
            [['recaptchaV3MinScore'], 'number', 'min' => 0, 'max' => 1],
            [
                ['recaptchaV3SiteKey', 'recaptchaV3SecretKey'],
                'required',
                'when' => fn(): bool => $this->enableCaptcha
                    && $this->selectedCaptchaProvider === 'recaptcha'
                    && $this->captchaType === self::CAPTCHA_V3,
            ],
            [
                ['recaptchaV2SiteKey', 'recaptchaV2SecretKey'],
                'required',
                'when' => fn(): bool => $this->enableCaptcha
                    && $this->selectedCaptchaProvider === 'recaptcha'
                    && $this->captchaType === self::CAPTCHA_V2,
            ],
            [
                ['turnstileSiteKey', 'turnstileSecretKey'],
                'required',
                'when' => fn(): bool => $this->enableCaptcha && $this->selectedCaptchaProvider === 'turnstile',
            ],
            [
                ['hcaptchaSiteKey', 'hcaptchaSecretKey'],
                'required',
                'when' => fn(): bool => $this->enableCaptcha && $this->selectedCaptchaProvider === 'hcaptcha',
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
