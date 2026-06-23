# Settings reference

Every Simple Form plugin setting in one place. Settings are stored in **project
config** under `plugins.simple-form.settings` and can be edited in the Control
Panel (**Simple Form → Settings**, requires the `manageSettings`
[permission](PERMISSIONS.md)) or overridden in `config/simple-form.php`:

```php
// config/simple-form.php
return [
    'submitRateLimitPerMinute' => 10,
    'cacheFormStructure' => true,
];
```

Secret/key values support **environment-variable references** (e.g.
`'$RECAPTCHA_SECRET'`) so they live in `.env` rather than as plaintext in project
config. A few settings are **code-only** (no CP control) and are noted as such —
set those in `config/simple-form.php`.

Each section links to the guide that explains the feature in depth.

---

## Email — see [Notifications](../notifications.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `defaultEmailSender` | `null` | Default From address for notifications (env-ref supported). |
| `defaultEmailSenderName` | `null` | Default From name. |

## Spam protection — see [Spam protection](../spam-protection.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `enableHoneypot` | `true` | Hidden honeypot field; a filled value silently drops the submission. |
| `submitRateLimitPerMinute` | `0` | Max submits per visitor IP per minute (front-end + GraphQL). `0` disables; ~10 recommended for public forms. |
| `enableCaptcha` | `false` | Master toggle for CAPTCHA. |
| `selectedCaptchaProvider` | `'recaptcha'` | Active provider handle: `recaptcha` / `hcaptcha` / `turnstile`. |
| `captchaType` | `recaptcha-v3` | reCAPTCHA flavor: `recaptcha-v3` or `recaptcha-v2`. |
| `recaptchaV3MinScore` | `0.5` | Minimum score (0.0–1.0) a reCAPTCHA v3 response must meet. |
| `recaptchaV3SiteKey` / `recaptchaV3SecretKey` | `null` | reCAPTCHA v3 keys (env-ref). |
| `recaptchaV2SiteKey` / `recaptchaV2SecretKey` | `null` | reCAPTCHA v2 keys (env-ref). |
| `turnstileSiteKey` / `turnstileSecretKey` | `null` | Cloudflare Turnstile keys (env-ref). |
| `hcaptchaSiteKey` / `hcaptchaSecretKey` | `null` | hCaptcha keys (env-ref). |
| `allowGraphqlCaptchaBypass` | `false` | Let GraphQL `submitForm` skip CAPTCHA. Leave off unless using trusted server-to-server tokens. |
| `enableAkismet` | `false` | Akismet content spam scoring. |
| `akismetApiKey` | `null` | Akismet API key (env-ref). |
| `akismetMode` | `flag` | On a spam verdict: `flag` (save as spam for review) or `block` (drop). |
| `enableDenylists` | `false` | Owner-controlled keyword/email/IP denylists, evaluated before Akismet. |
| `denylistMode` | `flag` | On a denylist hit: `flag` or `block`. |
| `blockedKeywords` | `null` | Newline-separated keywords (`*` wildcard), matched case-insensitively. |
| `blockedEmails` | `null` | Newline-separated emails, `@domain.tld`, or `*.domain.tld`. |
| `blockedIps` | `null` | Newline-separated IPs or CIDR ranges (v4/v6). |

## Submission handling — see [Submissions](../submissions.md) & [Building forms](../building-forms.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `storageLocation` | `'database'` | Where submissions are stored. |
| `submitMessage` | "Thank you! Your submission has been received." | Default success message (a form may override it per site). |
| `errorMessage` | "There was an error…" | Default error message (per-form override available). |
| `editPath` | `''` | Site path of the front-end edit template that renders `craft.simpleForm.editForm()`. Used by `editUrl(submission)` to build the tokenized edit link; empty = pass a path explicitly. See [Twig & API](../twig-and-api.md). |

## Rendering & assets — see [Theming / render templates](../render-templates.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `templatePath` | `null` | Global render-template override directory (a per-form path wins). Empty = built-in markup. |
| `cacheFormStructure` | `true` | **Code-only.** Cache the resolved form structure between renders (auto-bypassed in dev mode / when Craft's cache is disabled). Set `false` to force a fresh DB read every render. |
| `inlineFormAssets` | `false` | **Code-only.** Emit the form's CSS/JS inline instead of via a cache-bustable asset bundle. Escape hatch for self-contained output (e.g. email previews). |

## Forms as code — see [Forms as code](../forms-as-code.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `applyFormsConfigOnUp` | `false` | **Code-only.** When on, `craft up` automatically runs `simple-form/forms/apply` after it finishes, deploying code-defined forms from `config/simple-form/forms/*.json`. Never prunes on the automatic run; `apply` is always available to run manually. |

## Integrations — see [Outbound integrations](../integrations.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `dispatchIntegrationsSynchronously` | `false` | **Code-only.** Run integrations inline during the submit request instead of on the queue. Leave off in production (a slow/failing third party must never block a submission); turn on only for local debugging. |

## Notifications & PDF — see [Notifications](../notifications.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `pdfStorageVolume` | `null` | Asset-volume handle for storing generated submission PDFs. Empty = render on demand, never store. |
| `maxAttachmentSizeMb` | `10` | Cap (MB) on a notification's combined attachments; over the cap, uploads fall back to in-body download links. `0` disables. |

## Data retention & privacy — see [Submissions › Retention](../submissions.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `retainSubmissionsDays` | `0` | Prune submissions older than N days on GC. `0` = keep forever. |
| `retainIntegrationLogsDays` | `90` | Prune integration dispatch logs older than N days. |
| `retainAuditLogDays` | `365` | Prune audit-log entries older than N days. |
| `draftRetentionDays` | `30` | Keep an unfinished save-&-resume draft for N days (each save refreshes expiry). Must be > 0. |
| `anonymizeInsteadOfDelete` | `false` | When pruning, scrub PII in place instead of deleting the row, so aggregate stats survive. |

## MCP server — see [Twig & developer API › MCP](../twig-and-api.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `enableMcp` | `false` | Enable the token-authenticated MCP endpoint at `simple-form/mcp`. **Off by default** (remotely reachable API surface). |
| `mcpTokens` | `[]` | Configured MCP access tokens, stored hash-only (the plaintext secret is never stored). Managed from the CP. |

## Payments — see [Payments](../payments.md)

| Setting | Default | Purpose |
| --- | --- | --- |
| `paymentGatewayHandle` | `null` | Commerce gateway handle to charge through. Empty = the store's first customer-enabled gateway. |
| `paymentPendingTtlMinutes` | `60` | Minutes a pending (unpaid) submission may linger before GC cancels it. `0` disables expiry. |
