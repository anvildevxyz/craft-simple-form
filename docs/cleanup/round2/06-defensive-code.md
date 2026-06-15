# Concern #6 (ROUND 2) — Defensive Code Audit: MCP insight tools + resources

**Scope (NEW feature only):**
- `src/mcp/tools/{DetectSpamPatternsTool,CategorizeSubmissionsTool,SummarizeSubmissionsTool}.php`
- `src/mcp/tools/support/InsightCorpus.php`
- `src/mcp/resources/{ResourceProviderInterface,FormSchemaResource,SubmissionsDatasetResource}.php`
- `src/mcp/McpServer.php` — the `resources/*` dispatch additions

**Phase 1 — assessment only. No source files were edited.**

Round-1 classification (`docs/cleanup/06-defensive-code.md`) is reused: the MCP `isError` in-band error contract, `json_decode`/stored-data guards, and untrusted-`$arguments`/`$uri` guards are KEEP categories and are not re-litigated below except to confirm a specific new site falls into them.

## Summary

The new insight feature contains **zero `try`/`catch` of its own** — the only two catches in scope are the pre-existing MCP boundary catches in `McpServer` (`tools/call` and the new `resources/read`), both of which are the explicit-KEEP MCP in-band/protocol error contract. There are **no empty catches, no `// ignore`, no `@`-suppressions, and no `\Throwable` catches around the pure in-memory aggregation** in the three insight tools or `InsightCorpus`. The aggregation passes (normalize / countLinks / isShouting / groupKeys / shapeGroups / textValues) are written total — they cannot throw and are correctly left un-guarded.

The defensive *guards* present are almost entirely the KEEP categories: `is_array($built)` discriminating the documented `SubmissionQuery|array{isError}` union return, `$submission->data ?? []` against a genuinely-nullable `?array` column, `isset($arguments[...])` / `is_string(...)` against the untrusted MCP `array<string,mixed>` arguments, `(string)($row['name'] ?? '')` against decoded stored field config, and `!$form instanceof Form` against nullable element-query results.

I traced every guard back to its data source. **There are 0 HIGH-confidence purposeless constructs.** The handful of findings below are LOW: minor cosmetic redundancy and one defensively-broad `\Throwable` in the new resources/read dispatch that is the documented protocol contract (KEEP). Nothing is safe to remove for behaviour-correctness gain.

---

## Findings

### try / catch

#### R2-K1 — `McpServer::handleResourceRead` `\Throwable` catch — KEEP (new code, MCP boundary contract)
`src/mcp/McpServer.php:354` — `catch (\Throwable $e)` → `Craft::error(...)` + `return $this->error($id, ERR_INVALID_PARAMS, 'Resource read failed.')`.
- **What it does:** wraps `$provider->read($uri)`. A provider's `read()` runs an element query (`Form::find()...->one()`) and `json_encode` — genuine I/O/DB that can throw. The catch converts any low-level failure into a JSON-RPC error without leaking internals, exactly mirroring the round-1-KEEP `tools/call` catch (`McpServer.php:254`, round-1 M-4).
- **Reachability:** the guarded operation does real DB I/O, so a throw is reachable and must not 500 the transport or leak a stack trace. This is the documented MCP error boundary the task lists as explicit-KEEP.
- **Note (not a concern-#6 change):** the two boundaries are slightly asymmetric — `tools/call` reports failures in-band (`isError:true` content), while `resources/read` reports them as a JSON-RPC protocol error. That asymmetry is intentional and matches the MCP resources spec (a failed/absent resource is a JSON-RPC error; the `array_key_exists('isError', $result)` branch at `:359` already routes the provider's own not-found payload to the same JSON-RPC error). Correct as-is.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

There are **no other try/catch blocks** in the in-scope files. The three insight tools and `InsightCorpus` contain none — correct, because their post-query work is pure in-memory string/array aggregation that cannot throw.

### Guards — union-return discriminators (KEEP)

#### R2-K2 — `is_array($built)` in all three insight tools + `SubmissionsDatasetResource` — KEEP (documented union discriminator)
`DetectSpamPatternsTool.php:88`, `CategorizeSubmissionsTool.php:67`, `SummarizeSubmissionsTool.php:68`, `SubmissionsDatasetResource.php:85`.
- `SubmissionQueryBuilder::build()` is declared `: SubmissionQuery|array` and returns the `array{isError:true,error:string}` payload when a referenced form can't be resolved (`SubmissionQueryBuilder.php:34-35`). `is_array($built)` is the **only** way to discriminate the error payload from the query object, and returning it propagates the form-not-found error to the MCP client. Removing it would treat the error array as a query and fatal. Behaviorally load-bearing.
- **Recommendation:** KEEP. (Same category as the round-1 sweep's union/contract guards.)
- **Confidence:** KEEP — high.

### Guards — nullable `?array` submission data (KEEP)

#### R2-K3 — `$submission->data ?? []` / `?? null` across insight code — KEEP (column is genuinely nullable)
`InsightCorpus.php:73` (`$submission->data ?? []`), `CategorizeSubmissionsTool.php:123` (`($submission->data ?? [])[$groupBy] ?? null`).
- `Submission::$data` is declared `public ?array $data = null` (`elements/Submission.php:16`) and is the decoded `data` JSON column. A submission can legitimately have `null` data (e.g. an all-empty submission, or a row written before a field existed). The `?? []` / `?? null` guards prevent a null-index error on a reachable null. This is the round-1 stored-data / nullable-contract KEEP category.
- The inner `[$groupBy] ?? null` additionally guards a key the submission may simply not contain (schemaless blob) — reachable, correct.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

#### R2-K4 — `is_array($value)` in `InsightCorpus::textValues` (:79) and `CategorizeSubmissionsTool::groupKeys` (:124) — KEEP (multi-value fields stored as arrays)
- The submission `data` blob stores multi-value fields (checkbox / multi-select) as arrays and scalar fields as strings — confirmed by the parallel handling in `SubmissionQueryBuilder::applyFieldMatch` (`:77-82`, `is_array($actual)` for multi-value). So a `data` value being an array is a reachable, normal state, and the `is_array` branch (implode to a space-joined string / fan a checkbox into every selected bucket) is the correct handling, not an over-defensive guard against an impossible state.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

### Guards — untrusted MCP arguments (KEEP)

#### R2-K5 — `isset(...)` / `is_string(...)` / `is_array(...)` over `$arguments` — KEEP (untrusted MCP input)
Examples: `is_array($arguments['fieldMatch'] ?? null)` (`DetectSpamPatternsTool.php:95`, `CategorizeSubmissionsTool.php:74`, `SummarizeSubmissionsTool.php:75`); `is_array($arguments['fields'] ?? null)` (`SummarizeSubmissionsTool.php:116`); the `resolveForm` / `resolveGroupBy` guards (`DetectSpamPatternsTool.php:188,192`; `CategorizeSubmissionsTool.php:156,175,179`; `SummarizeSubmissionsTool.php:135,139`); `max(1, (int)($arguments['linkThreshold'] ?? ...))` (`DetectSpamPatternsTool.php:99`).
- `$arguments` is the decoded MCP `array<string,mixed>` straight off the wire (`McpServer.php:243`), validated only loosely against `inputSchema`. A client can send a wrong type, omit a key, or send an empty string. These guards are exactly the round-1 untrusted-`$arguments` KEEP category and the task names them as explicit-KEEP. The `max(1, (int)...)` clamp also keeps a hostile `linkThreshold` of `0`/negative from flagging everything — intentional.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

### Guards — decoded stored field config (KEEP)

#### R2-K6 — `(string)($row['name'] ?? '')` / `(string)($row['type'] ?? '')` in `InsightCorpus::fieldTypes` — KEEP (stored-config category)
`InsightCorpus.php:35-37`.
- `Form::getFields()` returns `array<int,array<string,mixed>>` rows assembled from the structure cache / decoded JSON config (`elements/Form.php:78-88`). A row missing `name`/`type`, or holding a non-string, is the same legacy/corrupt-stored-config risk that round-1 L-1 (`FieldQueryHelper:73`) established as a KEEP category. The `?? ''` + `if ($handle !== '')` skip is the right tolerant handling for decoded stored data.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

### Guards — nullable element-query results (KEEP)

#### R2-K7 — `!$form instanceof Form` / `$form->handle === null` in resources + `resolveForm` — KEEP (nullable query contract)
`FormSchemaResource.php:40,72`; `SubmissionsDatasetResource.php:46,78`; `$f instanceof Form ? $f : null` in every `resolveForm` (`DetectSpamPatternsTool.php:190,194`; `CategorizeSubmissionsTool.php:177,181`; `SummarizeSubmissionsTool.php:137,141`); `$form instanceof Form` in `resolveHandles` (`SummarizeSubmissionsTool.php:121`) and the tools' `call()` (e.g. `DetectSpamPatternsTool.php:102`).
- `Form::find()->...->one()` returns `?ElementInterface`; in `list()` the iterated set is `Element[]` typed loosely so the `instanceof` narrows for static analysis and skips a handle-less form (a form with `handle === null` is in-progress/invalid and can't form a `form://{handle}` URI). All reachable nullable-contract guards.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

### Guards / URI parsing — untrusted `$uri` (KEEP)

#### R2-K8 — empty-handle guards after `substr($uri, ...)` in both resources — KEEP (untrusted `$uri`)
`FormSchemaResource.php:67`, `SubmissionsDatasetResource.php:73` — `if ($handle === '') return ['isError'=>true,...]`.
- `$uri` is untrusted MCP input; a caller can send `form://` with no handle. The guard returns the provider's in-band error payload (which `McpServer::handleResourceRead` then maps to a JSON-RPC error). This is the explicit-KEEP untrusted-`$uri` category.
- **Recommendation:** KEEP.
- **Confidence:** KEEP — high.

### LOW — cosmetic only (not error-hiding, not removal targets under #6)

#### R2-L1 — `($submission->data ?? [])[$groupBy] ?? null` double-fallback — LOW (cosmetic)
`CategorizeSubmissionsTool.php:123`. The outer `?? null` is mildly redundant *in spirit* with the `?? []`, but both are needed: `?? []` guards null `data`, the trailing `?? null` guards a missing `$groupBy` key in a present array. Not redundant on inspection — leave as-is. Recorded only so a future sweep doesn't mistake it for double-defensiveness.
- **Recommendation:** KEEP (no change).
- **Confidence:** LOW.

#### R2-L2 — empty-`$handles` sentinel comment vs return type in `InsightCorpus` — LOW (doc nit)
`InsightCorpus.php:46-48` docblock says `freeTextHandles` "returns null so callers fall back" but the method is typed `: array` and returns `[]` (empty list as the sentinel, which the callers correctly treat as "every string is text", e.g. `textValues` `:76`). The behaviour is correct and consistent; only the stale "returns null" wording in the docblock is inaccurate. Pure documentation nit, no code/behaviour issue.
- **Recommendation:** Optional one-line docblock fix (s/returns null/returns an empty list/). Not a concern-#6 construct.
- **Confidence:** LOW.

---

## High-confidence implementation checklist

**There are 0 HIGH-confidence purposeless constructs to remove in the new MCP insight-tools + resources feature.**

The new aggregation code is written total (no swallow-and-continue, no broad catches around pure in-memory work), and every guard traced back to a reachable untrusted-input, nullable-`?array`, decoded-stored-config, nullable-element-query, or documented-union-return state — all KEEP categories carried over from round 1.

No mechanical removal is behaviour-safe. Optional, non-#6 polish only:

- [ ] **R2-L2 (optional doc nit):** correct the stale "returns null" wording in `InsightCorpus::freeTextHandles` docblock (`InsightCorpus.php:46-48`); it returns an empty list. No code change.

All other in-scope sites (R2-K1 … R2-K8): **KEEP**, recorded above so they are not re-flagged.
