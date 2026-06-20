# PRD — Spam Denylists, Duplicate Prevention & Quarantine Review

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#140](https://github.com/fabianhaef/craft-simple-form/issues/140)

---

## 1. Problem Statement

Simple Form already ships a competent spam stack: honeypot, four captcha providers
(`src/captcha`), Akismet content scoring (`AkismetService`), and per-IP submit rate
limiting (`Settings::$submitRateLimitPerMinute`, `RateLimiter`). What it lacks are the
cheap, deterministic, site-owner-controlled filters that every lightweight competitor
*also* lacks but real-world forms desperately need:

- A keyword denylist ("casino", "crypto", URL spam) that drops obvious junk without a
  third-party API call or score threshold.
- Email/domain blocking (a single repeat abuser, or a whole disposable-mail domain).
- IP blocking by single address or CIDR range (a hostile subnet, a known bot host).
- Duplicate-submission prevention — the same person hitting Submit three times, or a bot
  replaying the same payload, currently creates three rows.

Akismet's `flag` mode already saves spam as a `SubmissionStatus::SPAM` row with a
`spamReason` (see `SubmissionService::submit()` step 5), but there is **no CP review
surface** built around it: the SPAM status exists on the element, yet there is no
quarantine workflow — no approve/restore action, no "this was a false positive, send the
notifications now" path. A submission flagged by Akismet is effectively invisible and
un-actionable.

This PRD adds the deterministic denylists + duplicate prevention, and turns the existing
SPAM status into a real **quarantine review** workflow. For a plugin that markets itself
as "simple", a built-in spam quarantine is a genuine differentiator — Formie/Freeform
have it, but the lightweight tier (Sprout, Contact Form) does not.

## 2. Goals

- Add four deterministic, settings-driven filters enforced in `SubmissionService::submit()`,
  before persistence:
  1. **Blocked keywords** — matched against any text value, with `*` wildcard support.
  2. **Blocked emails / domains** — exact address or `@domain` / `*.domain`.
  3. **Blocked IPs** — single IPv4/IPv6 or CIDR range.
  4. **Duplicate prevention** — per-form, configurable window + dedupe key.
- Make each filter respect the same **flag vs. block** mode that Akismet already uses, so
  owners can choose silent-drop or quarantine-for-review per the global mode.
- Build a real **quarantine review** experience around the existing `SubmissionStatus::SPAM`:
  a clear "Spam" status filter/counter on the Submissions index, and bulk
  **Approve (not spam)** / **Delete spam** actions, extending `SetSubmissionStatus`.
- On **Approve**, fire the dispatch + notifications that were withheld at submit time, so a
  rescued false-positive behaves exactly like a legit submission.
- Record the *reason* a submission was quarantined (`spamReason` already exists) so the CP
  can show *why* (e.g. `keyword:casino`, `ip:203.0.113.0/24`, `duplicate`).

## 3. Non-Goals (v1)

- No machine-learning / Bayesian scoring — Akismet covers probabilistic content scoring.
- No allowlist (trusted-sender bypass) — global captcha/honeypot already gate the path.
- No per-form denylists in v1 — the four lists are global `Settings` (per-form duplicate
  *window* is the only per-form knob). Per-form denylists are a future iteration.
- No shared/community denylist feeds or auto-updating blocklists.
- No retroactive re-scan of already-stored submissions when a denylist changes.

## 4. Users & Use Cases

- **Site owner / marketer**: pastes a list of spam keywords and a couple of abuser domains
  into the plugin settings, ends the daily junk flood without touching code.
- **Developer**: blocks a hostile CIDR range surfaced in server logs.
- **Reviewer**: opens the Submissions index, filters to **Spam**, sees *why* each was
  flagged, bulk-approves the two false positives (which then send their notifications) and
  bulk-deletes the rest.
- **Form visitor**: double-clicks Submit on a slow connection and does not create a
  duplicate row / does not get a duplicate autoresponder.

## 5. Proposed Solution

### 5.1 Settings (`src/models/Settings.php`)

New global settings, validated and env-parseable where it makes sense. Reuse the existing
`AKISMET_FLAG` / `AKISMET_BLOCK` semantics by introducing a parallel `denylistMode`:

```php
public bool $enableDenylists = false;
public string $denylistMode = self::DENYLIST_FLAG; // DENYLIST_FLAG | DENYLIST_BLOCK

/** Newline-separated; '*' wildcard. Matched case-insensitively against text values. */
public ?string $blockedKeywords = null;
/** Newline-separated emails, '@domain.tld', or '*.domain.tld'. */
public ?string $blockedEmails = null;
/** Newline-separated single IPs or CIDR ranges (v4/v6). */
public ?string $blockedIps = null;
```

Stored as text blobs (one entry per line) — simplest authoring UX, parsed into arrays at
read time. A small `DenylistService` parses/normalises and caches the lists. Validation
rules reject malformed CIDR / IP entries with a clear inline error rather than failing
silently at submit time.

### 5.2 Duplicate prevention (per-form)

Per-form toggle + window, following the **exact** existing `allowSaveResume` precedent
(boolean column on the `Form` element + dedicated migration `m260620_000003_form_allow_save_resume`):

- `Form::$preventDuplicates` (bool, migration `m26062x_000001_form_prevent_duplicates`).
- `Form::$duplicateWindowMinutes` (int, default 0 = "ever").
- `Form::$duplicateKey` (enum: `email` | `content` | `ip`). `email` keys on the first email
  field's value; `content` keys on a hash of the persisted `data` payload; `ip` on source IP.

A duplicate is detected with a `Submission::find()` query scoped to `formId`, the key
match, and `dateCreated >= now - window`. Camel-case columns in any raw fragment are
`[[...]]`-quoted per house rule.

### 5.3 Enforcement (`SubmissionService::submit()`)

Insert a new **denylist stage** between captcha (step 2) and validation (step 4), and a
**duplicate stage** after validation but before persistence (step 5). Both reuse the
existing flag/block fork that Akismet already established:

```php
// (2b) Deterministic denylists.
$hit = $this->denylist->match($form, $data, $context); // ?string reason, e.g. "keyword:casino"
if ($hit !== null) {
    if ($settings->denylistMode === Settings::DENYLIST_BLOCK) {
        return ['submission' => null, 'errors' => null]; // silent drop, like honeypot
    }
    $quarantineReason = $hit; // fall through to save-as-spam
}
```

The existing `$isSpam` / `readStatus = SPAM` / `spamReason` machinery at step 6 is extended
to also account for a denylist or duplicate hit. `spamReason` is set to the *specific*
reason (`keyword:casino`, `email:spam@x.tld`, `ip:203.0.113.5`, `duplicate`) — not just
`'akismet'`. Steps 8–10 (payments, dispatch, notifications) already self-skip for spam, so
flagged submissions are stored but withhold side effects automatically.

GraphQL inherits all of this for free because `FormMutations::resolveSubmit()` routes
through the same `submit()` method.

### 5.4 Quarantine review workflow

- **Index**: `Submission::defineActions()` already returns a "Mark as spam" action; add
  **"Mark as not spam (approve)"** and **"Delete spam"** as `SetSubmissionStatus` /
  delete actions. Add a "Spam" entry to the status filter/counters
  (`SubmissionStatus::SPAM` is already in `allValid()`).
- **Approve = re-activate side effects**: `SubmissionService::updateStatus()` already clears
  `spamReason` when moving out of SPAM. Extend it so that an explicit *approve* transition
  (SPAM → NEW) re-fires `EVENT_AFTER_SUBMISSION_SAVE` (→ integration dispatch) and
  `EmailService::sendSubmissionEmail()`, behind an idempotency guard so re-approving twice
  does not double-send. This is the key value: a rescued false-positive completes its
  journey.
- **Detail view**: show a "Quarantined — reason: …" banner derived from `spamReason`, with
  inline Approve / Delete buttons.
- **Audit**: every approve/delete writes an `AuditService::log('submission.status', …)`
  entry (the status path already logs).

### 5.5 Migrations

- `m26062x_000001_form_prevent_duplicates` — adds `preventDuplicates`, `duplicateWindowMinutes`,
  `duplicateKey` to the form table. New columns only; no data backfill, no breaking change.
  (Register in `codeception.yml` + reset `craft_test` per the test-DB snapshot reference.)

## 6. Acceptance Criteria

- [ ] New global settings (`enableDenylists`, `denylistMode`, `blockedKeywords`,
      `blockedEmails`, `blockedIps`) render on the Spam settings tab and persist.
- [ ] A keyword denylist entry with `*` wildcard matches a containing text value
      case-insensitively across any field.
- [ ] A blocked email (exact), `@domain`, and `*.domain` form each match the appropriate
      submitted email.
- [ ] A blocked single IP and a blocked CIDR range each match the submitter's IP.
- [ ] Malformed IP/CIDR denylist entries are rejected at settings save with an inline error.
- [ ] In **block** mode, a denylist/duplicate hit drops silently (no row, no error surfaced),
      matching honeypot behaviour.
- [ ] In **flag** mode, a hit saves a `SubmissionStatus::SPAM` row with a specific
      `spamReason` (e.g. `keyword:casino`, `duplicate`) and **no** notification/integration
      dispatch fires.
- [ ] Per-form duplicate prevention blocks a second submission inside the window for each of
      the `email`/`content`/`ip` keys, and allows it outside the window.
- [ ] Submissions index has a working **Spam** status filter + counter and bulk
      Approve / Delete actions.
- [ ] Approving a spam submission (SPAM → NEW) clears `spamReason`, fires integration
      dispatch + notification emails exactly once (idempotent on re-approve), and writes an
      audit entry.
- [ ] All four filters apply identically to GraphQL submissions.
- [ ] Multi-site safe; `Craft::t('simple-form', …)` for all strings; raw SQL camelCase
      `[[...]]`-quoted; PHPStan L7 + ECS clean.

## 7. Testing

### Unit
- `DenylistService`: keyword wildcard matching; email exact/domain/wildcard matching;
  IPv4/IPv6 single + CIDR matching (in/out of range); empty-list = no-op.
- Settings validation: malformed CIDR rejected, valid lists accepted, env refs parsed.
- Duplicate detection: window boundary (inside vs. outside), each dedupe key, per-form scope.
- `SubmissionService::submit()` with mocked services: block mode → no row; flag mode → SPAM
  row + correct `spamReason` + notifications/dispatch suppressed.
- `updateStatus()` approve path: side effects fire once, idempotent on repeat.

### craft-smoke-test scenarios
1. Add `casino` to blocked keywords (flag mode), submit a form whose message contains
   "Casino night!", verify the submission lands in the **Spam** filter with reason
   `keyword:casino` and that no notification email reached Mailpit.
2. Block `@mailinator.com`, submit with `bob@mailinator.com`, verify quarantine; submit with
   `bob@gmail.com`, verify it lands as **New**.
3. Set block mode, submit a denylisted payload, verify *no* submission row was created and
   the visitor saw the success message (silent drop).
4. Enable per-form duplicate prevention (key=email, window=10m), submit twice with the same
   email, verify only one row exists; wait past window / change email, verify a second row.
5. From the Spam filter, bulk-**Approve** a quarantined false positive; verify status → New,
   `spamReason` cleared, the withheld notification email now appears in Mailpit, and an audit
   entry was written.
6. Submit the same denylisted form via the GraphQL `submitForm` mutation; verify identical
   quarantine behaviour.

## 8. Open Questions

- Should `denylistMode` be a single global mode, or per-list (e.g. block IPs but only flag
  keywords)? Starting global to stay "simple"; revisit if requested.
- For the `content` dedupe key, hash the full `data` payload or a normalised subset
  (exclude file fields / timestamps)? Proposing full-payload hash for v1.
- Should Approve re-fire the **autoresponder** as well as admin notifications, or only admin
  notifications? Likely both, but it could surprise a visitor who submitted weeks ago — gate
  behind a confirm dialog?
- Do we want a "block reason" shown to denylisted visitors in flag mode, or always show the
  generic success message? (Leaning: always generic, to avoid leaking the denylist.)
