# Concern #6 (round 3) — Defensive Code Audit: try/catch, `@`-suppression, `??`/guard fallbacks

**Scope:** all 162 PHP files under `src/`. Read-only assessment; no source edited.
**Goal:** genuine bugs propagate with clear handling — no error hiding, no silent fallback — while
KEEPing legitimate boundary handling (untrusted input, real external I/O, transaction cleanup,
low-level→domain-result conversion).

This is a fresh independent pass that **confirms and extends** PR #146's `docs/cleanup/06-defensive-code.md`.

## Inventory (current tree, 2026-06-20)

- **30 PHP `catch` blocks** (4 JS catches in `web/assets/.../dist/*.js` are out of scope — bundled output).
- **3 `@`-suppressions:** `helpers/SafeUrl.php:119` (`@dns_get_record`), `TwigExtension.php:257` & `:258` (`@file_get_contents`).
- **1 DB transaction in the whole tree:** `services/FieldSyncService.php:192` (`beginTransaction`).

## Headline verdict

**0 HIGH-confidence purposeless try/catch or `@` to delete.** Every catch sits on a real boundary —
Guzzle HTTP (captcha, integrations), mailer, Commerce soft-dep, secret decrypt, MCP protocol,
audit-log, asset publishing, transaction rollback-rethrow. Every reviewed guard traces to untrusted
input (`getBodyParam`, MCP/GraphQL `$arguments`, `json_decode` of stored config) or a nullable contract.

The one **genuine, actionable finding is structural, not a removal: `FieldsController` performs
multi-row writes with NO transaction, and its `catch` then makes a partial write SILENT** — a real
data-integrity defect (re-confirmed from PR #146; still present). Everything else is a KEEP or a
MEDIUM behavior-decision.

---

## Findings (classified)

### F-1 — `FieldsController::actionAdd`/`actionEdit` — non-transactional multi-row writes + silent partial-write swallow — **GENUINE (data integrity)**
`src/controllers/FieldsController.php:53-90` (add) and `:121-152` (edit).

- **Construct:** `try { ...multiple createCommand()->insert/update/upsert()->execute()... } catch (\Exception $e) { warn; return asJsonError('Failed to ...'); }`.
- **What it hides:** `actionAdd` writes **1** structural row into `{{%simpleform_fields}}` (`:55`) then loops `:72` writing **N** per-site rows into `{{%simpleform_fields_sites}}` (`:73`). There is **no `beginTransaction()`**. If the structural row commits and a per-site insert then fails (constraint, deadlock, DB blip), the field exists with missing/partial `_sites` rows — and the `catch` reduces it to a generic `"Failed to add field"`, so the **half-written field is silent**. `actionEdit` (`:123` update + `:132` upsert) has the same shape. By contrast `FieldSyncService::sync` (`:192`) already wraps its equivalent multi-row insert/upsert/delete in `beginTransaction()` + rollback-rethrow — so the controller path is the inconsistent one.
- **Recommendation:** **Wrap the add/edit write blocks in `$db->beginTransaction()` + commit, with rollback in the catch** (mirror `FieldSyncService::sync` at `:192/:289-293`). KEEP the JSON-error catch itself (AJAX UI → meaningful outcome is correct), but it must roll back first. Consider including a stable error to the client while logging the real cause (already logged).
- **Confidence:** HIGH (data-integrity defect). **Risk:** LOW (additive transaction; behavior unchanged on success). **Auto-implement now:** YES.

### F-2 — `FieldsController::actionReorder` — non-transactional N-row update loop — **MINOR (consistency)**
`src/controllers/FieldsController.php:210-249`.

- **Construct:** `try { foreach ($ordered as $id => $sortOrder) { update sortOrder } } catch (\Exception) { return asJsonError(...) }`.
- **What it hides:** N independent `UPDATE`s of `sortOrder` with no transaction; a mid-loop failure leaves a partially-reordered set (some rows at new sort, some old). Less destructive than F-1 (all rows survive, only ordering is inconsistent) and self-healed by the next successful reorder, but inconsistent with FieldSyncService.
- **Recommendation:** Same fix as F-1 (wrap the loop in a transaction). Lower priority than F-1.
- **Confidence:** MEDIUM. **Risk:** LOW. **Auto-implement now:** YES (bundle with F-1).

### F-3 — `FieldModel::validateValue` broad `\Throwable` → `['Validation error occurred']` — **MEDIUM (decision)**
`src/models/FieldModel.php:124-127`.

- **Construct:** `catch (\Throwable $e) { warn; return ['Validation error occurred']; }` around field-type resolution + `$fieldType->validate($value)`. The `null` field-type case is already handled explicitly above (`:118`), so the residual `\Throwable` mostly papers over a **programmer/data defect** (malformed stored config tripping a TypeError) as a vague user-facing string.
- **Note vs PR #146:** the companion `FieldModel::renderInput` catch the prior report flagged (old `:99`) is **gone** — rendering now happens in `TwigExtension::renderFieldGroup` (`:226`, `renderInput`) with **no** surrounding try/catch, so a broken field type already propagates on the render path. Only the `validateValue` catch remains, which makes it more clearly the odd one out.
- **Recommendation:** Either **remove** the catch so a genuine bug surfaces (caught at the controller/Twig boundary), OR keep but **enrich** the warning with field handle + type and use a meaningful message. Because validation runs on a public submission path, there's a mild UX argument for not 500-ing a submit on one malformed field — so MEDIUM, not HIGH. Needs a human stance.
- **Confidence:** MEDIUM. **Risk:** LOW-MED (changes failure mode of a public path). **Auto-implement now:** NO (decision first).

### F-4 — `Submission::getForm` `catch (\Throwable) → return null` — **MEDIUM-LOW (decision)**
`src/elements/Submission.php:88-93`.

- **Construct:** `try { return Form::find()->id($this->formId)->one(); } catch (\Throwable) { warn; return null; }`. A *missing* form already returns `null` without any exception, so the catch only fires on a real query/infra failure — which it then masks as "no form," producing confusing downstream "form not found" states instead of a clear DB error.
- **Recommendation:** Consider **removing** the try/catch so a genuine DB failure propagates; the absent-form `null` keeps working. LOW risk, LOW-MED payoff. Not HIGH because "element accessor returns null on failure" is a common Craft idiom.
- **Confidence:** MEDIUM-LOW. **Risk:** LOW. **Auto-implement now:** NO (decision first).

### F-5 — `TwigExtension::renderAssets` `@file_get_contents(...) ?: ''` — **LOW (optional)**
`src/TwigExtension.php:257-258`.

- **Construct:** `$css = @file_get_contents(FormAsset::distPath('css/simple-form.css')) ?: '';` (and `js`). `@` silences the warning if the **bundled build artifact is missing** (a build error), and `?: ''` then emits empty `<style></style>`/`<script></script>`. A broken build is silently invisible.
- **Recommendation:** Optional — replace `@`+`?:` with `is_file()` + a logged warning when the dist artifact is absent, so a broken build surfaces in logs rather than producing silently-empty inline assets. Low priority.
- **Confidence:** LOW. **Risk:** LOW. **Auto-implement now:** YES (trivial, non-behavioral on the happy path).

---

## KEEP (with rationale) — do NOT file issues

All of these convert a low-level failure into a meaningful, surfaced outcome or sit on a genuine
external/transaction/protocol boundary. Re-verified this pass.

- **`captcha/RecaptchaProvider.php:63` / `captcha/AbstractSiteverifyProvider.php:63`** — `catch (GuzzleException)` → fail-closed (`false`) on a third-party verify POST. Narrow type, correct boundary. KEEP.
- **`services/EmailService.php:102`** — `catch (\Exception)` on mailer send → warn + `false`. Caller sends *after* the submission is saved and ignores the return, so a mail failure must not lose a persisted submission. Textbook graceful degradation. KEEP. (Optional polish: widen to `\Throwable` so a render TypeError logs instead of fatals.)
- **`services/EmailService.php:138`** — `catch (\Throwable)` around notification-body Twig render → fall back to default template, logged. KEEP.
- **`services/IntegrationsService.php:240`** — wraps `$type->send()`, logs a FAILED dispatch row (secrets scrubbed), returns a domain failure. Dispatch boundary. KEEP.
- **`services/IntegrationsService.php:462`** — `catch (\Throwable)` on `decryptByKey` → log + degrade to `''` so a rotated/corrupt cipher doesn't 500 every dispatch. KEEP. (Guarded by base64/key checks first at `:454-458`.)
- **`services/PaymentsService.php:190`** — `catch (\Throwable)` around Commerce `saveElement(cart)` → warn + `null`. Commerce is a genuine soft dependency (guarded by `commerceAvailable()`). KEEP.
- **`services/AkismetService.php:66`** — external spam-check HTTP, fail-open (`false` = not spam), logged. Optional anti-spam. KEEP.
- **`services/AuditService.php:37`** — audit-log write must never break the audited op; logged. KEEP.
- **`services/FieldSyncService.php:290`** — `catch (\Throwable) { rollBack(); throw; }`. Pure transaction cleanup, re-throws (does not swallow). **Reference pattern** F-1/F-2 should copy. KEEP.
- **Integration connectors** — `WebhookIntegration.php:142`, `AbstractChatIntegration.php:74`, `ActiveCampaignIntegration.php:68`&`:95`, `PipedriveIntegration.php:73`, `HubSpotIntegration.php:67`, `MailchimpIntegration.php:77`: each catches a Guzzle/transport exception → `IntegrationResult::failure(...)`. Low-level→domain-result on a third-party API boundary. KEEP.
- **`mcp/McpServer.php:261`** (tool-call) and **`:361`** (resource read) — `catch (\Throwable)` → in-band MCP error (`isError`/`ERR_*`), internals not leaked. MCP protocol contract; broad type justified (any tool/provider may throw anything). KEEP.
- **`mcp/TokenManager.php:165`** — `catch (\Throwable)` on `persist()` of a `lastUsed` timestamp → warn only. Documented best-effort telemetry; the failure IS logged and must not block an otherwise-valid authed request. KEEP.
- **`fields/FileFieldType.php:134`** — `catch (\Throwable)` around `FileHelper::getMimeType` → treat as non-executable, with extension allowlist + Craft asset validation still in force. Defensible security default. KEEP.
- **`TwigExtension.php:250`** — `catch (\Throwable)` around `registerAssetBundle` → fall back to inline output in console/test contexts where the asset manager can't publish. Documented, intentional. KEEP.
- **`helpers/SafeUrl.php:119`** — `@dns_get_record($host, DNS_AAAA)` inside an SSRF guard that errs toward blocking; suppression avoids a noisy warning on hosts with no AAAA record. KEEP.

### Guards (`isset`/`??`/`is_array`/`empty`) — sweep result
No removable guards surfaced. All non-trivial ones trace to untrusted/nullable input. Confirmations:
- `FieldsController::decodeConfigParam:193-194` `is_array($decoded)` — `json_decode` of posted config (scalar JSON decodes to non-array). KEEP.
- `controllers/FieldsController.php:204` `!is_array($fields)` — untrusted `getRequiredBodyParam('fields')`. KEEP.
- `IntegrationsService.php:450/455` `is_string`/`base64_decode(...,true) === false` guards before decrypt. KEEP.
- MCP/GraphQL `isset($arguments[...])` across `mcp/` and `gql/` — untrusted `array<string,mixed>`. KEEP.
- `App::parseEnv` string|array guards in `EmailService`. KEEP.

### Cosmetic (not error-hiding — out of scope for #6)
- `FieldSyncService:64`-style `empty($x) || !is_array($x)` redundancy and `is_array($f['config'] ?? null)` `?? null` — harmless, simplify only if already touching the line. Not removal targets.

---

## Prioritized list

| # | Finding | Action | Confidence | Risk | Auto-now |
|---|---------|--------|-----------|------|----------|
| F-1 | FieldsController add/edit — no transaction, silent partial write | wrap in transaction (keep catch, rollback) | HIGH | LOW | YES |
| F-2 | FieldsController reorder — no transaction on N-row loop | wrap in transaction | MEDIUM | LOW | YES |
| F-3 | FieldModel::validateValue broad `\Throwable` swallow | remove OR enrich+narrow (decision) | MEDIUM | LOW-MED | NO |
| F-4 | Submission::getForm `\Throwable`→null masks DB error | remove (decision) | MED-LOW | LOW | NO |
| F-5 | TwigExtension `@file_get_contents ?: ''` hides missing build | is_file() + log | LOW | LOW | YES |

**Data-integrity callout:** F-1 (and F-2) are the only findings that hide a real correctness problem
(silent partial writes). F-1 should be fixed first. All `catch`-removals (F-3/F-4) are behavior
decisions, not mechanical deletes.
