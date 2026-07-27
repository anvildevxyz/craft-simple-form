# 07 — Legacy / Fallback / Dual-Path Audit

Dimension: code that exists only for backward-compat, migration-era fallbacks, version
guards below the floor, or parallel old/new implementations of the same job.

Plugin floor (from `composer.json`): **PHP `^8.2`**, **Craft CMS `^5.0`**. Anything guarding
below those is dead. (No `version_compare` / `PHP_VERSION` / `getVersion()` checks exist at all
in `src/` other than a benign metadata stamp — see below.)

Research-only. No source modified.

---

## 1. Critical assessment

The codebase is, for its history (22-feature + 8-refactor reconciliation merge), surprisingly
clean on this dimension. There are **no version/feature-detection guards**, **no `@deprecated`
methods**, and **no "TODO remove after migration" markers**. The "legacy" surface is concentrated
in two real places:

1. **The legacy single-email path** (`EmailService::sendLegacy` + the `emailTo/emailSubject/
   emailReplyTo/emailBody` columns on `Form`). This is a genuine parallel implementation
   superseded by the per-form notifications system (#112). The migration backfilled old data, but
   the columns were **never dropped**, the element still reads/writes them, and the controller
   still NULL-writes them on every save though no CP template posts them. **This is the one real
   cleanup target** — but it touches a still-live element schema, so it needs a deliberate
   drop-column migration, not a code-only deletion.

2. **The bare-scalar submission-data fallbacks** (older submissions stored `field_<id> => value`
   instead of `field_<id> => {label,type,value}`). **No migration ever rewrapped this data**, so
   these fallbacks **must stay** — they guard real un-backfilled rows in existing installs.

Most other "fallback" matches are not legacy at all: they are normal null-coalescing defaults
(HiddenField `default`, FormRows `row` default, per-site label fallback), or *removed* legacy
fallbacks where a hardening pass replaced an old insecure default with a fail-closed throw
(TokenManager F9, encryption migration). I flag those only to record that they're correctly clean.

---

## 2. Findings

### MEDIUM — Legacy single-email path is a vestigial parallel implementation

**Files:**
- `src/services/EmailService.php:21-33` (dispatcher falls back to `sendLegacy`)
- `src/services/EmailService.php:157-172` (`sendLegacy()`)
- `src/elements/Form.php:123-126` (the four `email*` properties)
- `src/elements/Form.php:390,400,426-427,553-556` (table attr, search attr, rules, config array)
- `src/controllers/FormsController.php:115-118` (NULL-writes the four params on every save)
- Migration that superseded it: `src/migrations/m260618_000004_notifications.php`

**What it's for:** Before #112, a form sent one email driven by its own `emailTo/emailSubject/
emailReplyTo/emailBody` columns. #112 introduced the `simpleform_notifications` table.
`sendSubmissionEmail()` now resolves notification rows and only calls `sendLegacy()` when a form
has **zero** notification rows.

**Evidence it's (mostly) unneeded:**
- The notifications migration (`m260618_000004`) created a "Default notification" row for **every
  form that had a non-empty `emailTo`**, so all pre-#112 forms that actually sent mail now have a
  notification row and never hit `sendLegacy`.
- No CP template authors these columns anymore — grep of `templates/` finds **zero** references to
  `emailTo`/`emailBody`. `FormsController::saveForm` (line 115-118) still does
  `$form->emailTo = $request->getBodyParam('emailTo')`, but since nothing posts that param it
  resolves to `null` and effectively **wipes the columns on every save** — they are write-dead.
- The columns are still declared in the live schema (never dropped) and surfaced as a table/search
  attribute (`Form.php:390,400`), which is dead UI weight.

**Residual risk that keeps this MEDIUM, not High:** `sendLegacy` *can* still fire for a form that
(a) has its `emailTo` column populated **and** (b) has no notification rows. That combination is
only reachable for data populated outside the CP — e.g. a form imported via
`FormPortabilityService` or created via API that sets `emailTo` but no notification. Confirm the
import/clone/portability paths don't carry `emailTo` before removing. If they don't, this is safe
to delete.

**Proposed action (staged):**
1. Verify Portability/Clone/MCP form-create paths never set `email*` (grep shows the producers
   write notification rows, not `email*`, but confirm).
2. Add a migration that drops `emailTo/emailSubject/emailReplyTo/emailBody` from
   `simpleform_forms` (mirror the existing `dropColumn` pattern used heavily in `m260620_*`).
3. Delete `sendLegacy()` and the `if ($resolved === []) return $this->sendLegacy(...)` branch;
   delete the four properties, rules, config entries, and the `emailTo`/Email-To table/search
   attribute on `Form`; delete the four `getBodyParam('email*')` writes in `FormsController`.

**RISK:** Medium. Schema-touching + a narrow reachable code path. Needs the portability check
above and a migration; pure code deletion alone would orphan live columns.

---

### LOW — `SubmissionValues::labelledLines()` assumes the wrapper, unlike its siblings

**File:** `src/services/.../support/SubmissionValues.php` (`labelledLines()`, ~lines 60-72)
(found via `src/integrations/support/SubmissionValues.php`)

**What it is:** A *consistency* gap rather than a legacy path. `SubmissionValues::value()` and
`byHandle()` both guard bare-scalar legacy entries (`is_array($entry) ? ... : $entry`), but
`labelledLines()` does `$entry['label']` / `$entry['value']` unconditionally and will warning/throw
on a legacy bare-scalar row.

**Evidence:** No migration rewrapped submission data (see §3), so legacy bare-scalar rows can
exist; this method does not tolerate them.

**Proposed action:** Not a *removal* — this dimension's goal is "singular, clean paths," and the
clean fix is to route `labelledLines()` through `self::value()` + a guarded label read so all three
readers share one shape-tolerant path. (Flag to the chat/notification dimension owner.)

**RISK:** Low. It's a latent bug, not dead code; left as-is it's a fragility, not bloat.

---

### LOW — `Plugin::getVersion()` metadata stamp (NOT a version guard)

**File:** `src/services/FormPortabilityService.php:78`

`'pluginVersion' => Plugin::getInstance()->getVersion()` only stamps the export envelope's
`_meta`. It is **not** a feature/version gate and is correct. Listed only because the audit grep
for `getVersion` flags it. **No action.**

---

## 3. Legacy / fallback code that MUST STAY

### A. Bare-scalar submission-data fallbacks (un-migrated old data)

**Files:**
- `src/helpers/SubmissionCsv.php:419-430` (`entryLabel`), and the `is_array($entry)` guards in
  `cell()` / row building (around 131, 200-246, 400)
- `src/services/FormRenderService.php:195-201` (prefill carries bare-scalar through as-is)
- `SubmissionValues::value()` (`is_array($entry) ? ($entry['value'] ?? $default) : $entry`)
- `SubmissionValues::byHandle()` (same guard)

**Why it must stay:** Older submissions stored `field_<id> => <scalar>` directly, before the
`{label, type, value}` wrapper was introduced. **I confirmed there is NO migration that rewraps
submission `data`** — `migrations/` contains zero submission-data transforms (only schema columns
on the submissions table). Existing installs therefore still hold un-wrapped rows. Removing these
guards would break CSV export, edit-form prefill, and integration dispatch for legacy submissions.

**Removability condition:** Only safe to remove if a backfill migration is first written that
rewraps every old submission's `data` into the `{label,type,value}` shape. Until then: **keep.**

### B. Encryption plaintext pass-through — `IntegrationsService::decryptSettings()` (484-518)

Legacy plaintext secrets (no `ENC_PREFIX` marker) pass through unchanged. A backfill migration
**does** exist (`m260620_000001_encrypt_integration_secrets`) and re-encrypts in place, so most
installs are converted. **Keep anyway:** the pass-through is the load-bearing logic that lets the
*write* path distinguish "needs encrypting" from "already encrypted," and the migration is skipped
when no `securityKey` is set (it throws instead). It costs nothing and guards env-ref / empty /
unmigrated values. Not legacy bloat — leave it.

### C. Removed-fallback hardening (already clean — do NOT "restore" or re-flag)

These read as "legacy/fallback" in comments but are spots where an old insecure default was
**deliberately deleted** in favor of fail-closed behavior. They are the desired end state:
- `src/mcp/TokenManager.php:194-203` — F9: removed the old `Craft::$app->id` HMAC-key fallback;
  now throws when `securityKey` is empty. Correct.
- `src/migrations/m260620_000001_*` — throws instead of silently succeeding without a key. Correct.

### D. Genuine defaults misnamed "fallback" (not legacy at all — keep)

- `src/fields/HiddenFieldType.php:26` — `default:` config key = a normal user-facing default value.
- `src/helpers/FormRows.php:77` — `row` defaults to null when no row hint; normal layout default.
- `src/helpers/FieldQueryHelper.php:77` — guards malformed JSON config decode; defensive, not BC.
- `src/traits/HasPropagation.php:42` — empty-result fallback in multi-site resolution; normal.
- `src/fields/FieldType.php:105` — `t()` placeholder interpolation when no Craft app is booted;
  exists **only** for pure-source unit tests, production always has the app. Test affordance, keep.
- `src/mcp/McpServer.php:282` — serialises structured content into a text block "for backwards
  compatibility" **per the MCP spec** (older MCP clients), not the plugin's own legacy. Keep.
- `src/web/assets/form/FormAsset.php:33` — inline-asset fallback so the no-bundle render reads the
  same CSS/JS; a deployment affordance, not legacy. Keep.

---

## Summary of counts

- `@deprecated` methods: **0**
- Version/PHP/Craft floor guards below `^8.2` / `^5.0`: **0**
- "TODO remove after migration" markers: **0**
- Genuine legacy parallel implementations: **1** (legacy single-email path — MEDIUM)
- Consistency/latent-bug fallback gap: **1** (`labelledLines` — LOW, route through shared reader)
- Fallbacks that MUST STAY (un-migrated data / spec-required / defaults): **the bare-scalar
  submission readers (§3A), encryption pass-through (§3B), and ~8 misnamed defaults**
