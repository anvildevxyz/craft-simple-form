# 07 — Deprecated / Legacy / Fallback / Dual-Path Audit

**Date:** 2026-06-20
**Scope:** `src/` of `fabianhaef/craft-simple-form`
**Mode:** Read-only research. No source edited.

## Version floor (what's removable in principle)

`composer.json` requires:

- `php: ^8.2`
- `craftcms/cms: ^5.0`

So any PHP < 8.2 or Craft < 5.0 compatibility code would be removable. **There is none.** No
`version_compare`, no `PHP_VERSION`, no `Craft::$app->getVersion()` checks, no `@deprecated`
markers anywhere in `src/`. The codebase has clearly been through prior cleanup passes and is in
good shape on this axis.

## Overall assessment

The word "legacy" appears in several comments, but in nearly every case it labels a **still-live,
still-supported code path**, not dead code. The genuine removal candidates are tiny. There is no
version-shim debt, no commented-out implementations, no always-on/always-off feature flags, and no
dual old/new behavior branches kept "just in case."

---

## HIGH-confidence findings

### H1. Vestigial "legacy call shape" on `FieldModel::validateValue()`

- **File:** `src/models/FieldModel.php:103` (param `array $formData = []`), docblock at lines 91–102.
- **What:** `validateValue(mixed $value, array $formData = [])`. The docblock says: "With no
  `$formData` (the legacy call shape) behaviour is unchanged."
- **Reality:** The single-arg / no-`$formData` shape has **zero callers** anywhere — the only
  production caller (`src/services/SubmissionService.php:199`) always passes `$valuesByHandle`, and
  there are no `validateValue($x)` single-arg calls in `tests/` either.
- **Singular clean path:** The default value `= []` and the "legacy call shape" sentence in the
  docblock are vestigial. Keeping a defaulted param is low-harm, but the docblock language implies a
  supported alternate contract that no longer exists and is slightly misleading.
- **Recommendation:** Drop the "legacy call shape" sentence from the docblock. Optionally make
  `$formData` required (remove the `= []` default) since every caller supplies it — but verify the
  PHPUnit/Codeception suites first (a unit test may construct the call directly). Confidence: HIGH
  on the docblock; MEDIUM on removing the default (test exposure).

---

## MEDIUM / context-dependent findings

### M1. `IntegrationsController::actionGlobalIndex()` — redundant redirect entry point

- **File:** `src/controllers/IntegrationsController.php:50-57`; route wired at
  `src/Plugin.php:445` (`simple-form/integrations` → `integrations/global-index`).
- **What:** A "Legacy `/simple-form/integrations` entry point" whose entire body is
  `return $this->redirect('simple-form/settings/integrations');`.
- **Reality:** It is still wired as a live route, so it is not dead — it preserves an old bookmark/
  link target. But it is a redundant second path to the canonical Settings → Integrations screen.
  Nothing in templates links to `simple-form/integrations` directly (templates use the specific
  sub-actions like `/integrations/toggle`, `/integrations/failures`).
- **Singular clean path:** If no external bookmarks/docs depend on the bare `/simple-form/integrations`
  URL, drop both the `actionGlobalIndex()` method and the `Plugin.php:445` route rule. If backward
  link stability matters, keep it but it's the one intentional redirect-shim in the codebase.
- **Recommendation:** Low priority. Remove only if you're confident no nav item, doc, or saved CP
  link still points at it. Confidence: MEDIUM (depends on external link stability the audit can't see).

### M2. EmailService "legacy email columns" path — NOT removable (live feature)

- **File:** `src/services/EmailService.php:19-66` (`sendLegacy()`, called from `sendSubmissionEmail()`
  at line 31 when a form has no notification rows).
- **What:** Labeled "legacy single-notification path driven by the form's own email columns"
  (`emailTo` / `emailSubject` / `emailReplyTo` / `emailBody`).
- **Reality — important:** Despite the "legacy" wording, this is a **fully live, supported feature**,
  not migration-era dead code. The email columns are still:
  - Editable in the CP form-edit screen (`src/templates/forms/edit.html:120-156`),
  - Saved by `FormsController::actionSave()` (`src/controllers/FormsController.php:105-108`),
  - Settable via MCP `CreateFormTool` / `UpdateFormTool` and surfaced by `FormPresenter`,
  - Projected by `FormQuery` and validated in `Form::defineRules()`.
- **Singular clean path:** None to pursue right now — this is a genuine two-mode design
  (simple single-recipient email *or* the richer Notifications system), both supported. The only
  cleanup available is **terminology**: the "legacy" naming (`sendLegacy`, "legacy email columns")
  over-signals deprecation for what is a current feature. Renaming to e.g. `sendSimpleEmail` /
  "built-in email columns" would reduce future-reader confusion.
- **Recommendation:** Do NOT remove. Optional: rename for clarity. Confidence: HIGH that it must stay.

### M3. IntegrationsService plaintext-passthrough on decrypt — migration-era, KEEP

- **File:** `src/services/IntegrationsService.php:434-469` (`decryptSettings()`); paired backfill
  migration `src/migrations/m260620_000001_encrypt_integration_secrets.php`.
- **What:** Decrypt path passes through marker-less (plaintext) secret values "for backward
  compatibility."
- **Reality:** This is correct and should stay. It handles: env-reference values (never encrypted),
  values written between deploy and the backfill migration running, and the documented idempotent
  re-encrypt-on-write contract. The marker-prefix check (`str_starts_with($value, self::ENC_PREFIX)`)
  is the right way to distinguish — not a removable dual path.
- **Recommendation:** Do NOT remove. Confidence: HIGH that it's needed.

---

## Defensive guards reviewed and deliberately LEFT (not legacy debt)

These matched grep terms but are correct, intentional, and not removal candidates:

- `src/mcp/TokenManager.php:192` — comment describes a fallback that was **already removed**
  (now fails closed). Nothing to do; the code is already the singular clean path.
- `src/helpers/FieldQueryHelper.php:74` — `is_array()` guard against malformed/legacy stored JSON.
  Defensive data hygiene, not a dual behavior path.
- `src/traits/HasPropagation.php:42` — "Callers apply their own fallback when the result is empty"
  is normal empty-result handling, not compat code.
- `src/mcp/McpServer.php:280` — "backwards compatibility" here is **MCP spec compliance**
  (serialize structuredContent into a text block per the protocol), not internal legacy support.
- `src/web/assets/form/FormAsset.php:33` & `src/web/assets/cp/SimpleFormCpAsset.php:12` — inline-asset
  fallback / consolidation are intentional rendering strategies, not version shims.

## Do-NOT-TOUCH (migrations — historical, even if they look legacy)

All under `src/migrations/`. These reference the old email columns, backfill encryption, etc., but
migrations are an applied historical record and must not be edited:

- `m240614_000001_init.php` (defines `emailTo`/`emailSubject`/`emailReplyTo`)
- `m260615_000002_form_email_body.php` (adds `emailBody`)
- `m260618_000004_notifications.php` (migrates email columns → notification rows)
- `m260620_000001_encrypt_integration_secrets.php` (secret backfill backing M3)

---

## Prioritized recommendations

| # | Action | Confidence | Effort |
|---|--------|-----------|--------|
| H1 | Remove the "legacy call shape" sentence from `FieldModel::validateValue()` docblock; consider dropping the `$formData = []` default (verify tests). | HIGH (docblock) / MED (default) | Trivial |
| M1 | Remove `actionGlobalIndex()` + `Plugin.php:445` route **iff** no external links depend on `/simple-form/integrations`. | MEDIUM | Trivial |
| M2 | Optional rename of `sendLegacy()` / "legacy email columns" wording to reduce false-deprecation signal. Keep the code. | HIGH (keep) | Small |
| M3 | Keep plaintext-passthrough decrypt as-is. | HIGH (keep) | None |

**Net removable dead code: effectively one item (H1's docblock + maybe one default param).** This
axis is essentially clean; prior cleanup passes did their job.
