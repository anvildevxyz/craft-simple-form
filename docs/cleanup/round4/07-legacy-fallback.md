# 07 — Legacy / Fallback / Dual-Path Audit (re-run, net-new focus)

Concern: deprecated / legacy / transitional / fallback code, dual code paths where one branch
is obsolete, version-gated branches, unreachable fallbacks. Goal: make each path singular and
clean — but only remove what is *verifiably* obsolete (not a supported config / real degradation).

This run re-audits with emphasis on the code shipped this session — coupons (#246), address
autocomplete (#250), submission workflow (#248), conversational theme (#243), payments (#116),
logic jumps (#245), and the review-fix commit `c274dea` / `bce14ef` — and re-checks the one
"legacy" path the earlier pass flagged. Research-only; no source modified.

Plugin floor (`composer.json`): PHP `^8.2`, Craft `^5.0`. There are **no** `version_compare` /
`PHP_VERSION` / Craft-version guards anywhere in `src/` (only `Plugin::getVersion()` as an export
metadata stamp at `FormPortabilityService.php:78` — not a gate). No `@deprecated` methods. No
"TODO remove after migration" markers.

---

## 1. Critical assessment

The net-new code is clean on this dimension. Coupons, workflow, payments, address autocomplete,
logic jumps, and the conversational theme are all single-path implementations with no backward-compat
shims, no dual old/new branches, and no version gates. The `Craft::$app !== null` guards in
`AddressFieldType::beforeSubFields` (and the `?: 'photon'` provider default) are the established
pure-unit-test affordance, identical to `FieldType.php:105`, not legacy. `JumpResolver::jumpsOf`
("tolerant of legacy or malformed shapes", `:161`) is plain defensive input normalisation for a
brand-new (#245) feature where no legacy shape can yet exist — keep, it is one path.

The one genuinely "legacy"-labelled surface — `EmailService::sendLegacy()` plus the
`emailTo/emailSubject/emailReplyTo/emailBody` columns on `Form` — **must be reclassified**. A prior
audit (this file's earlier version) recommended dropping the columns and deleting `sendLegacy()` as
"write-dead". That is **incorrect and unsafe**: the MCP API (`CreateFormTool`, `UpdateFormTool`) and
form clone (`FormCloneService`) actively *write* these columns, create **zero** notification rows,
and `FormPresenter` reads them back. For an MCP- or clone-created form the `email*` columns are the
*only* notification config and `sendLegacy()` is the live, reachable mail path. So this is not a
vestigial parallel implementation — it is a supported configuration. I am withdrawing the prior
removal recommendation.

Net: this concern is **already clean**. I have one LOW consistency item and several "must-stay"
confirmations; I explicitly recommend against the prior pass's email-column removal.

---

## 2. Findings

### CORRECTION (was MEDIUM "remove", now KEEP) — `EmailService::sendLegacy()` + `email*` columns are a live supported path, not dead legacy

**Files:**
- `src/services/EmailService.php:31-37` (dispatcher: `if ($resolved === []) return $this->sendLegacy(...)`)
- `src/services/EmailService.php:186-217` (`sendLegacy()`)
- `src/elements/Form.php:154-157` (the four `email*` properties)
- `src/mcp/tools/CreateFormTool.php:47-50,79-82` and `src/mcp/tools/UpdateFormTool.php:46-49,100-110`
  (write `email*`; create **no** notification rows)
- `src/mcp/tools/support/FormPresenter.php:71-74` (reads them back)
- `src/services/FormCloneService.php:546-549` + `src/helpers/FormContentHelper.php:21`
  (clone/portability carries `email*` as content attrs)

**Why it must stay:** A form created or cloned via the MCP tools sets only `emailTo` and has no
`simpleform_notifications` rows, so `resolveForSubmission()` returns `[]` and `sendLegacy()` is the
sole code path that delivers its notification. Dropping the columns or deleting `sendLegacy()` would
silently break notifications for every MCP/clone/portability-created form and break the documented
MCP `emailTo`/`emailSubject`/`emailBody`/`emailReplyTo` tool arguments. The "legacy" naming refers
to it predating the per-form notifications UI (#112), but the path remains a real, supported,
actively-written configuration — not migration-era dead weight.

**Action:** **None — keep.** Do not act on the earlier audit's drop-column / delete-`sendLegacy`
recommendation. (Optional, separate concern: the method/comment naming "legacy" undersells that it
is the live MCP path; a rename/comment-clarify is cosmetic and out of scope here.)

**RISK of removal:** High (would break a supported API + clone). Hence: leave as-is.

---

### LOW — `SubmissionValues::labelledLines()` doesn't share the bare-scalar guard its siblings use

**File:** `src/integrations/support/SubmissionValues.php` (`value()` / `byHandle()` guard
`is_array($entry) ? ... : $entry`; `labelledLines()` reads `$entry['label']` / `$entry['value']`
unconditionally).

**What it is:** A *consistency* gap, not dead code. Older submissions stored `field_<id> => <scalar>`
before the `{label,type,value}` wrapper; there is **no migration that rewraps submission `data`**
(confirmed: `migrations/` has only submissions-table column adds, no data transforms). So bare-scalar
rows exist, `value()`/`byHandle()` tolerate them, and `labelledLines()` will warn/throw on them.

**Action:** Route `labelledLines()` through `self::value()` + a guarded label read so all three
readers share one shape-tolerant path. This is a "make the path singular" improvement, not a removal.
Best owned by the chat/notification (defensive-code) dimension.

**RISK:** Low. Latent fragility, not bloat.

---

## 3. Confirmed clean (must-stay / not legacy — do NOT touch)

- **Bare-scalar submission-data fallbacks** — `SubmissionCsv.php` (`entryLabel` ~`:630`, the
  `is_array($entry)` guards), `FormRenderService.php:195-201`, `SubmissionValues::value()/byHandle()`.
  No backfill migration exists, so un-wrapped rows are live data. **Keep.**
- **Encryption plaintext pass-through** — `IntegrationsService::decryptSettings()` (~`:465`).
  Load-bearing: lets the write path tell "needs encrypting" from "already encrypted"; guards
  env-ref/empty values. A backfill (`m260620_000001`) exists but the pass-through still earns its
  keep. **Keep.**
- **Removed-fallback hardening** — `TokenManager.php:194` (F9: dropped the insecure `Craft::$app->id`
  HMAC fallback, now fail-closed) and `m260620_000001` (throws without a key). These read as
  "fallback" in comments but are the *desired* end state. **Do not "restore".**
- **`McpServer.php:282`** — serialises structured content into a text block "for backwards
  compatibility" per the **MCP spec** (older MCP clients), not the plugin's own legacy. **Keep.**
- **`AddressFieldType::beforeSubFields` `Craft::$app !== null` guard + `?: 'photon'`
  (`AddressFieldType.php:77-81`)** and **`FieldType.php:105`** — pure-unit-test affordance +
  user-facing default. **Keep.**
- **`JumpResolver::jumpsOf` "tolerant of legacy/malformed shapes" (`:161-182`)** — defensive
  normalisation of a brand-new (#245) config; single path. **Keep.**
- **Misnamed "fallback" defaults** — `HiddenFieldType.php:26` (`default:` value), `FormRows.php:77`
  (`row` default), `FieldQueryHelper.php:77` (malformed-JSON guard), `HasPropagation.php:42`
  (empty-result resolution), `FormAsset.php:33` (inline-asset deployment affordance). All normal
  defaults/defensive code, not BC. **Keep.**

---

## 4. Counts

- `@deprecated` methods: **0**
- Version/PHP/Craft floor guards: **0**
- "TODO remove after migration" markers: **0**
- Genuine legacy parallel implementations safe to remove: **0** (the `sendLegacy` path is a *live
  supported* MCP/clone config — prior "remove" recommendation withdrawn)
- Consistency/latent-bug single-path improvements: **1** (`labelledLines` — LOW)
- Must-stay fallbacks confirmed: bare-scalar readers, encryption pass-through, MCP-spec compat,
  test affordances, normal defaults.
