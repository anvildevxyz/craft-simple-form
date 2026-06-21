# Concern #7 — Deprecated / Legacy / Fallback / Dual-Path Audit (Round 3)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2, ns `fabianhaef\simpleform`)
**Date:** 2026-06-20
**Mode:** Read-only research. No source/tests/templates edited.
**Version floor:** `php: ^8.2`, `craftcms/cms: ^5.0`.
**Baseline:** Fresh independent pass *after* PR #146 (the round-1/round-2 cleanup) landed
(commit `7e31e17`). Confirms/refutes/extends the prior `docs/cleanup/07-legacy.md` and
`docs/cleanup/round2/07-deprecated-legacy.md`.

---

## Headline

The codebase is **essentially clean** on this axis, and got cleaner with PR #146.

- **0** `@deprecated` markers, **0** `version_compare` / `getVersion` / `::VERSION` / PHP-version
  gates, **0** `class_alias` BC shims, **0** polyfills, **0** dead feature flags, **0**
  commented-out implementation blocks, **0** "old-format vs new-format" dual parsing branches
  anywhere in `src/` (outside the historical `src/migrations/`, which must not be touched).
- The two **actionable** findings from prior rounds are now **already resolved** by PR #146
  (see "Resolved since prior rounds").
- Exactly **one** genuine, small finding remains: the `actionGlobalIndex()` redirect shim
  (prior round-1 M1, re-confirmed). Filed as a GitHub issue.
- Everything else that matches a grep term is **necessary compatibility / intentional design**
  and is recorded under "Must keep" with rationale — all prior "do NOT remove" verdicts hold.

---

## Resolved since prior rounds (verified, no longer findings)

### R1 — `FieldModel::validateValue()` "legacy call shape" — RESOLVED by #146
Round-1 H1 flagged a vestigial `$formData = []` default and a "legacy call shape" docblock
sentence. **Both are gone.** Current signature (`src/models/FieldModel.php:101`) is
`validateValue(mixed $value, array $formData): array` — `$formData` is now **required**, and
the docblock (lines 91–100) no longer mentions any "legacy call shape." Nothing to do.

### R2 — Triplicated `resolveForm()` across the 3 MCP insight tools — RESOLVED by #146
Round-2 H1 flagged `resolveForm()` copied byte-for-byte across `DetectSpamPatternsTool`,
`CategorizeSubmissionsTool`, `SummarizeSubmissionsTool`. It is now a **single shared** static
method `InsightCorpus::resolveForm()` (`src/mcp/tools/support/InsightCorpus.php:96`); no
`private function resolveForm` copies remain in `src/mcp/`. Nothing to do.

---

## Findings (genuine)

### F1 — `IntegrationsController::actionGlobalIndex()` + bare `simple-form/integrations` route — redundant redirect shim
- **Files:**
  - `src/controllers/IntegrationsController.php:50-57` — method whose entire body is
    `return $this->redirect('simple-form/settings/integrations');`, docblocked
    "Legacy `/simple-form/integrations` entry point — integrations now live under Settings."
  - `src/Plugin.php:454` — route rule
    `$event->rules['simple-form/integrations'] = 'simple-form/integrations/global-index';`
- **What it is:** A transitional shim from when global integration management moved out of a
  top-level `/simple-form/integrations` screen and into **Settings → Integrations**
  (`simple-form/settings/integrations`, route at `Plugin.php:485`, served by
  `actionSettingsIndex()`). The bare URL exists only to redirect old bookmarks/links to the
  new canonical location.
- **Proof it is safe to remove:**
  - **Not in the CP nav.** `getCpNavItem()` (`src/Plugin.php:420-441`) builds the subnav from
    only `forms`, `submissions`, `settings` — it never references `simple-form/integrations`.
  - **Not referenced by any template.** Every template hit for `simple-form/integrations`
    targets a **specific sub-action** (`/toggle`, `/delete`, `/save`, `/resend`,
    `/resend-all`, `/failures`, `/toggle-form`) — never the bare path that maps to
    `global-index`. (`grep -rn "simple-form/integrations" src/templates` shows only sub-actions.)
  - **Not referenced by console, GraphQL, MCP, or migrations** — `grep` for `global-index` /
    `globalIndex` / `actionGlobalIndex` returns only the definition site + its route rule.
  - The redirect target (`simple-form/settings/integrations`) is a live route, so the canonical
    screen remains reachable; removing the shim only drops the convenience redirect.
- **Recommendation:** Remove `actionGlobalIndex()` (`IntegrationsController.php:50-57`) **and**
  the `Plugin.php:454` route rule, making the integrations entry point singular (Settings only).
- **Confidence:** MEDIUM. The audit can only prove there are no *in-repo* references. The single
  residual risk is an **external** saved bookmark / third-party doc / hard-coded admin link to
  the bare `/simple-form/integrations` URL — those would 404 instead of redirecting. Given this
  is a **pre-1.0, unreleased** plugin with no shipped version to preserve bookmarks for, that
  risk is low.
- **Risk:** Low. No behavior change to forms, submissions, migrations, or the canonical
  Settings → Integrations screen.
- **Safe to auto-implement now:** Yes (trivial, gate-safe), accepting the documented external-link
  caveat. If maximal link stability is desired pre-release, downgrade to "keep" — but then it
  should lose the "Legacy" framing.

---

## Must keep (necessary compatibility / intentional design — NOT legacy debt)

All re-verified this round; all prior "do NOT remove" verdicts stand.

### K1 — EmailService `sendLegacy()` / "legacy email columns" — KEEP (live feature)
`src/services/EmailService.php:19-66`. When a form has **no notification rows**,
`sendSubmissionEmail()` falls back to the form's own `emailTo`/`emailSubject`/`emailReplyTo`/
`emailBody` columns. Despite the "legacy" naming this is a **fully live, supported** simple
single-recipient mode: those columns are still editable (`src/templates/forms/edit.html`),
saved (`FormsController`), projected (`FormQuery`), validated (`Form`), and settable/surfaced via
MCP (`CreateFormTool`/`UpdateFormTool`/`FormPresenter`). It is a deliberate two-mode design
(simple columns *or* the richer Notifications system), not migration-era dead code.
**Cleanup available is terminology only** (the `sendLegacy` / "legacy email columns" naming
over-signals deprecation for a current feature; e.g. `sendSimpleEmail` / "built-in email
columns"). Out of scope for *removal*; noted for a possible rename. **Confidence HIGH (keep).**

### K2 — IntegrationsService plaintext-passthrough on decrypt — KEEP (production data compat)
`src/services/IntegrationsService.php:434-469` (`decryptSettings()`). Marker-prefixed values are
decrypted; marker-less (plaintext) values pass through unchanged. This is **required** for real
stored data: env-reference values (never encrypted), and any secret written between deploy and
the backfill migration (`m260620_000001_encrypt_integration_secrets.php`) running. The
`str_starts_with($value, self::ENC_PREFIX)` marker check is the correct discriminator, not a
removable dual path. **Confidence HIGH (keep).**

### K3 — TokenManager "previous fallback" comment — already singular, nothing to do
`src/mcp/TokenManager.php:192-198`. The comment *describes* a fallback (to `Craft::$app->id`)
that was **already removed**; the code now fails closed when `securityKey` is empty. The code is
already the singular clean path. (Optionally the historical comment could be trimmed, but it
documents a real security decision — leave it.) **Confidence HIGH (no removal).**

### K4 — FieldQueryHelper malformed/legacy JSON guard — KEEP (defensive data hygiene)
`src/helpers/FieldQueryHelper.php:73-77`. `is_array()` guard after `json_decode` of a stored
`config` column. Single parse path with a normal type guard against malformed/legacy stored
rows — not an old/new-format branch. **Confidence HIGH (keep).**

### K5 — HasPropagation "callers apply their own fallback" — KEEP (normal empty-result handling)
`src/traits/HasPropagation.php:42`. Documents ordinary empty-result behavior, not compat code.
**Confidence HIGH (keep).**

### K6 — MCP "backwards compatibility" text block — KEEP (MCP wire-protocol spec)
`src/mcp/McpServer.php:280`. Serializes `structuredContent` into a `text` content block per the
**MCP protocol** (clients that don't read `structuredContent` fall back to text). This is spec
compliance, not this plugin's internal history. One response, not two selectable paths.
**Confidence HIGH (keep).**

### K7 — FormAsset inline-asset fallback — KEEP (intentional rendering strategy)
`src/web/assets/form/FormAsset.php:33`. Inline-asset path reads the same CSS/JS the bundle
serves; an intentional rendering mode (inline vs bundled), not a version shim. **Confidence
HIGH (keep).**

### K8 — Runtime feature toggles — NOT feature-flag debt
`enableHoneypot` / `enableCaptcha` / `enableAkismet` / `enableMcp` /
`dispatchIntegrationsSynchronously` / `anonymizeInsteadOfDelete` /
`allowGraphqlCaptchaBypass` / `cacheFormStructure` / `inlineFormAssets` (`src/models/Settings.php`)
are **operator-facing settings**, branched at runtime on purpose — not dead/transitional flags.
**Confidence HIGH (keep).**

### Migrations — do NOT touch (historical record)
All under `src/migrations/` (`m240614_000001_init`, `m260615_000002_form_email_body`,
`m260618_000004_notifications`, `m260620_000001_encrypt_integration_secrets`, etc.). They
reference the email columns / encryption backfill but are an applied historical record.

---

## Prioritized recommendations

| # | Action | Confidence | Risk | Auto-now |
|---|--------|-----------|------|----------|
| F1 | Remove `actionGlobalIndex()` (`IntegrationsController.php:50-57`) + the `Plugin.php:454` bare route → singular Settings-only entry point. | MEDIUM | Low | Yes (w/ external-link caveat) |
| K1 | *Optional* rename `sendLegacy()` / "legacy email columns" wording (do NOT remove the code). | HIGH (keep) | — | Not a removal |

**Net removable legacy in `src/`: one redirect shim (F1).** Everything else is necessary
compatibility or intentional design. PR #146 cleared the only other two actionable items. This
axis is in very good shape.
