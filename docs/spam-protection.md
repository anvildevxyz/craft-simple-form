# Spam Protection

Simple Form ships a layered anti-spam stack. The defenses **stack** — enable any
combination — and every one of them is **enforced server-side**, so the AJAX
submit path, the no-JS POST path, and the GraphQL `submitForm` mutation are all
held to the same checks. A crafted POST or a stale cached page cannot slip past a
control just because the rendered form omitted it.

Most of these live under **Settings → Simple Form → Spam Protection** (a few are
per-form). Spam is generally **flagged for review, not silently dropped**, so you
can recover a false positive rather than lose a real lead.

## The order the checks run

For each submission the server runs, in order:

1. **Honeypot** — a filled hidden field is a bot; the submission is dropped
   silently (no row, no error, no signal to the bot).
2. **Availability** (open/close window, quota) — see
   [Form availability](form-availability.md).
3. **Access gates** (login required, per-user limit) — see
   [Form availability](form-availability.md).
4. **CAPTCHA** — the token is verified with the provider.
5. **Per-field validation** + conditional-logic visibility.
6. **Denylists** (keywords / emails / IPs) — deterministic, run *before* Akismet.
7. **Duplicate prevention** (per-form).
8. **Akismet** content scoring.

A denylist, duplicate, or Akismet hit either **flags** the submission as spam
(the default — it is saved for review) or **blocks** it (silently dropped). The
first matching reason wins, and it is recorded as the submission's *spam reason*
so the review queue can show you *why*.

---

## Honeypot

> **Spam Protection → Honeypot → Enable Honeypot Field** (on by default)

Adds a hidden field to every form. Bots that auto-fill all inputs trip it; a
non-empty honeypot value drops the submission silently. This is the cheapest
defense and the one that costs legitimate visitors nothing, so leave it on.

---

## CAPTCHA

> **Spam Protection → CAPTCHA → Enable CAPTCHA** (off by default)

When enabled, choose a **CAPTCHA Provider**. Simple Form ships three:

| Provider | Setting handle | Notes |
|----------|----------------|-------|
| **Google reCAPTCHA** | `recaptcha` | v2 checkbox, or v3 invisible with a score threshold |
| **Cloudflare Turnstile** | `turnstile` | Privacy-friendly, invisible/managed challenge |
| **hCaptcha** | `hcaptcha` | Drop-in checkbox widget |

All key/secret fields accept **environment variables** (e.g. `$RECAPTCHA_SECRET`),
so secrets stay in `.env` rather than as plaintext in project config. Verification
always happens **server-side** against the provider's siteverify endpoint: a
missing secret key, a missing token, or a failed verification call rejects the
submission.

### Google reCAPTCHA

Pick a **CAPTCHA Type**:

- **reCAPTCHA v3 (Invisible)** — no user interaction. A confidence score (0.0–1.0)
  comes back; set **reCAPTCHA v3 Minimum Score** (`recaptchaV3MinScore`, default
  `0.5`) — responses scoring below it are rejected. Fill in **reCAPTCHA v3 Site
  Key** and **Secret Key**.
- **reCAPTCHA v2 (Checkbox)** — the classic "I'm not a robot" checkbox. Fill in
  **reCAPTCHA v2 Site Key** and **Secret Key**.

Get the keys from the [reCAPTCHA admin console](https://www.google.com/recaptcha/admin).

### Cloudflare Turnstile

Set **Turnstile Site Key** and **Turnstile Secret Key** from your Cloudflare
dashboard. The widget injects its own `cf-turnstile-response` token.

### hCaptcha

Set **hCaptcha Site Key** and **hCaptcha Secret Key**. The widget injects its own
`h-captcha-response` token.

### GraphQL and CAPTCHA

> **Spam Protection → GraphQL → Bypass CAPTCHA for GraphQL submissions**
> (`allowGraphqlCaptchaBypass`, off by default)

When CAPTCHA is enabled, the GraphQL `submitForm` mutation must pass a
`captchaToken` argument like any browser submit. **Leave the bypass off** so a
leaked or public GraphQL token can't submit at machine speed. Turn it on only for
trusted server-to-server callers that genuinely cannot obtain a browser CAPTCHA
token — ideally paired with a scoped token.

---

## Akismet

> **Standard edition.** Akismet requires [Standard](editions.md). See [Editions](editions.md).


> **Spam Protection → Akismet → Enable Akismet** (off by default)

Content-based spam scoring that complements CAPTCHA. Akismet inspects the
submitted text (plus the submitter's IP and user agent) and returns a spam
verdict.

- **Akismet API Key** (`akismetApiKey`) — your key from
  [akismet.com](https://akismet.com); env-var friendly (`$AKISMET_KEY`).
- **On spam verdict** (`akismetMode`):
  - **Flag (save as spam for review)** — the default. The submission is saved and
    marked spam.
  - **Block (silently drop the submission)** — the submission is discarded with no
    row and no signal to the bot.

Akismet **fails open**: if it's disabled, unconfigured, or the API call errors,
the submission is treated as not-spam. Spam scoring must never reject a legitimate
visitor because of a third-party outage.

---

## Denylists

> **Standard edition.** Denylists require [Standard](editions.md). See [Editions](editions.md).


> **Spam Protection → Denylists → Enable denylists** (off by default)

Deterministic, owner-controlled filters that run **before Akismet** and need no
third-party call. Add one entry per line. A hit either flags or blocks per the
**On a denylist hit** mode (`denylistMode`, same flag/block choice as Akismet —
**Flag** is the default).

### Blocked keywords (`blockedKeywords`)

One keyword per line, matched **case-insensitively as a substring** against any
submitted text value. Use `*` as a wildcard:

```
casino
buy*now
free-money
```

### Blocked emails (`blockedEmails`)

One per line. Matched against every email-like value in the submission:

| Form | Matches |
|------|---------|
| `bob@example.tld` | that exact address |
| `@example.tld` | every address at that domain |
| `*.example.tld` | that domain **and any subdomain** |

### Blocked IPs (`blockedIps`)

One per line — a single IPv4/IPv6 address or a CIDR range, matched against the
submitter's IP:

```
203.0.113.5
203.0.113.0/24
2001:db8::/32
```

Malformed IP/CIDR lines are **rejected when you save the settings**, with an
inline error naming the bad entry, so a typo never fails silently at submit time.

A denylist hit records a specific reason (`keyword:casino`, `email:bob@x.tld`,
`ip:203.0.113.5`) on the quarantined submission.

---

## Rate limiting

> **Spam Protection → Rate limiting → Submissions per minute**
> (`submitRateLimitPerMinute`, default `0` = off)

Throttles how many front-end submissions a single visitor **IP** may make per
minute, independent of CAPTCHA/honeypot. The limit is **per IP across all forms**,
not per form. It is shared by the front-end submit endpoint and the GraphQL submit
mutation. `0` disables it; **`10` is a sensible starting point** for public forms.

---

## Duplicate-submission prevention (per-form)

Unlike the settings above, this is configured **per form** under the form's
**Availability → Access & limits** section.

- **Prevent duplicate submissions** (`preventDuplicates`) — turn it on.
- **Duplicate key** (`duplicateKey`) — what makes two submissions a duplicate:
  - **Same email address** (`email`, default) — the first email field value.
  - **Identical submitted content** (`content`) — a hash of the payload,
    independent of field order.
  - **Same source IP** (`ip`).
- **Duplicate window (minutes)** (`duplicateWindowMinutes`) — how far back to look.
  `0` means "ever".

A duplicate is treated like a denylist hit: it's flagged (or blocked) per the
global **denylist mode**, with the spam reason `duplicate`. If a submission both
duplicates and trips a denylist, the denylist reason wins.

---

## Spam quarantine & review workflow

Flagged spam is **never silently lost** — it's saved with a **Spam** status and a
recorded reason, reviewable in the Control Panel.

1. Go to **Simple Form → Submissions**.
2. Filter to the **Spam** status to see flagged submissions. Each shows its spam
   reason (`akismet`, `manual`, a denylist reason like `keyword:casino`, or
   `duplicate`).
3. Open a false positive and **mark it as not spam** (or use the
   **Set status** bulk action on the index).

Approving a quarantined false-positive **completes the submission's journey**: the
notification email that was withheld while it sat in spam (including any PDF or
upload attachments) is **fired**, and the outbound **integration dispatch** runs —
exactly as if the submission had been accepted in the first place. This re-fire is
guarded to the Spam → not-spam transition, so re-saving an already-approved
submission won't send duplicate notifications.

You can also **manually flag** a legitimate-looking submission as spam (its reason
is recorded as `manual`); moving it back out of spam clears the reason.

---

## What's enforced where (summary)

| Control | Scope | Default | Enforced on AJAX / no-JS / GraphQL |
|---------|-------|---------|------------------------------------|
| Honeypot | Global | On | Yes |
| CAPTCHA | Global | Off | Yes (GraphQL via `captchaToken`) |
| Akismet | Global | Off | Yes |
| Denylists | Global | Off | Yes |
| Rate limit | Global (per IP) | Off (`0`) | Yes |
| Duplicate prevention | Per form | Off | Yes |

All of it lives on the server. The rendered widgets are only a convenience — the
verdict is always made server-side.
