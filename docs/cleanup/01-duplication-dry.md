# Code Duplication & DRY — Assessment (Concern #1)

Plugin: **Simple Form** (`plugins/simple-form`, Craft CMS 5, PHP 8.2)
Phase: **Assessment only** — no source files were edited. Every duplicate site below was opened and verified; line numbers are exact at time of review.

---

## Summary

The codebase is generally well-factored: the MCP layer already has shared support classes (`FieldOps`, `FormPresenter`, `SubmissionQueryBuilder`), field resolution funnels through one `FieldQueryHelper`, and the submit path is deliberately single-sourced (`SubmissionService::submit`). That intent is good — but a handful of genuine duplications remain.

The duplication clusters into three areas:

1. **Field types** — `getOptions()` is byte-for-byte triplicated; option-membership and min/max-length validation are copy-pasted; the `if ($value !== null && $value !== '')` guard is repeated 6×.
2. **Field write/validation logic** — `FieldsController`, `FieldOps`, and `FieldSyncService` each independently re-implement: the `supportedSiteIds()` helper (3 copies), the valid-types + option-types lists (3 hardcoded copies, the registry being the real source of truth), and the per-site insert/upsert DB writes.
3. **MCP tool boilerplate** — the "resolve form by id-or-handle", "load form by id + not-found guard", arg-coercion, and "build submission query then bail on error payload" preambles repeat across tools.

The highest-value, lowest-risk wins are the three field-type extractions (#1, #2, #3) and the `OPTION_TYPES`/`VALID_TYPES` constant consolidation (#7). The field-write consolidation (#5/#6) is real but riskier (two write paths have intentionally different transaction/semantics), so it is graded MEDIUM/LOW.

**HIGH-confidence items logged: 5** (Findings #1, #2, #3, #4, #7).

---

## Findings

### Finding #1 — `getOptions()` triplicated across choice field types — **HIGH**

**Duplicate sites (byte-identical bodies):**
- `src/fields/SelectFieldType.php:37-56`
- `src/fields/RadioFieldType.php:37-56`
- `src/fields/CheckboxFieldType.php:41-60`

**Shared shape:** all three are the *exact same* method — read `config['options']`, json-decode if string, then fold an array of `{value,label}` (array or object) into a `value => label` keyed array.

**Proposed fix:** Extract a single protected method into a new trait `HasOptions` (or onto the base `FieldType`) and `use` it from the three choice types. Pure copy-paste removal — the three bodies are identical, so behaviour is provably preserved. A trait is marginally preferable to the base class since only 3 of 8 field types need it, but either is safe.

**Why it reduces complexity:** removes ~40 lines of exact duplication and gives the option-normalization logic one home, so the (admittedly fiddly) array-vs-object parsing only has to be reasoned about / fixed once.

**Confidence: HIGH** — mechanical, behaviour-preserving, no callers outside the three classes.

---

### Finding #2 — Option-membership validation duplicated in Select + Radio — **HIGH**

**Duplicate sites (identical):**
- `src/fields/SelectFieldType.php:20-32` (`validate()`)
- `src/fields/RadioFieldType.php:20-32` (`validate()`)

**Shared shape:**
```php
$errors = parent::validate($value);
if ($value !== null && $value !== '') {
    $options = $this->getOptions();
    if (!in_array($value, array_keys($options))) {
        $errors[] = 'Please select a valid option.';
    }
}
return $errors;
```
Checkbox (`CheckboxFieldType.php:20-36`) is a *near* variant — it loops over an array of values and emits a slightly different message ("Please select valid options.").

**Proposed fix:** Pair this with Finding #1. Put a `protected function validateOptionMembership(mixed $value): array` on the shared trait/base (single-value form), and have Select + Radio call it. Keep Checkbox's multi-value variant as its own override (or add an `$allowMultiple` flag) — do NOT force the three into one method if it adds branching; the single-value extraction alone removes the two identical copies cleanly.

**Why it reduces complexity:** Select and Radio stop being copy-paste twins; the "is this a known option?" rule lives once.

**Confidence: HIGH** for the Select/Radio extraction. (Folding Checkbox in too is LOW — keep it separate.)

---

### Finding #3 — min/max length validation duplicated in Text + Textarea — **HIGH**

**Duplicate sites (byte-identical):**
- `src/fields/TextFieldType.php:24-35`
- `src/fields/TextareaFieldType.php:24-35`

**Shared shape:** identical minLength/maxLength block guarded by `if ($value !== null && $value !== '')`, same messages.

**Proposed fix:** Extract `protected function validateLength(string $value): array` onto the base `FieldType` (both already extend it) and call it from both. ~12 identical lines collapse to one call each.

**Why it reduces complexity:** the length rule has one definition; a future change (e.g. multibyte `mb_strlen`) is a one-line edit instead of a two-file edit that can silently drift.

**Confidence: HIGH** — identical bodies, common base class already present.

---

### Finding #4 — Repeated `if ($value !== null && $value !== '')` "has-value" guard — **HIGH**

**Duplicate sites (same guard opening every type-specific `validate()`):**
- `TextFieldType.php:24`, `TextareaFieldType.php:24`, `EmailFieldType.php:24`, `NumberFieldType.php:24`, `DateFieldType.php:24`, `SelectFieldType.php:24`, `RadioFieldType.php:24`, `CheckboxFieldType.php:24`

**Shared shape:** every subclass does `$errors = parent::validate($value);` then `if ($value !== null && $value !== '') { <type-specific> }`. The "skip type validation when empty" rule is re-expressed 8 times.

**Proposed fix (conservative):** Add a tiny `protected function hasValue(mixed $value): bool { return $value !== null && $value !== ''; }` to the base `FieldType` and replace the literal in each subclass. This is the *minimal* DRY move and is purely mechanical.

> Note: a more aggressive template-method (base `validate()` calling an abstract `validateValueOnly()` only when non-empty) would remove the `parent::validate()` + guard boilerplate entirely, but that restructures all 8 subclasses and is **not** recommended at HIGH — log it as a LOW/optional refinement.

**Confidence: HIGH** for the `hasValue()` helper substitution only.

---

### Finding #5 — Field validation logic triplicated: `FieldsController` / `FieldOps` / `FieldSyncService` — **MEDIUM**

**Duplicate sites:**
- `src/controllers/FieldsController.php:232-268` (`validateFieldInput`)
- `src/mcp/tools/support/FieldOps.php:72-107` (`validate`)
- `src/services/FieldSyncService.php:30-71` (`validate`)

**Shared shape:** all three validate the same rules — label required, handle required + regex `^[a-zA-Z_][a-zA-Z0-9_]*$`, handle uniqueness, type is one of the valid set, and option-types require a non-empty `options` array. `FieldsController::validateFieldInput` and `FieldOps::validate` are *near-identical* (both return a Craft-style `field => [msgs]` map and check DB uniqueness). `FieldSyncService::validate` differs meaningfully — it validates a whole *posted set* (in-set duplicate detection, positional `#n` messages, translated via `Craft::t`) rather than one field against the DB.

**Proposed fix:** Extract the *single-field* rules shared by `FieldsController` and `FieldOps` into one validator (e.g. a `FieldValidator::validateOne(...)` helper or fold the controller's path onto `FieldOps::validate`, which already exists for exactly this reason — the FieldOps docblock says it "mirrors" the controller). Leave `FieldSyncService::validate` as-is (different problem: set-replacement semantics, i18n messages); only share the *primitive* checks (handle regex, valid-types membership — see #7).

**Why it reduces complexity:** the handle-format rule, the uniqueness query, and the option-required rule currently live in 2–3 places that must be kept in lockstep; the docblocks already acknowledge the mirroring, which is a maintenance hazard.

**Confidence: MEDIUM** — `FieldsController` and `FieldOps` are genuinely consolidatable, but they return slightly different error vocab and one throws/JSONs while the other returns payloads; needs care so the controller's JSON contract is unchanged. `FieldSyncService` should be **KEPT separate**.

---

### Finding #6 — Per-site field DB writes + `supportedSiteIds()` triplicated — **MEDIUM (writes) / HIGH (supportedSiteIds helper)** 

**`supportedSiteIds()` duplicate sites (3 near-identical copies):**
- `src/controllers/FieldsController.php:276-288`
- `src/mcp/tools/support/FieldOps.php:252-265`
- `src/services/FieldSyncService.php:184-191`

All three do the same thing: iterate `$form->getSupportedSites()` (the `HasPropagation` trait), normalize each entry (`is_array($e) ? (int)$e['siteId'] : (int)$e`), and fall back to a single site when empty.

**Per-site insert/upsert duplicate sites:**
- `FieldsController.php:54-81` (insert structural + per-site rows) and `:125-146` (update + upsert)
- `FieldOps.php:116-157` (`add`) and `:165-192` (`update`)
- `FieldSyncService.php:107-156` (update/insert within the sync loop)

These share the same column shapes for `{{%simpleform_fields}}` and `{{%simpleform_fields_sites}}` (the same `name/required/config/sortOrder/dateUpdated` and `fieldId/siteId/label/helpText/uid` payloads, the same "encode-once config" comment copy-pasted verbatim).

**Proposed fix:**
- *(HIGH part)* Extract the `supportedSiteIds(Form $form, int $fallbackSiteId): array` normalization into one static helper (e.g. on `HasPropagation` or a `FieldOps::supportedSiteIds`) and have all three callers use it. The bodies are effectively identical; only the fallback argument differs.
- *(MEDIUM part)* Consider routing `FieldsController`'s add/edit/delete DB writes through the already-existing `FieldOps::add/update/delete` (they were written to mirror the controller). That removes the largest block of write duplication. Risk: the controller wraps writes in try/catch→JSON and uses the posted site as the upsert target; `FieldSyncService` runs inside an explicit DB transaction over a whole set. Don't naively merge the sync path.

**Why it reduces complexity:** there are currently three places that must agree on the exact column set and the propagation-to-site-ids rule; a schema or propagation change has to be made consistently in all three.

**Confidence:** the `supportedSiteIds` helper is **HIGH** (mechanical), but I've graded the overall finding **MEDIUM** because the write consolidation it's bundled with is the risky part. If implementing piecemeal, do the `supportedSiteIds` extraction alone as a safe win.

---

### Finding #7 — `VALID_TYPES` / `OPTION_TYPES` hardcoded in 3 places (registry is the real source) — **HIGH**

**Duplicate sites:**
- `src/controllers/FieldsController.php:256` — `['text','email','textarea','select','checkbox','radio','date','number']`
- `src/services/FieldSyncService.php:20` — `const VALID_TYPES = [...same list...]`
- `FieldsController.php:261` & `FieldSyncService.php:21` & `FieldOps.php:31` — `['select','checkbox','radio']` (option types)

Meanwhile `FieldOps::validTypes()` (`FieldOps.php:39-42`) already derives the valid set from the field-type **registry** (`FieldTypeRegistry::getAllFieldTypes()`) — the genuine single source of truth. The two hardcoded lists in the controller and sync service can silently drift from the registered types (e.g. adding a 9th field type updates the registry but not these literals).

**Proposed fix:**
- Replace the hardcoded `$validTypes` in `FieldsController` and `FieldSyncService::VALID_TYPES` with `FieldTypeRegistry::getAllFieldTypes()` (via `FieldOps::validTypes()` or a thin accessor).
- Hoist `OPTION_TYPES = ['select','checkbox','radio']` to a single shared constant (e.g. `FieldOps::OPTION_TYPES`, already defined at `FieldOps.php:31`) and reference it from the controller and sync service.

**Why it reduces complexity:** registering a new field type currently requires remembering to also edit two literal arrays; this makes the registry authoritative and removes the drift hazard. The replacement is value-equivalent today (lists already match), so it's behaviour-preserving.

**Confidence: HIGH** — the lists are provably identical to the registry's keys today; substitution is mechanical. (The JS copy at `src/templates/forms/edit.html:179` is a separate frontend concern and out of scope for this PHP pass — note only.)

---

### Finding #8 — MCP "resolve form by id-or-handle" block repeated — **MEDIUM**

**Duplicate sites (near-identical ~10-line blocks):**
- `src/mcp/tools/GetFormTool.php:53-66`
- `src/mcp/tools/DeleteFormTool.php:68-81`
- `src/mcp/tools/UpdateFormTool.php:71-83`

**Shared shape:** build `Form::find()->siteId('*')->status(null)->unique()`, branch on `id` vs `handle`, return `['isError'=>true,'error'=>'Provide either "id" or "handle".']` otherwise, then `->one()` + `['isError'=>true,'error'=>'Form not found.']` guard. (`AddFieldTool.php:74-77`, `ReorderFieldsTool.php:71-74`, `UpdateFieldTool.php:107-108` share the by-id-only "load form / not-found" half.)

**Proposed fix:** Add `FormPresenter::resolveByIdOrHandle(array $args): Form|array` (or a small `ToolForms` support helper) returning the `Form` or the error payload. The three tools collapse to `$form = ...; if (is_array($form)) return $form;`. This mirrors the existing, well-liked `SubmissionQueryBuilder::build()` pattern (which already returns query-or-error-payload — see #9).

**Why it reduces complexity:** one definition of the id/handle resolution + the two standard error strings; consistent error messages across tools.

**Confidence: MEDIUM** — safe and low-risk, but it spans 3 files and introduces a new helper signature, so not "purely mechanical". Good candidate but a notch below HIGH.

---

### Finding #9 — MCP submission-tool preamble repeated (build → bail → with(form) → applyFieldMatch) — **LOW**

**Duplicate sites:**
- `QuerySubmissionsTool.php:77-85`
- `ExportSubmissionsTool.php:66-74`
- `SubmissionStatsTool.php:51-59`

**Shared shape:**
```php
$built = SubmissionQueryBuilder::build($arguments);
if (is_array($built)) return $built;        // error payload
$query = $built;
$query->with(['form']);
$fieldMatch = is_array($arguments['fieldMatch'] ?? null) ? $arguments['fieldMatch'] : [];
```

**Proposed fix:** Add `SubmissionQueryBuilder::buildWithForm(array $args): SubmissionQuery|array` that does the `with(['form'])` for you, and a `SubmissionQueryBuilder::fieldMatch(array $args)` accessor. Saves ~3 lines per tool.

**Why graded LOW:** the saving is small, the three tools diverge immediately after this preamble (paging vs CSV vs aggregation), and `SubmissionQueryBuilder` already centralizes the hard part (filter interpretation). This is borderline "abstraction for its own sake" — log it, but it's optional.

**Confidence: LOW** (small payoff).

---

### Finding #10 — Option `{value,label}` normalization also lives in `FormGqlResolver` — **LOW / borderline KEEP**

**Sites:**
- `src/fields/{Select,Radio,Checkbox}FieldType.php::getOptions()` — produce a **keyed** `value=>label` map (for rendering).
- `src/gql/resolvers/FormGqlResolver.php:68-87` (`mapOptions`) — produce a **list** of `{label,value}` (for the GraphQL schema).

**Shared core:** both json-decode-if-string and filter for `{value,label}` entries. **But** the output shapes differ (keyed map vs ordered list) and they serve different consumers (HTML rendering vs GraphQL contract).

**Recommendation: largely KEEP.** Only the "decode options if string, keep entries that have both value+label" *primitive* is common; the shaping is intentionally different. If anything, extract just a tiny `normalizeRawOptions(mixed): list<array{value,label}>` that both call and then each re-shapes — but the payoff is marginal and risks coupling the GraphQL contract to the field-render path. Not recommended unless #1 is already done and the primitive falls out naturally.

**Confidence: LOW** — and partly a false-DRY warning.

---

## Items reviewed and deliberately marked KEEP (not duplication)

- **`ElementQueryHelper::forCurrentSite/forSite/forAllSites`** (`helpers/ElementQueryHelper.php`) — thin, intentional one-liners; not duplication.
- **`FormStructureService::getFieldSet` vs `getFieldSets`** — single vs batch (N+1 avoidance); the single delegates conceptually to the batched helper. Intentional, KEEP.
- **`FieldQueryHelper::fieldsForForm` → `fieldsForForms`** — already single-sourced (the singular delegates to the batch). Good, KEEP.
- **`SubmissionService::submit` / `createFromRequest`** — deliberately the *single* submit entry point shared by controller + GraphQL; the per-channel adapters (`SubmitController::fieldValues`, `FormMutations::resolveSubmit` value-building) are thin transport mapping, not business-logic duplication. KEEP.
- **MCP `name()/description()/inputSchema()/requiredScope()/call()` per tool** — this is the `ToolInterface` contract; one class per tool is the intended Craft/MCP shape, not duplication. KEEP.
- **`FormPresenter::fields` vs `FieldsController::fieldsToBuilderJson`** — both map field rows to a shape, but to *different* shapes (tool JSON vs builder JSON) for different consumers. Borderline; the field-set rows are already single-sourced via `FieldQueryHelper`, so KEEP.

---

## High-confidence implementation checklist

Only HIGH-confidence, behaviour-preserving items. Each is mechanical; run the test suite after each step.

1. **Extract `getOptions()` (Finding #1).** Create a trait `src/fields/HasOptions.php` (or add a protected method to `FieldType`) containing the exact current `getOptions()` body. Remove the three identical copies from `SelectFieldType.php:37-56`, `RadioFieldType.php:37-56`, `CheckboxFieldType.php:41-60` and `use` the trait. No behaviour change (bodies identical).

2. **Extract single-value option-membership check (Finding #2).** Add `protected function validateOptionMembership(mixed $value): array` (the Select/Radio block) to the shared trait/base; call it from `SelectFieldType::validate` and `RadioFieldType::validate`. Leave `CheckboxFieldType::validate` untouched.

3. **Extract length validation (Finding #3).** Add `protected function validateLength(string $value): array` to base `FieldType` (the minLength/maxLength block from `TextFieldType.php:25-34`). Call it from `TextFieldType::validate` and `TextareaFieldType::validate` (replacing lines 25-34 in each).

4. **Add `hasValue()` guard helper (Finding #4).** Add `protected function hasValue(mixed $value): bool` to base `FieldType` and replace the literal `$value !== null && $value !== ''` in all 8 field-type `validate()` methods with `$this->hasValue($value)`. Pure substitution. (Do NOT attempt the larger template-method restructure here.)

5. **Single-source the type lists (Finding #7).**
   - Replace the hardcoded array at `FieldsController.php:256` with the registry-derived set (`FieldOps::validTypes()` or a registry accessor).
   - Replace `FieldSyncService::VALID_TYPES` (`:20`) usage with the same registry source.
   - Reference the existing `FieldOps::OPTION_TYPES` (`FieldOps.php:31`) from `FieldsController.php:261` and `FieldSyncService.php:21` instead of re-listing `['select','checkbox','radio']`.
   - Verify the registry keys still equal the old literals (they do today) before/after — keep a test asserting the set.

> Bundled-but-safe sub-item (from Finding #6, HIGH portion): extract the 3 identical `supportedSiteIds()` normalizers into one helper. Safe to include in this pass if desired; it's mechanical. The surrounding DB-write consolidation is MEDIUM/LOW and intentionally excluded here.

Deferred (MEDIUM/LOW — do not bundle into the mechanical pass): Findings #5 (controller/FieldOps validate merge), #6 write-path consolidation, #8 (MCP form resolver helper), #9, #10.
