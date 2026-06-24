# 07 — Legacy / Fallback / Dual-Path (round 3, full re-audit)

Concern #7: code that exists only for backward-compat, migration-era fallbacks, version
guards below the floor, "old format vs new format" branches, dual paths where one branch is
dead, always-on/off feature flags, or transitional refactor leftovers.

Scope: full re-audit of `src/` on branch `chore/code-quality-round3` (HEAD `1c48368`), covering the
~103 commits since 2026-06-21 — payments (#116), the DX initiative (#218–#226: forms-as-code,
developer events, JS hooks, examples, make generators, API-stability, GraphQL SDL), the tabbed
editor + Email-Settings→Notifications merge, the multi-field-rows feature, and — most importantly
for this dimension — **the collapse of all 25 incremental migrations into a single `Install.php`**
(`1c48368`). Builds on `docs/cleanup/07-legacy-fallback.md` and `docs/cleanup/delta/…`.
Research-only. No source modified.

---

## 1. Critical assessment

The codebase remains very clean on this dimension. Across all 210 `src/` files there are still
**zero `@deprecated` markers**, **zero `version_compare`/`PHP_VERSION`/`getVersion()` floor
guards**, **zero "TODO remove after migration" markers**, and **no commented-out old
implementations**. Every `class_exists()` is benign optional-dependency detection (Commerce,
Dompdf) or a pure-source DI guard — not a version gate.

The migration collapse (`1c48368`) is the decisive new fact for this pass. `Install.php` is a pure,
unconditional `createTable`/`addForeignKey` schema with **no** column-existence checks, **no**
"if table exists" guards, **no** backfill, and **no** dual create paths — exactly right for a
collapsed pre-launch install. But the collapse deleted every incremental migration, and one of
those (`m260620_000001_encrypt_integration_secrets`) was the **only** production caller of a
backfill method that still lives in the service. That method is now production-dead — **the one
real new finding this round.**

The two surfaces the prior reports agonised over are both re-confirmed and *more* settled now:

1. **Legacy single-email path** (`EmailService::sendLegacy` + the `email*` columns). Since the
   prior reports this path has been *wired further in*, not retired: the MCP `CreateForm`/
   `UpdateForm` tools, `FormPortabilityService` (export + forms-as-code import), `FormCloneService`,
   and `FormContentHelper::CONTENT_ATTRS` all still read/write `email*`, so `sendLegacy` is genuinely
   reachable for forms produced by those producers without notification rows. The `FormsController`
   comment (`:112-116`) documents it as a *deliberate dormant fallback*. **Confirmed must-stay — do
   not remove.** (The earlier "drop the columns" idea stays withdrawn.)

2. **Bare-scalar submission-data fallbacks.** Re-verified end-to-end: `SubmissionService` writes
   only the `{label,type,value}` wrapper (so fresh installs never produce bare scalars), and
   `Install.php` performs **no** data rewrap. The `is_array($entry)` guards across `SubmissionCsv`,
   `FormRenderService`, and `SubmissionValues` therefore still guard genuinely un-migrated rows in
   pre-launch installs. **Must-stay.** Bonus: the prior LOW "`labelledLines()` doesn't tolerate
   bare scalars" gap was **fixed** (commit `987de24`) — all three readers now route through the
   shape-tolerant `SubmissionValues::value()`/`label()`. Resolved; drop from the list.

Two new LOW comment-accuracy items appeared with the v2 export-schema bump (stale "v1 is current"
comments) and the multi-field-rows CSS (a browser-cache `var()` fallback). Neither is dead code.

---

## 2. Findings

| # | File:line | Construct | Evidence it's dead / redundant | Recommended collapse | Confidence | Risk |
|---|-----------|-----------|-------------------------------|----------------------|-----------|------|
| 1 | `services/IntegrationsService.php:457-472` (`encryptStoredSecrets()`) | One-time plaintext→ciphertext backfill method; its own docblock (`:451`) says *"One-time backfill (migration m260620_000001)"* | That migration was **deleted** in the collapse (`1c48368`). Grep shows **no production caller** remains anywhere in `src/` — the only references are two assertions in `tests/integration/IntegrationExposureTest.php:162,172`. Pre-1.0, no release ever shipped plaintext secrets through an upgrade, and fresh installs encrypt on every write (`encryptSettings`, `:441-443`), so no row is ever plaintext. | Delete the method; drop/rework the two test assertions (the test can assert `encryptSettings()`/`decryptSettings()` round-trip directly). **Keep** the `decryptSettings()` plaintext pass-through (`:508-509`) — it still guards env-refs (`$VAR`) and empty values on the read path. | **HIGH** | LOW–MED. Pure code+test deletion. The only "risk" is removing the sole way to encrypt pre-existing plaintext rows in a dev install upgraded across the encryption commit; pre-1.0 that's acceptable, and write-time encryption converts any such row on next save. |
| 2 | `elements/Form.php:133-136,400,410,436-437,585-588`; `EmailService.php:35-36,186-218`; `elements/db/FormQuery.php:74-77`; + producers (`mcp/tools/CreateFormTool`, `UpdateFormTool`, `tools/support/FormPresenter`, `services/FormPortabilityService`, `FormCloneService`, `helpers/FormContentHelper:21`) | Legacy single-email path: `email*` columns + `sendLegacy()` fallback when a form has 0 notification rows | **Reachable, not dead.** MCP create/update, portability import/export, clone, and forms-as-code all still set `email*`; such a form with no notification row hits `sendLegacy`. `FormsController:112-116` documents it as an intentional dormant fallback. | **None — keep.** (Re-confirms prior decision; the "drop columns" path stays withdrawn.) | n/a (must-stay) | — |
| 3 | `helpers/SubmissionCsv.php` (`is_array($entry)` guards ~149,166,195,252,301,482,505,518,535); `services/FormRenderService.php:204-205`; `integrations/support/SubmissionValues.php:24-26,34-35` | Bare-scalar submission-data fallbacks (pre-`{label,type,value}` rows) | `Install.php` does **no** data rewrap; `SubmissionService` only ever writes the wrapper. Old un-wrapped rows can still exist in pre-launch installs. Removing the guards would break CSV export / edit-prefill / integration dispatch for them. | **None — keep** until a rewrap backfill is written (not worth it pre-1.0). | n/a (must-stay) | — |
| 4 | `services/FormPortabilityService.php:582,597` (docblocks) | Comments say *"presently empty (v1 is current)"* / *"Empty while v1 is current; a future bump adds `1 => …`"* | Stale: `SCHEMA_VERSION` was bumped to **2** in `0ba0d58` (#226). The comments now misdescribe the current version. The empty `schemaUpgraders()` map itself is **correct** (v2 is purely additive — `applyFormSettings()` keys-in with `array_key_exists`, so v1 docs import with defaults) and is a legit extension seam — keep it. | Comment-only fix: update both docblocks to "v2 is current" (concern #6 territory). Do **not** remove `schemaUpgraders()`. | **HIGH** (that it's stale) | none (comment-only) |
| 5 | `web/assets/form/dist/css/simple-form.css:56-69` | `grid-template-columns: var(--sf-cols, repeat(N,1fr))` — `data-cols` `repeat()` fallback for "older cached markup (no --sf-cols)" | The live template (`templates/_form/form.twig:36,52`) **always** emits `--sf-cols`, so the `repeat()` fallback only ever applies to a visitor's browser-cached HTML from before the 2026-06-24 multi-field-rows feature. Transitional. | Out of strict scope (CSS, not PHP source). Low value to remove vs. the tiny chance a stale cached page renders single-column for a few minutes. **Leave** for now; revisit post-launch when browser caches have rolled. | LOW | LOW |

### Re-confirmed must-stay / clean (not re-flagging — recorded so they aren't re-audited)

- `services/IntegrationsService.php:508-509` decrypt plaintext pass-through — load-bearing
  marker-detection + guards env-refs/empty; **keep** (prior §3B).
- `mcp/McpServer.php:282` structured-content → text block "for backwards compatibility" — per the
  **MCP spec** (older MCP clients), not the plugin's own legacy. Keep.
- `events/BeforeSendNotificationEvent.php:42` `?NotificationModel $notification = null` — the `null`
  is the `sendLegacy` arm of the event; consistent with finding #2's deliberate retention. Keep.
- `Plugin.php:339` + `models/Settings.php:108` `applyFormsConfigOnUp` (default `false`) — a real
  user-facing opt-in flag, not an always-on/off dead flag. Keep.
- `fields/HiddenFieldType.php:26`, `helpers/FormRows.php:77`, `fields/FieldType.php:105`,
  `helpers/FieldQueryHelper.php:77`, `traits/HasPropagation.php:42`, `web/assets/form/FormAsset.php:33`
  — normal user-facing defaults / test affordance / defensive JSON guard / deployment affordance,
  all misnamed "fallback." Keep.
- `Install.php` (whole file) — pure unconditional schema, no backfill, no conditional column/table
  checks, no dual create path. Clean (exactly right for a collapsed pre-launch install).
- Forms-as-code create-vs-update (`FormPortabilityService::createForm` vs `applyToExistingForm`),
  the `make/*` generators, the new event classes, and the JS-hook asset: **both branches reachable**,
  no dead arms, no v1/v2 format forks. Clean.

---

## 3. HIGH-CONFIDENCE RECOMMENDATIONS (proven unreachable / obsolete)

1. **Delete `IntegrationsService::encryptStoredSecrets()` (`:450-472`)** and the two test assertions
   in `tests/integration/IntegrationExposureTest.php:162,172`. It is a one-time backfill whose sole
   production caller (migration `m260620_000001`) was removed in the migration collapse `1c48368`;
   it now has **no production caller**, fresh installs never store plaintext, and the plugin is
   pre-1.0 with no upgrade path to preserve. Keep the `decryptSettings()` plaintext pass-through
   (`:508-509`) — it still serves env-refs/empty values on read. *(Removing the method makes the
   class strictly cleaner; the test should assert `encryptSettings`/`decryptSettings` directly.)*

2. **Fix the stale "v1 is current" docblocks** in `FormPortabilityService.php:582` and `:597` to say
   "v2 is current." Comment-only; the empty `schemaUpgraders()` map stays (correct, additive v2).
   *(Borderline concern #6, but it's the only other proven-stale legacy artifact, so flagged here.)*

Everything else on this dimension is either a deliberate, reachable fallback (legacy email path,
bare-scalar readers, decrypt pass-through) or a misnamed normal default. **No other gate-safe
removals.**

---

## Counts (full `src/` re-audit)

- `@deprecated` methods: **0**
- Version / PHP / Craft floor guards: **0**
- "TODO remove after migration" markers: **0**
- Commented-out old implementations: **0**
- Genuinely dead code from the migration collapse: **1** (`encryptStoredSecrets()` — HIGH)
- Stale legacy comments: **1** (FormPortability v1/v2 docblocks — HIGH, comment-only)
- Transitional non-PHP fallback (CSS browser-cache): **1** (`--sf-cols` `repeat()` — LOW, leave)
- Deliberate reachable fallbacks that MUST STAY: legacy email path (#2), bare-scalar submission
  readers (#3), decrypt plaintext pass-through, `sendLegacy` event-null arm, ~6 misnamed defaults
- Prior LOW `labelledLines()` gap: **RESOLVED** (commit `987de24`) — dropped from the list
