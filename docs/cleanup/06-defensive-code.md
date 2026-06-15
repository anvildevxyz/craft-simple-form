# Concern #6 — Defensive Code Audit (try/catch + over-defensive guards)

**Scope:** All ~72 PHP files under `src/`. Goal: clear, loud error handling — no error hiding, no silent fallbacks — while preserving legitimate handling of untrusted external input, framework contracts, domain-error conversion, and transaction/resource cleanup.

**Phase 1 — assessment only. No source files were edited.**

## Summary

The codebase is, on the whole, **conservatively and appropriately guarded**. There are only **14 `try`/`catch` blocks** and **2 `@`-error-suppressions** in the entire tree, and most of them sit on genuine external-failure boundaries (Guzzle captcha HTTP, mailer send, asset publishing, JSON/DB writes) where graceful degradation is the documented, correct behavior.

The defensive *guards* (`isset`/`??`/`is_array`/`empty`) are overwhelmingly on untrusted surfaces — `getBodyParam`/`getRequiredBodyParam`, MCP/GraphQL `$arguments`, `json_decode` of stored config, nullable DB relations, and `App::parseEnv` (which can return string|array) — i.e. exactly the KEEP categories. A focused sub-agent sweep of 306 guard sites surfaced **zero** confidently-removable guards once their input sources were traced (its one HIGH candidate, `FieldQueryHelper:73`, was re-verified and is actually correct — see L-1).

**There are 0 HIGH-confidence purposeless constructs.** The findings below are the handful worth a second look; the strongest candidates are MEDIUM (best-effort swallows that hide a real write failure, and broad `\Throwable` catches on the render path). The rest are documented KEEPs, recorded so a future audit doesn't re-litigate them.

---

## Findings

### try / catch

#### M-1 — `EmailService::sendSubmissionEmail` catch — KEEP (correct graceful degradation)
`src/services/EmailService.php:51` — `catch (\Exception $e)` → warn + `return false`.
- **What it does:** swallows mailer failures (SMTP down, bad recipient, template render error).
- **Reachability/value:** Verified the caller: `SubmissionService.php:151-153` sends the email **after** `saveElement($submission)` succeeded and **ignores the return value**. A mail failure must NOT lose an already-persisted submission. This is the textbook "user-facing flow needs graceful degradation" case named in the task.
- **Recommendation:** KEEP. (Minor optional polish: catch `\Throwable` so a TypeError in `renderBody` is also logged rather than fatal — but not required.)
- **Confidence:** KEEP — high.

#### M-2 — `CaptchaService` Guzzle catch — KEEP (external HTTP I/O)
`src/services/CaptchaService.php:64` — `catch (GuzzleException $e)` → warn + `return false`.
- Narrowly typed to `GuzzleException`, wraps a third-party `POST` to the reCAPTCHA verify endpoint. Network/timeout failure must fail verification closed (`false`), not 500 the submission. Exactly a KEEP category (third-party HTTP/captcha call).
- **Recommendation:** KEEP. Good example — narrow type, not `\Throwable`.
- **Confidence:** KEEP — high.

#### M-3 — `FieldSyncService::sync` rollback catch — KEEP (transaction cleanup)
`src/services/FieldSyncService.php:169-172` — `catch (\Throwable $e) { $transaction->rollBack(); throw $e; }`.
- Pure rollback-and-rethrow around a multi-row insert/upsert/delete inside `beginTransaction()`. Does not swallow — re-throws. Required for resource cleanup.
- **Recommendation:** KEEP. Reference pattern.
- **Confidence:** KEEP — high.

#### M-4 — `McpServer` tool-call catch — KEEP (MCP protocol contract)
`src/mcp/McpServer.php:219` — `catch (\Throwable $e)` → log + return `isError:true` in-band.
- Documented MCP contract: tool-execution errors are reported in-band (`isError:true`), never as a JSON-RPC protocol error or a 500, and internals are not leaked to the client. This is "convert a low-level exception into a meaningful protocol-level outcome." Broad `\Throwable` is justified because any tool may throw anything and the boundary must stay closed.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

#### M-5 — `TwigExtension::renderAssets` asset-bundle catch — KEEP (degraded environments)
`src/TwigExtension.php:121-128` — `catch (\Throwable $e)` → warn, fall through to inline `<style>/<script>`.
- `registerAssetBundle` throws when no publishable web `View` exists (console/test/CLI render contexts). Falling back to self-contained inline output keeps rendered forms functional there. Documented and intentional.
- **Recommendation:** KEEP. (Could narrow toward the specific asset-manager exception, but `\Throwable` here is defensible given the variety of failure modes in non-web contexts.)
- **Confidence:** KEEP — medium-high.

#### M-6 — `TokenManager::touch` swallow — MEDIUM (hides a real persist failure, but best-effort by design)
`src/mcp/TokenManager.php:160` — `catch (\Throwable $e)` → warn only.
- **What it hides:** `persist()` (line 198) throws `\RuntimeException` when `savePluginSettings` fails. `touch()` swallows that so updating the `lastUsed` timestamp never blocks an otherwise-valid authenticated request. The catch genuinely hides a settings-write failure — but the docblock explicitly states this is intentional best-effort telemetry.
- **Recommendation:** KEEP as-is is acceptable. If tightening: narrow to `catch (\RuntimeException|\Throwable)` is no improvement; the only real change would be to drop `touch()`'s write entirely, which is out of scope. Leave it, the rationale is sound and the failure IS logged.
- **Confidence:** LOW (to change). The swallow is justified by a documented best-effort contract.

#### M-7 — `FieldModel::validateValue` / `renderInput` broad catches — MEDIUM (render-path defensiveness; mostly hides programmer errors)
`src/models/FieldModel.php:81` (→ `['Validation error occurred']`) and `:99` (→ `<!-- Error rendering field -->`).
- **What they hide:** `getFieldType()` returns `null` (already handled explicitly above each catch), and the field types' `validate()`/`renderInput()` operate defensively on their own config/value (verified `SelectFieldType` — no realistic throw). So the residual `\Throwable` catch mostly papers over a *programmer* bug (e.g. a malformed config that trips a type error) by emitting a generic message / HTML comment instead of surfacing it.
- **Reachability:** A throw here is not an expected external-input failure — it indicates a real defect. Swallowing it to a vague string degrades debuggability.
- **Recommendation:** Lean toward **removing** these two catches so a genuine bug propagates (and is caught by the controller/Twig boundary), OR narrow them and include the field handle/type in the warning. Because these are on the public render path (a broken field shouldn't blank the whole form for an end user), graceful degradation has *some* merit — so this is MEDIUM, not HIGH. Decide based on whether a half-rendered form is preferable to a 500. If kept, at minimum they already log; that's the floor.
- **Confidence:** MEDIUM. Not safe to call "purposeless" — there is a UX argument for keeping a broken field from taking down the page.

#### M-8 — `Submission::getForm` catch — MEDIUM-LOW (swallows query failure to null)
`src/elements/Submission.php:70` — `catch (\Throwable $e)` → warn + `return null`.
- `Form::find()->id($this->formId)->one()` returning null on a *missing* form is already the normal contract (no exception needed). The catch only fires on an actual query/infra failure, which it then masks as "no form." That can produce confusing downstream "form not found" states instead of a clear DB error.
- **Recommendation:** Consider **removing** the try/catch and letting a real DB failure propagate; the `null` for a genuinely-absent form already happens without it. LOW risk but LOW-to-MEDIUM payoff. Not HIGH because element accessors returning null on failure is a common (if imperfect) Craft idiom.
- **Confidence:** MEDIUM-LOW.

#### M-9 — `FieldsController` add/edit/delete/reorder DB catches — MEDIUM (non-transactional + generic error hides cause)
`src/controllers/FieldsController.php:90, 151, 175, 220` — each `catch (\Exception $e)` → warn + `asJson(['success'=>false,'error'=>'Failed to ...'])`.
- **What they hide:** the actual DB error is reduced to a fixed string for the client (acceptable for an AJAX UI), but more importantly `actionAdd` (`:52-93`) and `actionEdit` (`:123-154`) perform **multiple** inserts/upserts (structural row + one `_sites` row per supported site) with **no surrounding transaction**. A mid-loop failure leaves a half-written field. The catch makes that partial-write *silent*.
- **Recommendation:** KEEP the catch (a controller returning JSON error to an AJAX UI is fine and is "convert to meaningful outcome"), but the real fix is orthogonal to concern #6: wrap the add/edit multi-row writes in a `beginTransaction()`/rollback like `FieldSyncService::sync` already does. Note for the structural concern, not a removal here.
- **Confidence:** KEEP the catch; flag the missing transaction separately. MEDIUM.

#### L-2 — `FormsController::actionSave` field-sync catch — KEEP (user-facing message after partial save)
`src/controllers/FormsController.php:130` — `catch (\Throwable $e)` → warn + session error + redirect.
- The inner `FieldSyncService::sync` already rolls back its own transaction (M-3) and rethrows; this outer catch only translates that into a user-facing "Form saved, but its fields could not be saved." notice. Reasonable domain-error presentation.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — medium-high.

### @-error-suppression

#### L-3 — `@file_get_contents` on bundled dist assets — LOW (mild; missing build artifact is silenced)
`src/TwigExtension.php:131-132` — `@file_get_contents(FormAsset::distPath(...)) ?: ''`.
- Reads the shipped `dist/css|js` for the inline-asset path. `@` suppresses the warning if the file is absent (a build error) and `?: ''` then emits empty inline tags. Arguably a missing build artifact should surface, but silencing a PHP warning from inside page output (vs. crashing the render) is defensible.
- **Recommendation:** Optional: replace `@`+`?:` with an explicit `is_file()` check that logs a warning when the artifact is missing, so a broken build is visible in logs rather than producing silently-empty assets. Low priority.
- **Confidence:** LOW.

### Guards (isset / ?? / is_array / empty) — sweep result

#### L-1 — `FieldQueryHelper.php:73` `if (!is_array($config))` — KEEP (re-verified; NOT removable)
`$config = $row['config'] ? json_decode($row['config'], true) : [];` then `if (!is_array($config)) { $config = []; }`.
- A sub-agent initially flagged this HIGH ("unreachable, json_decode returns array|null"). **That is incorrect.** `json_decode` of a *scalar* JSON value (e.g. a legacy/corrupt column holding `"42"`, `"true"`, or `"\"x\""`) returns an `int`/`bool`/`string`, not `null` and not an array — so `!is_array()` genuinely fires. This is JSON-decode of stored DB data, an explicit KEEP category. The "malformed/legacy values" comment is accurate.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high (re-verified manually).

#### Other guards reviewed — all KEEP
The sweep traced ~306 guard sites; every non-trivial one resolved to a legitimate untrusted-input or nullable-contract guard. Notable confirmations:
- `CaptchaService:69` `!is_array($result) || empty($result['success'])` — JSON of third-party HTTP response. KEEP.
- `EmailService:39-40` `is_string($parsedFromEmail)` — `App::parseEnv` returns string|array. KEEP.
- `SubmissionService:91/97/129/135` `?? null` / `isset` — caller-supplied `$context` array (optional keys). KEEP.
- `SubmissionService:209-213` dual int/`field_<id>` key handling — intentional POST-shape polymorphism. KEEP.
- `CreateFormTool` / `ReorderFieldsTool` / `UpdateFieldTool` etc. `isset($arguments[...])` — untrusted MCP `array<string,mixed>` args; `isset` needed to distinguish missing vs null. KEEP.
- `FormMutations:95` `!is_array($entry)` — GraphQL input entries are `mixed` at runtime. KEEP.
- `FormsController:189/206/246`, `FieldsController:285` `is_array(...)` — `json_decode` results and `getSupportedSites()` (Craft contract returns Site[]|array<string,int>). KEEP.
- `FormStructureService:51/94` `is_array($cached)` — `Cache::get()` returns `false` on miss; this is the correct miss-detection idiom. KEEP.

#### Cosmetic redundancy (not error-hiding — optional, LOW)
- `FieldSyncService:64` and `FieldsController:262`: `empty($x) || !is_array($x)` — the `!is_array()` is logically redundant after `empty()` (empty() is true for non-arrays). Harmless; simplify only if touching the line.
- `FormsController:206` / `FieldSyncService:101`: `is_array($f['config'] ?? null)` — the `?? null` is slightly redundant before `is_array`. Cosmetic.
These hide nothing and are *not* removal targets under concern #6; listed only for completeness.

---

## High-confidence implementation checklist

**There are 0 HIGH-confidence purposeless constructs to remove.** Every `try`/`catch` and `@` either sits on a real external/transaction boundary or is documented best-effort degradation, and every guard traced back to untrusted or nullable input.

Nothing in this concern is safe to remove blindly. The candidates below are MEDIUM and require a behavior decision rather than a mechanical delete:

- [ ] **M-7 (decision needed):** `FieldModel::validateValue`/`renderInput` (`models/FieldModel.php:81,99`) — decide whether a malformed field should propagate (better debuggability) or keep degrading the page. If kept, enrich the warning with field handle+type. Do NOT delete without that decision.
- [ ] **M-8 (optional):** `Submission::getForm` (`elements/Submission.php:70`) — consider dropping the try/catch so a real DB failure surfaces; the absent-form `null` already works without it.
- [ ] **M-9 (structural, not a removal):** add a `beginTransaction()`/rollback around the multi-row writes in `FieldsController::actionAdd`/`actionEdit` so partial field writes can't occur. Keep the existing catch.
- [ ] **L-3 (optional):** replace `@file_get_contents` + `?:` in `TwigExtension:131-132` with an `is_file()` check that logs when the bundled asset is missing.

All other sites: **KEEP** (recorded above so they aren't re-flagged).
