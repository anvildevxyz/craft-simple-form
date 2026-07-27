<?php

namespace anvildev\simpleform\models;

use anvildev\simpleform\helpers\IpHelper;
use Craft;
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
 *
 * @phpstan-import-type TokenArray from \anvildev\simpleform\mcp\McpToken
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

    public const DENYLIST_FLAG = 'flag';
    public const DENYLIST_BLOCK = 'block';

    public const DUPLICATE_FLAG = 'flag';
    public const DUPLICATE_BLOCK = 'block';

    /**
     * IP capture policy (#315). `full` stores the visitor's IP verbatim,
     * `anonymized` stores a masked IP (last octet / low 80 bits zeroed) applied
     * at capture time, and `off` stores nothing. Supersedes the legacy
     * {@see $collectIpAddresses} boolean, which is kept in lockstep for
     * backward compatibility.
     */
    public const IP_CAPTURE_FULL = 'full';
    public const IP_CAPTURE_ANONYMIZED = 'anonymized';
    public const IP_CAPTURE_OFF = 'off';

    /**
     * Deterministic, owner-controlled denylists (#140) that run before Akismet:
     * blocked keywords, emails/domains, and IPs/CIDR ranges. Off by default so
     * existing installs are unchanged. A hit either flags the submission as spam
     * for review (flag, the default) or silently drops it (block), mirroring the
     * Akismet flag/block fork.
     */
    public bool $enableDenylists = false;
    public string $denylistMode = self::DENYLIST_FLAG;

    /** Newline-separated keywords; '*' wildcard. Matched case-insensitively against text values. */
    public ?string $blockedKeywords = null;
    /** Newline-separated emails, '@domain.tld', or '*.domain.tld'. */
    public ?string $blockedEmails = null;
    /** Newline-separated single IPs or CIDR ranges (v4/v6). */
    public ?string $blockedIps = null;

    /**
     * What to do when a per-form duplicate-prevention hit is detected (#140):
     * flag the submission as spam for review (flag, the default) or silently drop
     * it (block). Independent of {@see self::$denylistMode}/{@see self::$akismetMode}
     * so duplicate handling can be configured on its own — the per-form toggle
     * lives on each {@see \anvildev\simpleform\elements\Form::$preventDuplicates}.
     */
    public string $duplicateMode = self::DUPLICATE_FLAG;

    /**
     * Max public form submissions accepted per visitor IP per minute, an abuse
     * throttle shared by the front-end submit endpoint and the GraphQL submit
     * mutation. The limit is per IP across all forms (not per form). 0 disables
     * it entirely.
     *
     * Defaults to 10 so the endpoint is throttled out of the box (CWE-770): with
     * no limit and captcha off by default, the only stock defense was the
     * honeypot, which a scripted attacker simply omits — leaving an
     * unauthenticated flood that amplifies into DB rows, queued emails, outbound
     * integration calls and Akismet lookups. 10/min/IP is well above real human
     * use. An operator can still set 0 to opt out.
     */
    public int $submitRateLimitPerMinute = 10;

    /** Content spam scoring via Akismet (complements captcha). */
    public bool $enableAkismet = false;
    public ?string $akismetApiKey = null;
    /** What to do with a spam verdict: flag (save as spam) or block (drop). */
    public string $akismetMode = self::AKISMET_FLAG;
    /**
     * Global default render-template path (#137): a site-templates directory of
     * Twig partials (e.g. `_simple-form`) overriding the plugin's built-in form
     * markup for every form. A per-form {@see \anvildev\simpleform\elements\Form::$templatePath}
     * takes precedence; an empty value (the default) uses the plugin's built-in
     * partials. Resolution is per partial — a theme may ship only `field.twig`
     * and fall through to the built-in `form.twig`.
     */
    public ?string $templatePath = null;
    public string $storageLocation = 'database';

    /**
     * When true, `craft up` automatically runs `simple-form/forms/apply` after it
     * finishes — so code-defined forms in `config/simple-form/forms/*.json` deploy
     * with the rest of the project. Off by default (opt-in); apply is always
     * available to run manually. Never prunes on the automatic run.
     */
    public bool $applyFormsConfigOnUp = false;
    public string $submitMessage = 'Thank you! Your submission has been received.';
    public string $errorMessage = 'There was an error submitting your form. Please try again.';

    /**
     * Site path where the front-end edit page lives (the template that renders
     * `craft.simpleForm.editForm(...)`), e.g. `forms/edit-submission`. Used by
     * `craft.simpleForm.editUrl(submission)` to build the tokenized edit link an
     * autoresponder/email embeds. Empty = no default; pass a path explicitly to
     * `editUrl(submission, path)` instead. The submission id (`id`) and token
     * (`t`) are appended as query params.
     */
    public string $editPath = '';

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
    /**
     * GDPR data minimization (#293): when off, the visitor's IP is never
     * stored on submissions. The transient rate limiter still reads the
     * request IP (nothing persisted), and IP-based duplicate detection
     * degrades to the other dedupe keys.
     */
    public bool $collectIpAddresses = true;

    /**
     * Three-state IP capture policy (#315): one of {@see IP_CAPTURE_FULL},
     * {@see IP_CAPTURE_ANONYMIZED}, or {@see IP_CAPTURE_OFF}. Null means "not
     * yet set" — {@see init()} derives it from the legacy
     * {@see $collectIpAddresses} boolean so pre-#315 installs behave unchanged
     * (on → full, off → off). After init this is always a concrete value.
     */
    public ?string $ipCapturePolicy = null;

    public int $retainSubmissionsDays = 0;

    /**
     * How long flagged spam submissions (`readStatus = 'spam'`) are kept before
     * garbage collection prunes them (#338). Unlike {@see $retainSubmissionsDays}
     * this defaults to a non-zero window so a spam-flag pile can't grow unbounded
     * out of the box — spam filters default to *flag* (persist), not *block*, so
     * without this a busy form's spam rows accumulate forever. 0 = keep spam
     * forever (opt out). Legitimate submissions are never touched by this sweep.
     */
    public int $retainSpamDays = 30;

    public int $retainIntegrationLogsDays = 90;
    public int $retainNotificationLogsDays = 90;
    public int $retainAuditLogDays = 365;

    /**
     * How long an unfinished save-&-resume draft is kept before garbage
     * collection. Each save refreshes the expiry. Must be > 0.
     */
    public int $draftRetentionDays = 30;

    /**
     * How long a passively-captured partial (#242/#244) is kept before garbage
     * collection. Deliberately conservative by default — abandoned PII shouldn't
     * linger — and independent of the save-&-resume window. Must be > 0.
     */
    public int $partialRetentionDays = 7;

    /**
     * When pruning submissions, scrub their data + user reference in place instead
     * of deleting the row, so aggregate counts/stats survive while PII does not.
     */
    public bool $anonymizeInsteadOfDelete = false;

    /**
     * Handle of the volume in which generated submission PDFs are stored (#143).
     * When set, {@see \anvildev\simpleform\services\PdfService::store()} persists
     * the rendered PDF as an Asset; the CP detail view links to it instead of
     * re-rendering on demand. Empty = render on demand, never store.
     */
    public ?string $pdfStorageVolume = null;

    /**
     * Handle of the default volume that form file-upload/signature assets are
     * stored in when a File/Signature field doesn't name its own `volume`.
     * Empty falls through to the first available volume, preserving the previous
     * behaviour. Resolution order: per-field volume → this default → first-available.
     */
    public ?string $uploadVolume = null;

    /**
     * Total cap (in megabytes) on a notification's combined attachments (#143). A
     * notification whose PDF + uploaded files exceed this falls back to in-body
     * download links for the uploads (and logs the skip) to protect deliverability.
     * 0 disables the cap.
     */
    public int $maxAttachmentSizeMb = 10;

    /**
     * @deprecated MCP tokens live in the `simpleform_mcp_tokens` table (see
     * {@see \anvildev\simpleform\mcp\TokenManager}), never in settings/project
     * config — keeping the keyed hashes out of git and out of environment syncs.
     * This property only remains so a pre-1.0.0 development install whose project
     * config still carries an `mcpTokens` key can boot; it is always empty and is
     * never read or written. Do not use it.
     *
     * @var array<int, TokenArray>
     */
    public array $mcpTokens = [];

    /**
     * Handle of the Craft Commerce gateway used to collect payment for forms
     * with a Payment field (#116). Empty = use the store's first enabled
     * gateway. Ignored when Commerce isn't installed.
     */
    public ?string $paymentGatewayHandle = null;

    /**
     * Minutes a pending (unpaid) payment submission may linger before it is
     * expired and its order canceled by garbage collection (#116). 0 disables
     * expiry. Only relevant for redirect/offsite gateways where the visitor may
     * abandon the payment after the row is created.
     */
    public int $paymentPendingTtlMinutes = 60;

    /**
     * Geocoding provider for the optional Address-field autocomplete (#250).
     * 'photon' and 'nominatim' are keyless OpenStreetMap services; 'google'
     * (Places) is reserved for a future provider and needs an API key. The
     * Address field opts in per-field; this only chooses the provider.
     */
    public string $addressAutocompleteProvider = 'photon';

    /**
     * Optional override for the autocomplete provider's query endpoint — point
     * it at a self-hosted Photon/Nominatim instance, or leave blank to use the
     * provider's public default (#250).
     */
    public ?string $addressAutocompleteEndpoint = null;

    /**
     * API key for autocomplete providers that require one (e.g. a future Google
     * Places integration). Never hardcoded; the keyless OSM providers ignore it.
     * Passed to the browser as a data attribute, so use a referrer-restricted key.
     */
    public ?string $addressAutocompleteApiKey = null;

    /**
     * Configurable submission approval workflow (#248). When off (default), the
     * submission status behaves exactly as today (new/read/archived/spam); when
     * on, submissions also move through the owner-defined pipeline below.
     */
    public bool $enableWorkflow = false;

    /**
     * Ordered workflow stages, each `['handle' => string, 'label' => string,
     * 'color' => string]`. The first stage is the one new submissions enter (#248).
     * Loosely typed because the raw stored config may be partial; WorkflowService
     * normalizes it.
     *
     * @var list<array<string, mixed>>
     */
    public array $workflowStatuses = [];

    /**
     * Allowed stage-to-stage moves, each `['from' => handle, 'to' => handle,
     * 'label' => string, 'groups' => list<string>]`. `groups` are the user-group
     * handles permitted to perform the transition; empty = any submission manager
     * (admins always may) (#248). Loosely typed because the raw stored config may
     * be partial; WorkflowService normalizes it.
     *
     * @var list<array<string, mixed>>
     */
    public array $workflowTransitions = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Back-compat bridge between the legacy boolean and the three-state
        // policy (#315). Properties are populated from project config before
        // init() runs, so both reflect stored values here. When the policy was
        // never set (pre-#315 install), derive it from the boolean; otherwise
        // keep the boolean in lockstep so legacy readers stay correct.
        if ($this->ipCapturePolicy === null) {
            $this->ipCapturePolicy = $this->collectIpAddresses
                ? self::IP_CAPTURE_FULL
                : self::IP_CAPTURE_OFF;
        } else {
            $this->collectIpAddresses = $this->ipCapturePolicy !== self::IP_CAPTURE_OFF;
        }
    }

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
                    'addressAutocompleteEndpoint',
                    'addressAutocompleteApiKey',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<array-key, mixed>|\yii\validators\Validator>
     */
    protected function defineRules(): array
    {
        $recaptcha = fn(string $type): bool => $this->enableCaptcha
            && $this->selectedCaptchaProvider === 'recaptcha'
            && $this->captchaType === $type;
        $provider = fn(string $name): bool => $this->enableCaptcha && $this->selectedCaptchaProvider === $name;

        return [
            [['defaultEmailSender'], 'required'],
            // Skip the email format check for env references (`$VAR`), which only
            // resolve at runtime.
            [['defaultEmailSender'], 'email', 'when' => fn(): bool => !str_starts_with((string) $this->defaultEmailSender, '$')],
            [['enableHoneypot', 'enableCaptcha', 'cacheFormStructure', 'inlineFormAssets', 'enableMcp', 'dispatchIntegrationsSynchronously', 'enableAkismet', 'anonymizeInsteadOfDelete', 'allowGraphqlCaptchaBypass', 'enableDenylists', 'collectIpAddresses'], 'boolean'],
            [['retainSubmissionsDays', 'retainSpamDays', 'retainIntegrationLogsDays', 'retainNotificationLogsDays', 'retainAuditLogDays', 'submitRateLimitPerMinute', 'maxAttachmentSizeMb'], 'integer', 'min' => 0],
            [['pdfStorageVolume', 'uploadVolume'], 'string'],
            [['draftRetentionDays', 'partialRetentionDays'], 'integer', 'min' => 1],
            [['akismetMode'], 'in', 'range' => [self::AKISMET_FLAG, self::AKISMET_BLOCK]],
            [['denylistMode'], 'in', 'range' => [self::DENYLIST_FLAG, self::DENYLIST_BLOCK]],
            [['duplicateMode'], 'in', 'range' => [self::DUPLICATE_FLAG, self::DUPLICATE_BLOCK]],
            [['ipCapturePolicy'], 'in', 'range' => [self::IP_CAPTURE_FULL, self::IP_CAPTURE_ANONYMIZED, self::IP_CAPTURE_OFF]],
            [['blockedKeywords', 'blockedEmails', 'blockedIps'], 'string'],
            // Reject malformed IP/CIDR entries at save so they never fail silently
            // at submit time (a bad line would simply never match).
            [['blockedIps'], 'validateBlockedIps'],
            [['akismetApiKey'], 'required', 'when' => fn(): bool => $this->enableAkismet],
            [['mcpTokens'], 'safe'],
            [['captchaType'], 'in', 'range' => [self::CAPTCHA_V3, self::CAPTCHA_V2]],
            [['selectedCaptchaProvider'], 'string'],
            [['storageLocation'], 'in', 'range' => ['database']],
            [['templatePath'], 'string'],
            [['recaptchaV3MinScore'], 'number', 'min' => 0, 'max' => 1],
            [['recaptchaV3SiteKey', 'recaptchaV3SecretKey'], 'required', 'when' => fn(): bool => $recaptcha(self::CAPTCHA_V3)],
            [['recaptchaV2SiteKey', 'recaptchaV2SecretKey'], 'required', 'when' => fn(): bool => $recaptcha(self::CAPTCHA_V2)],
            [['turnstileSiteKey', 'turnstileSecretKey'], 'required', 'when' => fn(): bool => $provider('turnstile')],
            [['hcaptchaSiteKey', 'hcaptchaSecretKey'], 'required', 'when' => fn(): bool => $provider('hcaptcha')],
            [['addressAutocompleteProvider'], 'in', 'range' => ['photon', 'nominatim', 'google']],
            [['addressAutocompleteEndpoint', 'addressAutocompleteApiKey'], 'string'],
            [['enableWorkflow'], 'boolean'],
            [['workflowStatuses', 'workflowTransitions'], 'safe'],
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
        return $this->isRecaptchaV2()
            ? $this->recaptchaV2SiteKey
            : $this->recaptchaV3SiteKey;
    }

    /**
     * The raw (unparsed) secret key for the active captcha type.
     */
    public function getActiveSecretKey(): ?string
    {
        return $this->isRecaptchaV2()
            ? $this->recaptchaV2SecretKey
            : $this->recaptchaV3SecretKey;
    }

    /**
     * Whether the active captcha type is reCAPTCHA v2 (as opposed to v3).
     */
    private function isRecaptchaV2(): bool
    {
        return $this->captchaType === self::CAPTCHA_V2;
    }

    /**
     * The parsed secret key for the active captcha type, for server-side verification.
     */
    public function getParsedSecretKey(): ?string
    {
        return $this->parseValue($this->getActiveSecretKey());
    }

    /**
     * Validate every non-empty line of the blocked-IPs list is either a single
     * IPv4/IPv6 address or a CIDR range. Surfaces a specific inline error naming
     * the offending entry so it can be fixed at save time rather than failing
     * silently (an unparseable line would otherwise just never match).
     */
    public function validateBlockedIps(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $entry = trim((string) $line);
            if ($entry !== '' && !IpHelper::isValidIpEntry($entry)) {
                $this->addError($attribute, Craft::t('simple-form', 'Invalid IP or CIDR range: {entry}', ['entry' => $entry]));
            }
        }
    }

    private function parseValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // parseEnv can return a bool for boolean env references; only non-empty
        // string values are meaningful here.
        $parsed = App::parseEnv($value);

        return is_string($parsed) && $parsed !== '' ? $parsed : null;
    }
}
