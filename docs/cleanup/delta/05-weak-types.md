# Delta Cleanup 05 — Weak Types

Delta scope: PHP changed since `c5b8fe7` (68 files after excluding the WIP set —
`FormsController.php`, `elements/Form.php`, `db/FormQuery.php`,
`services/FormRenderService.php`). PHPStan level 7, `--memory-limit=1G`, currently green.
Read-only audit, building on `docs/cleanup/05-weak-types.md`.

---

## 1. Critical assessment

**The delta is clean on this dimension — nothing warrants a source change.** The
prior full audit found typing health "excellent" and the delta upholds it:

- **Bare `@param array` / `@return array` (no shape): 0.** Every array docblock added
  in the delta carries a generic or shape (`array<string, mixed>`, `list<string>`,
  `list<array{key: string, messages: list<string>}>`, `array<int, mixed>`).
  Spot-checked new methods: `SafeUrl::resolveHostIps` (`@return list<string>`),
  `SafeUrl::guzzlePinDnsOptions` (`@return array<string, mixed>`),
  `Submission::defineSortOptions` (`@return array<string, mixed>`),
  `FormMutations::buildValueMap` (`@return array<int, mixed>`),
  `McpController::jsonRpcError` (`@return array<string, mixed>`). All shaped.
- **`@var mixed`: 0. Bare `@param object` / `@return object`: 0. Bare `iterable`: 0.**
- **No new inline `@phpstan-ignore` / baseline suppressions.** `phpstan.neon` unchanged
  on the suppression front (the 5 documented framework false-positives stand).
- **Bare `callable`: 1**, `SafeRenderService::withSandbox(..., callable $render)` —
  already flagged-as-legit in the prior report; not delta-new behavior.
- **No untyped params and no missing return types** anywhere in the delta (full
  single-line + multiline signature scan returned 0).
- **`mixed` in signatures**: every occurrence in the delta is either (a) a field-type
  value handler, (b) a framework-override slot, (c) a `Db::parseParam`-style element
  query param, or (d) a raw GraphQL/HTTP input narrowing layer — all of which the
  prior report already established as *correct* `mixed`. The delta added a few of each;
  none is strengthenable.

### Delta-new `mixed` slots, all verified correct (do NOT change)

- **`SubmissionQuery::$userId/$paymentStatus/$orderId` + setters
  `userId/paymentStatus/orderId(mixed $value = null)`** (`src/elements/db/SubmissionQuery.php:21-23,57,67,77`).
  Canonical Craft element-query pattern: these feed `Db::parseParam()` and must accept
  `int|string|list|':empty:'|'not …'|range` etc. Core `ElementQuery::$id`/`$status` are
  `mixed` for the same reason. Already documented in each method's docblock
  (`Accepts any value Db::parseParam() understands`). **Leave as-is.**
- **`FormMutations::buildValueMap(mixed $inputValues)`** (`:268`). `$inputValues` is the
  raw GraphQL `values` argument (unknown shape); the method *is* the narrowing layer
  (`is_array(...)`/`is_array($entry)` guards). Return `array<int, mixed>` is accurate —
  values are `$entry['values']` (array) or `$entry['value'] ?? null` (mixed). **Leave.**
- **`ConditionalEvaluator::numericCompare(string, mixed $actual, mixed $expected)`**
  (`:185`) and sibling comparators — operate over arbitrary stored field values across
  all field types; `mixed` is the correct domain. **Leave.**
- **`SubmissionCsv::collectAssetIds(mixed $entry, array &$ids)`** (`:248`) and the other
  `SubmissionCsv` `mixed $entry` helpers — `$entry` is a stored data entry that is
  polymorphic across legacy/partial rows (`{label,type,value}` or bare scalar), as the
  docblocks state. **Leave.**
- **`NotificationsController::nullableString(mixed $value)`** (`:186`). Callers pass
  `Request::getBodyParam()`, whose Craft return type is `mixed`
  (`vendor/craftcms/cms/src/web/Request.php:936`). `mixed` in is required. **Leave.**

---

## 2. High-confidence patch list

**None.** No gate-safe, high-confidence strengthening exists in the delta.

### LOW confidence — skip

**L1 — `McpController::jsonRpcError(mixed $id, …)`** (`src/controllers/McpController.php:155`).
All four call sites pass literal `null` (lines 84, 94, 106, 116). JSON-RPC 2.0 restricts
`id` to String|Number|Null, so `string|int|null` would be a tighter, accurate type and
*would* stay PHPStan-green (every current arg is `null`, a valid member). However:
the method's purpose is to echo back a request id, and the current `mixed` is the same
defensible "untrusted-input passthrough" pattern the prior report accepted for
`McpServer::error/result($id)`. The signature itself was **not changed in the delta**
(only surrounding lines moved), so touching it now is scope-creep on a cosmetic. **Skip.**

---

## Verdict

**0 high-confidence patches.** The delta introduces no new weak typing; all delta `mixed`
slots are framework-mandated, query-param, or raw-input narrowing layers and are correct.
The one nameable tightening (L1, `jsonRpcError $id` → `string|int|null`) is cosmetic,
out of delta scope, and consistent with the prior report's "leave passthrough `$id` as
`mixed`" stance — skip.
