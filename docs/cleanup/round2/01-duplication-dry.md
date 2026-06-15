# Code Duplication & DRY — Assessment, Round 2 (Concern #1)

Plugin: **Simple Form** (`plugins/simple-form`, Craft CMS 5, PHP 8.2)
Phase: **Assessment only** — no source files were edited. Every cited site was opened and verified firsthand against the current tree; line numbers are exact at time of review.
Scope: **(A)** the new, never-audited MCP "insight tools" + "resources" feature, and **(B)** the round-1 DEFERRED dup items (#6 HIGH part, #8, #9), re-verified against the current (post-a56e6ed) tree.

This report does NOT re-flag anything already fixed in round 1 or marked KEEP there (e.g. the field-type `getOptions()`/`validate()`/length extractions, the `VALID_TYPES`/`OPTION_TYPES` registry consolidation, the `ToolInterface` per-tool contract, the `present()`/`build()` centralisation).

---

## Summary

The MCP layer is, as in round 1, deliberately well-factored: `SubmissionQueryBuilder` (build/applyFieldMatch/present), `FormPresenter`, and `FieldOps` already absorb the hard, shared work, and `McpServer::result()/error()` already single-source the JSON-RPC envelope (so there is **no** repeated envelope shaping to extract — see KEEP). The new insight + resource code, however, was bolted on without reusing those seams in three concrete places, and it re-opened one duplication round 1 had already noted as borderline (the submission-tool preamble, finding R1-#9), pushing that pattern from 3 sites to **6**.

The genuine new/unblocked duplications cluster into four groups:

1. **`resolveForm(array $arguments, array $submissions)` is byte-identical in all 3 insight tools** (`DetectSpamPatternsTool`, `CategorizeSubmissionsTool`, `SummarizeSubmissionsTool`) — and it re-implements, with `siteId('*')->status(null)`, the same form-by-id / form-by-handle resolution that `SubmissionQueryBuilder::build()` already does internally. This is the single highest-value new win. **HIGH.**

2. **The submission-tool preamble** (`build → is_array bail → with(['form']) → fieldMatch extract → applyFieldMatch(query->all(), …)`) now repeats across **6** tools: the 3 round-1 submission tools *and* the 3 new insight tools. Round 1 logged this LOW at 3 sites; at 6 sites with the new tools copying it verbatim, the case for a `buildWithForm()` (or `fetch()`) helper on `SubmissionQueryBuilder` is stronger. **MEDIUM.**

3. **The two resource providers** (`FormSchemaResource`, `SubmissionsDatasetResource`) duplicate `scheme()`, `handles()`, the `list()` loop over all forms, and the `read()` preamble (strip scheme, missing-handle guard, `Form::find()->siteId('*')->status(null)->handle()->one()`, not-found guard). A small abstract base (`AbstractFormResource`) collapses all of it. **MEDIUM** (new interface/base; mechanical bodies).

4. **Round-1 deferred items, re-verified:**
   - **R1-#6 HIGH part** — the 3 `supportedSiteIds()` normalizers: confirmed still triplicated; the propagation→siteId *normalization loop* is identical, the fallback differs. Extract just the loop. **HIGH.**
   - **R1-#8** — the "resolve form by id-or-handle" block in `GetFormTool`/`DeleteFormTool`/`UpdateFormTool`: confirmed byte-identical (14 lines × 3). **MEDIUM** (still spans 3 files + a new helper signature, as round 1 graded it; promotable to HIGH if `FormPresenter::resolveByIdOrHandle` is accepted as the home).
   - **R1-#9** — folded into group 2 above (same pattern, now wider).

**HIGH-confidence items logged: 2** (Finding #A1 `resolveForm` consolidation; Finding #B1 `supportedSiteIds` normalizer). The rest are MEDIUM (safe, but cross-file / introduce a new helper or base, so not "purely mechanical").

---

## Findings — (A) MCP insight tools + resources

### Finding #A1 — `resolveForm()` byte-identical across all 3 insight tools (and re-implements `SubmissionQueryBuilder` form resolution) — **HIGH**

**Duplicate sites (byte-identical bodies):**
- `src/mcp/tools/DetectSpamPatternsTool.php:186-198` (`resolveForm`)
- `src/mcp/tools/CategorizeSubmissionsTool.php:173-185` (`resolveForm`)
- `src/mcp/tools/SummarizeSubmissionsTool.php:133-146` (`resolveForm`)

**Shared shape (identical in all three):**
```php
if (isset($arguments['formId'])) {
    $f = Form::find()->siteId('*')->status(null)->id((int)$arguments['formId'])->one();
    return $f instanceof Form ? $f : null;
}
if (isset($arguments['form']) && is_string($arguments['form']) && $arguments['form'] !== '') {
    $f = Form::find()->siteId('*')->status(null)->handle($arguments['form'])->one();
    return $f instanceof Form ? $f : null;
}
$first = $submissions[0] ?? null;
return $first?->getForm();
```
The only inter-file difference is a one-line code comment in `SummarizeSubmissionsTool` ("Fall back to the form of the first submission…"); the executable code is identical.

Note the partial overlap with `SubmissionQueryBuilder::build()` (`support/SubmissionQueryBuilder.php:30-38`): that method already resolves `formId`/`form` (by id / by handle, same `siteId('*')->status(null)`) to filter the query. The insight tools resolve the form a *second* time to read its schema. The "fall back to first submission's form" step is the only genuinely new bit.

**Proposed fix:** Add one static helper to the existing `InsightCorpus` support class (the natural home — it already owns `fieldTypes()`/`freeTextHandles()`):
```php
/** @param list<Submission> $submissions */
public static function resolveForm(array $arguments, array $submissions): ?Form
```
containing the exact current body. Each tool's private `resolveForm()` is deleted and call sites become `InsightCorpus::resolveForm($arguments, $submissions)`. Pure copy-paste removal — bodies are identical, so behaviour is provably preserved.

**Why it reduces complexity:** the id/handle/first-submission resolution rule lives once, next to the other schema-resolution helpers the same tools already share. Removes ~39 lines of exact triplication.

**Confidence: HIGH** — mechanical, behaviour-preserving, single obvious home, no callers outside the three tools.

---

### Finding #A2 — submission-tool preamble now duplicated across 6 tools (was R1-#9, LOW at 3) — **MEDIUM**

**Duplicate sites:**
- `src/mcp/tools/QuerySubmissionsTool.php:77-85`
- `src/mcp/tools/ExportSubmissionsTool.php:66-75`
- `src/mcp/tools/SubmissionStatsTool.php:51-60`
- `src/mcp/tools/SummarizeSubmissionsTool.php:67-76` **(new)**
- `src/mcp/tools/CategorizeSubmissionsTool.php:66-75` **(new)**
- `src/mcp/tools/DetectSpamPatternsTool.php:87-96` **(new)**

**Shared shape (verbatim in all six):**
```php
$built = SubmissionQueryBuilder::build($arguments);
if (is_array($built)) {
    return $built;                         // error payload
}
/** @var SubmissionQuery $query */
$query = $built;
$query->with(['form']);

$fieldMatch = is_array($arguments['fieldMatch'] ?? null) ? $arguments['fieldMatch'] : [];
```
Five of the six then immediately do `SubmissionQueryBuilder::applyFieldMatch($query->all(), $fieldMatch)` (Query is the lone exception — it pages in the DB when `fieldMatch === []`, see line 90, so it can't pre-fetch `->all()`).

**Proposed fix (two thin accessors on `SubmissionQueryBuilder`, no behaviour change):**
- `buildWithForm(array $args): SubmissionQuery|array` — does `build()` + the `is_array` bail is left to the caller (it must stay `return`-able), OR returns the query already `->with(['form'])`-eager-loaded. Cleanest form:
  ```php
  $query = SubmissionQueryBuilder::build($arguments);
  if (is_array($query)) return $query;
  $query->with(['form']);
  $fieldMatch = SubmissionQueryBuilder::fieldMatch($arguments);
  ```
  where `fieldMatch(array $args): array` wraps the `is_array(... ?? null) ? ... : []` coercion.
- For the 5 fetch-everything tools, optionally add `fetch(array $args): array|array` returning `applyFieldMatch(build()->with(['form'])->all(), fieldMatch())` — collapsing the whole preamble to one call + the `is_array` bail. Keep `QuerySubmissionsTool` on the lower-level `build()` because it pages.

**Why it was LOW in round 1 and is MEDIUM now:** round 1 (3 sites) judged the saving too small and the tools diverging immediately after. With 3 more verbatim copies added by the insight feature, the drift surface doubled — the `with(['form'])` eager-load and the `fieldMatch` coercion are exactly the kind of thing one tool will forget. The `fieldMatch()` coercion accessor alone is a clean, near-mechanical win; the `fetch()`/`buildWithForm()` wrapper is the judgement call.

**Confidence: MEDIUM** — safe and low-risk, but spans 6 files and the right helper signature is a small design decision (Query's paging exception means it isn't a single uniform `fetch()`). Recommend at minimum the `fieldMatch()` accessor (mechanical) and `buildWithForm()`.

---

### Finding #A3 — resource providers duplicate scheme/handles/list-loop/read-preamble — **MEDIUM**

**Duplicate sites (near-identical across the two providers):**
- `scheme()` — `FormSchemaResource.php:22-25` vs `SubmissionsDatasetResource.php:28-31` (return a `SCHEME` const).
- `handles()` — `FormSchemaResource.php:56-59` vs `SubmissionsDatasetResource.php:62-65` (`str_starts_with($uri, self::SCHEME . '://')`) — **byte-identical** modulo the const.
- `list()` loop — `FormSchemaResource.php:35-54` vs `SubmissionsDatasetResource.php:41-60`: both `Form::find()->siteId('*')->status(null)->all()`, skip non-`Form`/null-handle, then build a descriptor `{uri, name, title, description, mimeType}` from `$form->name ?? $form->handle` / `$form->title ?? …`. Same skeleton; only the per-resource `name`/`title`/`description` wording and `MIME` differ.
- `read()` preamble — `FormSchemaResource.php:66-74` vs `SubmissionsDatasetResource.php:72-80`: **byte-identical**:
  ```php
  $handle = substr($uri, strlen(self::SCHEME . '://'));
  if ($handle === '') {
      return ['isError' => true, 'error' => 'Missing form handle in URI: ' . $uri];
  }
  $form = Form::find()->siteId('*')->status(null)->handle($handle)->one();
  if (!$form instanceof Form) {
      return ['isError' => true, 'error' => 'Form not found: ' . $handle];
  }
  ```
  Only the payload-shaping *after* this preamble differs (FormPresenter::form vs SubmissionQueryBuilder dataset).
- The JSON-encode-into-`contents` tail (`['contents' => [['uri'=>$uri,'mimeType'=>self::MIME,'text'=>json_encode(…, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)]]]`) is also identical (`FormSchemaResource.php:79-85` vs `SubmissionsDatasetResource.php:105-111`).

**Proposed fix:** Introduce `abstract class AbstractFormResource implements ResourceProviderInterface` in `src/mcp/resources/` that:
- holds the `MIME` const and an abstract `protected function scheme(): string` / `protected const`-style scheme accessor;
- implements `handles()` (the `str_starts_with` check) and `scheme()` once;
- implements a `protected function resolveForm(string $uri): Form|array` doing the strip + missing-handle guard + lookup + not-found guard (returns `Form` or the error payload);
- implements a `protected function contents(string $uri, array $payload): array` doing the json_encode-into-`contents` tail;
- implements `list()` against an abstract `protected function describe(Form $form): array` that each subclass fills with its `name`/`title`/`description`.

Each concrete provider then only declares its scheme/scope/mime and the two payload-shaping methods. Removes the duplicated handle-parsing, the all-forms list loop, and the contents tail. **Note:** the two providers carry genuinely different scopes (`forms:manage` vs `submissions:read`) and different payloads — those stay overridden; only the plumbing is shared. Not false-DRY: the plumbing is identical, the policy is intentionally per-subclass.

**Why it reduces complexity:** the URI-parsing rule, the "list every form as a resource" rule, and the contents envelope each get one home; adding a 3rd resource scheme (likely, given the interface exists for exactly that) becomes "declare scheme + payload" instead of re-copying the plumbing a third time.

**Confidence: MEDIUM** — bodies are identical/near-identical so the merge is safe, but it introduces a new abstract base and touches the interface's two implementers; not "purely mechanical". Strong candidate.

---

### Finding #A4 — `MAX_ROWS = 500` re-declared per insight tool / resource — **LOW**

**Sites:** `SummarizeSubmissionsTool.php:23`, `CategorizeSubmissionsTool.php:23`, `SubmissionsDatasetResource.php:26` all `private const MAX_ROWS = 500;` (and `DetectSpamPatternsTool.php:36` uses `1000` — intentionally different, a wider scan). The 500-cap is a shared corpus-safety policy expressed in 3 places.

**Proposed fix:** Hoist a single `InsightCorpus::MAX_ROWS = 500` and reference it from the three 500-cappers; leave `DetectSpamPatternsTool`'s 1000 as its own (documented) override. Marginal.

**Confidence: LOW** — small payoff, and a per-tool cap is arguably a per-tool concern. Log it; don't bundle into the mechanical pass.

---

## Findings — (B) Round-1 deferred items, re-verified against current tree

### Finding #B1 — `supportedSiteIds()` propagation→siteId normalizer triplicated (R1-#6 HIGH part) — **HIGH**

**Duplicate sites (re-verified, line numbers drifted slightly since round 1):**
- `src/controllers/FieldsController.php:276-288` — `supportedSiteIds(int $formId, int $currentSiteId)`; loads form, fallback = `[$currentSiteId]`.
- `src/mcp/tools/support/FieldOps.php:252-265` — `static supportedSiteIds(int $formId)`; loads form, fallback = `[primarySite->id]`.
- `src/services/FieldSyncService.php:184-191` — `supportedSiteIds(Form $form, int $currentSiteId)`; form already passed in, fallback = `[$currentSiteId]`.

**Shared core (identical in all three):**
```php
$ids = [];
foreach ($form->getSupportedSites() as $entry) {
    $ids[] = is_array($entry) ? (int)$entry['siteId'] : (int)$entry;
}
// fallback when $ids === []
```
The wrappers legitimately differ (one already has the `Form`, the other two load it by id; fallback is current-site vs primary-site). The **normalization loop** is the duplicated invariant.

**Proposed fix (conservative — extract only the loop):** Add one static helper, e.g. on `FieldOps` (already the MCP field-write hub) or on the `HasPropagation` trait:
```php
/** @return list<int> */
public static function siteIdsFromSupportedSites(Form $form): array
{
    $ids = [];
    foreach ($form->getSupportedSites() as $entry) {
        $ids[] = is_array($entry) ? (int)$entry['siteId'] : (int)$entry;
    }
    return $ids;
}
```
Each existing method keeps its own form-loading + fallback (those differ by design) but replaces the loop with `$ids = FieldOps::siteIdsFromSupportedSites($form);` then `return $ids ?: [$fallback];`. This is the exact "HIGH part" round 1 flagged and explicitly excluded from the riskier write-path merge.

**Why it reduces complexity:** the `is_array($entry) ? (int)$entry['siteId'] : (int)$entry` propagation-shape rule (which encodes a Craft `getSupportedSites()` quirk) lives once instead of three copies that must agree.

**Confidence: HIGH** — the loop body is byte-identical; extracting just it (not the differing fallbacks) is mechanical and behaviour-preserving. Do NOT bundle the surrounding DB-write consolidation (round-1 #6 MEDIUM part) — still out of scope.

---

### Finding #B2 — "resolve form by id-or-handle" block in 3 form tools (R1-#8) — **MEDIUM** (promotable to HIGH)

**Duplicate sites (re-verified, byte-identical):**
- `src/mcp/tools/GetFormTool.php:53-66`
- `src/mcp/tools/DeleteFormTool.php:68-80`
- `src/mcp/tools/UpdateFormTool.php:71-83`

**Shared shape (identical in all three):**
```php
$query = Form::find()->siteId('*')->status(null)->unique();
if (isset($arguments['id'])) {
    $query->id((int)$arguments['id']);
} elseif (isset($arguments['handle']) && is_string($arguments['handle'])) {
    $query->handle($arguments['handle']);
} else {
    return ['isError' => true, 'error' => 'Provide either "id" or "handle".'];
}
$form = $query->one();
if (!$form instanceof Form) {
    return ['isError' => true, 'error' => 'Form not found.'];
}
```
(`UpdateFormTool` continues afterward with a per-site re-load; the resolution block above is shared.)

**Proposed fix:** Add `FormPresenter::resolveByIdOrHandle(array $arguments): Form|array` (returns the `Form` or the error payload), mirroring the well-liked `SubmissionQueryBuilder::build()` query-or-error-payload pattern. Each tool collapses to:
```php
$form = FormPresenter::resolveByIdOrHandle($arguments);
if (is_array($form)) return $form;
```
`FormPresenter` is the right home (it already owns form→shape for these same tools). Note: `DetectSpamPatternsTool::resolveForm` / the insight `resolveForm` (Finding #A1) use `id()`/`handle()` *without* `->unique()` and with a first-submission fallback — they are a *different* resolver (no error payload, different default), so do NOT try to merge #A1 and #B2 into one helper; keep them separate.

**Why it reduces complexity:** one definition of the id/handle branch and the two standard error strings; consistent error vocabulary across the form tools.

**Confidence: MEDIUM** (as round 1) — safe and low-risk but spans 3 files and adds a new helper signature, so not purely mechanical. If the team accepts `FormPresenter::resolveByIdOrHandle` as the canonical home, this is effectively a HIGH mechanical extraction.

---

## Items reviewed and deliberately marked KEEP (not duplication / false-DRY)

- **`McpServer::result()` / `error()` JSON-RPC envelope** (`McpServer.php:372-388`) — already the single source for `{jsonrpc, id, result|error}`; every dispatch path calls them. There is **no** repeated envelope shaping to extract. The brief asked to check for "repeated JSON-RPC envelope shaping" — there is none. KEEP.
- **`McpServer` tool-lookup loop vs resource-provider-lookup loop** (`handleToolCall` name-match `:211-217` vs `handleResourceRead` handles-match `:319-325`) — structurally similar `foreach … break` finders, but over different collections, different match predicates (`name() ===` vs `handles()`), and different not-found errors. Folding them into a generic finder would add a typed-callback indirection for ~5 lines each. Not worth it. KEEP.
- **`scope-denied` audit `Craft::info(...)` blocks** (tool denial `:226-236` vs resource denial `:334-343`) — same logging *shape*, but different message text and identifiers and they live on separate dispatch paths; the duplication is 1 `sprintf` call. Borderline; extracting a `logScopeDenied()` is possible but marginal. KEEP (or LOW at most).
- **The three insight tools' `inputSchema()` using `QuerySubmissionsTool::filterProperties() + [...]`** — this is the *correct* reuse already in place (the shared filter schema is single-sourced via `filterProperties()`); each tool only adds its own extra property. Not duplication. KEEP.
- **`InsightCorpus::FREE_TEXT_TYPES` / `OPTION_TYPES`** — distinct from round-1 finding #7's `FieldOps::OPTION_TYPES`: `InsightCorpus::OPTION_TYPES = ['select','radio','checkbox']` is value-equal to `FieldOps::OPTION_TYPES`. **Worth a NOTE:** these two could converge on one constant, but they serve different layers (field-write validation vs corpus grouping) and `InsightCorpus` also defines `FREE_TEXT_TYPES` which `FieldOps` doesn't. Low-value, slight coupling risk — borderline KEEP / optional LOW. Flagging it so it's not lost, but not recommending the merge in the mechanical pass.
- **`resolveGroupBy` / `groupKeys` / `shapeGroups`** (CategorizeSubmissionsTool) and `resolveHandles` (SummarizeSubmissionsTool) — tool-specific shaping, not shared with siblings. KEEP.

---

## High-confidence implementation checklist

Only HIGH-confidence, behaviour-preserving items. Each is mechanical; run `composer test` (and the `tests/integration` Codeception suite) after each step.

1. **Extract insight-tool `resolveForm()` (Finding #A1).** Add `public static function resolveForm(array $arguments, array $submissions): ?Form` to `src/mcp/tools/support/InsightCorpus.php` with the exact current body. Delete the private `resolveForm()` from `DetectSpamPatternsTool` (`:186-198`), `CategorizeSubmissionsTool` (`:173-185`), `SummarizeSubmissionsTool` (`:133-146`) and replace call sites with `InsightCorpus::resolveForm($arguments, $submissions)`. Bodies identical → no behaviour change.

2. **Extract the `supportedSiteIds` normalization loop (Finding #B1).** Add `public static function siteIdsFromSupportedSites(Form $form): list<int>` (the `foreach … is_array($entry) ? … : …` loop) to `FieldOps` (or `HasPropagation`). In each of `FieldsController::supportedSiteIds` (`:276-288`), `FieldOps::supportedSiteIds` (`:252-265`), `FieldSyncService::supportedSiteIds` (`:184-191`), replace the inline loop with a call to the helper, keeping each method's own form-loading and `?: [$fallback]` (the fallbacks differ by design — do NOT unify them).

> Recommended-but-MEDIUM (do as a follow-up, not bundled into the mechanical pass):
> - **#B2** `FormPresenter::resolveByIdOrHandle()` for the 3 form tools (promotable to HIGH once the helper home is agreed).
> - **#A2** `SubmissionQueryBuilder::fieldMatch()` accessor (mechanical) + `buildWithForm()` for the 6-tool preamble.
> - **#A3** `AbstractFormResource` base for the 2 resource providers.

Deferred / optional (LOW): **#A4** (`MAX_ROWS` hoist), the `InsightCorpus::OPTION_TYPES`↔`FieldOps::OPTION_TYPES` convergence note, and the `logScopeDenied()` micro-extraction.
