# 07 — Legacy / Fallback / Dual-Path (DELTA pass, since c5b8fe7)

Concern: code that exists only for backward-compat, migration-era fallbacks, version
guards below the floor, or parallel old/new implementations of the same job.

Scope: only PHP source changed since `c5b8fe7`. Builds on `docs/cleanup/07-legacy-fallback.md`.
Research-only. No source modified. WIP files (FormsController, Form, FormQuery,
FormRenderService, templates/, tests/) read for context only — not patch targets.

---

## 1. Critical assessment

**The delta is clean on this dimension. Zero high-confidence patches.**

The prior full report flagged exactly one real target — the legacy single-email path
(`EmailService::sendLegacy` + the `email*` columns on `Form`) — as a MEDIUM "vestigial parallel
implementation" worth a staged drop-column migration. **The delta reverses that conclusion.**

Two new migrations landed since `c5b8fe7`, and both are deliberate, behavior-preserving
retentions — not cleanup candidates:

1. **`m260622_000001_merge_default_notification.php`** explicitly states (lines 18-19):
   *"The legacy columns and send path are kept as a dormant fallback, so this migration is purely
   additive (no data is dropped)."* It is a **catch-up backfill**: any form created via the old
   Email Settings block since #112 that still has an `emailTo` but no notification row gets a
   "Default notification" synthesized so its mail keeps sending. This is the maintainer making an
   intentional call to **keep** `sendLegacy` as a live safety net, not retire it. After this
   migration, the residual-risk window the prior report worried about (a form with `emailTo` set
   but no notification row) is itself backfilled — so the fallback now fires only for
   data populated *after* this migration outside the CP. Retaining it is the documented design.

   → The prior report's §2 MEDIUM ("delete `sendLegacy` + drop the email columns") is **no longer
   actionable** in light of this migration. Deleting the path now would contradict a migration
   shipped one commit later whose docblock calls it a deliberate dormant fallback, and the
   `email*` columns are still surfaced (`forms/index.twig:71` renders `form.emailTo` as a table
   column — a WIP template, off-limits anyway). **Do not patch.**

2. **`m260622_000002_form_use_custom_template.php`** adds a `useCustomTemplate` lightswitch and
   backfills it to preserve current rendering on upgrade. This is **forward** compatibility done
   correctly (opt-in flag + behavior-preserving backfill), not legacy bloat. Clean.

`EmailService.php` changed only cosmetically since `c5b8fe7` (11+/9-, no legacy-related lines —
the `sendLegacy` branch, docblock, and method are byte-identical in intent). No new dual path.

Everything else flagged by the legacy/fallback grep across the ~70 delta files resolves to
patterns the prior report already correctly classified as **must-stay** or **misnamed defaults**:

- **Bare-scalar submission readers** (`SubmissionCsv.php` `entryLabel` ~501-505, `cell()`/row
  guards; `FormRenderService.php:198-205` prefill pass-through) — guard genuinely un-migrated
  `field_<id> => <scalar>` rows. No backfill migration exists. **Keep** (prior §3A). The
  `FormRenderService` line is in a WIP file regardless.
- **`IntegrationsService.php:474-475`** encryption plaintext pass-through — load-bearing
  marker-detection logic; backfill migration exists but pass-through still guards env-ref/empty
  values. **Keep** (prior §3B).
- **`McpServer.php:282`** — serialises structured content to a text block "for backwards
  compatibility" per the **MCP spec** (older MCP clients), not the plugin's own legacy. Keep.
- **`FieldQueryHelper.php:77`** ("malformed/legacy values that don't decode") — defensive JSON
  guard; concern #6 territory, not dead code.
- **`HiddenFieldType.php:26`**, **`FormRows.php:76`**, **`FieldType.php:105`** — normal
  user-facing defaults / a test-affordance interpolation, misnamed "fallback." Keep.

`SubmissionValues::labelledLines()` (the prior report's LOW consistency-gap finding) lives in
`src/integrations/support/SubmissionValues.php`, which is **NOT in the delta** — unchanged since
`c5b8fe7`. Out of scope for this pass; remains a prior-report item.

Counts in the delta: `@deprecated` methods **0**; version/PHP/Craft floor guards **0**;
"TODO remove after migration" markers **0**; new legacy parallel implementations **0**;
new dual code paths with one dead branch **0**.

---

## 2. High-confidence patch list

**None.** No high-confidence, gate-safe legacy/fallback patches in the delta.

- The one candidate inherited from the prior report (delete `sendLegacy` + drop `email*` columns)
  is **withdrawn**: migration `m260622_000001` (one commit after the audit baseline) deliberately
  keeps it as a dormant fallback and backfills around it. Removing it would contradict shipped
  intent. **LOW confidence — skip.**

---

## Verdict

**0 high-confidence patches.** Delta is clean; the prior MEDIUM email-path target is now
intentionally retained (per `m260622_000001`) and should not be removed.
