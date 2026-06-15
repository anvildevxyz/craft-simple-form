# Concern #2 (ROUND 2) — Type Definitions & Consolidation

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Scope (this pass):** (A) the new MCP "insight tools" + "resources" feature; (B) the round-1
type items that were deferred and are now unblocked (F1, F3, F4).
**Phase:** ASSESSMENT ONLY — no source files were edited. This document is the sole output.
**Date:** 2026-06-15
**Prior round:** `docs/cleanup/02-type-consolidation.md` (commit a56e6ed). Settled "already good"
items from that doc (`Scopes`, `SimpleFormObjectType` typed base, the support-class output
pattern, the registry as authoritative type source) are NOT re-flagged here.

> "Types" = PHP classes/interfaces/enums/value objects + the untyped associative-array shapes
> the codebase passes around in lieu of types.

---

## Summary

The new insight/resources slice (#66/#67) is well-factored on the output side: the 3 insight
tools all delegate text shaping to one `InsightCorpus` support class, and both resource
providers reuse the existing `FormPresenter` / `SubmissionQueryBuilder` presenters so a resource
and its sibling tool can't disagree about the JSON. That is the right pattern and most of it
needs no change.

The drift that DID accrete is in two places:

1. **A fourth copy of the `select/checkbox/radio` "option types" subset** landed on
   `InsightCorpus::OPTION_TYPES`, and it even uses a different element order than the other three
   copies. This folds straight into round-1 **F1** — which is still valid in the current tree —
   so F1's scope is now "4 PHP copies + 1 JS copy of the option subset, plus 2 copies of the
   8-type valid list, all collapsible onto the registry." **HIGH.**

2. **The two `ResourceProviderInterface` implementations are structurally near-identical**
   (scheme/MIME constants, `handles()`, the `://`-prefix URI parse, the "missing handle"/"form
   not found" error returns, and the whole `list()` loop over `Form::find()->siteId('*')`). The
   `read()`/`list()` result shapes (`array{contents:...}|array{isError:true,error:string}` and
   the per-resource descriptor) are declared informally. The interface docblock already pins the
   contracts as PHPStan-ish array shapes — promoting them to real `@phpstan-type` aliases and
   extracting an `AbstractFormResourceProvider` base is a clean, low-risk consolidation.
   **MEDIUM** (the shared base) / **MEDIUM** (the result-shape aliases).

The deferred round-1 items re-verify as follows against the current tree: **F1 still HIGH**
(line drift only; now widened by the InsightCorpus copy); **F4 still MEDIUM** (literal
`PropagationMethod` enum lists unchanged at `CreateFormTool:51-55` / `UpdateFormTool:50-54`);
**F3 still MEDIUM** (the resolved-field-row shape gained a *new* consumer — `InsightCorpus`
reads `$row['name']`/`$row['type']` — strengthening the case for the `@phpstan-type` alias but
not changing its risk profile).

Net new HIGH this round: **1** (F1, re-confirmed + widened). Everything else is MEDIUM/LOW.

---

## Findings

### N1 — `InsightCorpus::OPTION_TYPES` is a 4th copy of the option-type subset (folds into F1)  **[HIGH]**

`mcp/tools/support/InsightCorpus.php:23` adds `public const OPTION_TYPES = ['select', 'radio', 'checkbox']`.
That is now the **fourth** PHP declaration of the same closed-option subset, and the **only one
in a different element order**:

- `services/FieldSyncService.php:21` — `['select', 'checkbox', 'radio']`
- `controllers/FieldsController.php:261` — `['select', 'checkbox', 'radio']` (inline literal)
- `mcp/tools/support/FieldOps.php:31` — `['select', 'checkbox', 'radio']`
- `mcp/tools/support/InsightCorpus.php:23` — `['select', 'radio', 'checkbox']`  ← different order
- (`templates/forms/edit.html:179` — JS `['select', 'checkbox', 'radio']`, out of PHP scope)

The order difference is harmless today (every read is an order-insensitive `in_array(...)` /
membership loop — `InsightCorpus.php:161` in `CategorizeSubmissionsTool`, `InsightCorpus.php:56`
for the free-text complement), but it is exactly the kind of "these were meant to be the same
list" signal that justifies single-sourcing. And the free-text set
(`InsightCorpus::FREE_TEXT_TYPES = ['text','textarea','email']`, `:20`) is the *complement* of
the option set within the 8 registered types — today maintained by hand with no link to the
registry.

**Proposal:** roll this into F1 (below). The registry is the single source of truth; expose the
"option types" subset there once and point all PHP call sites — including `InsightCorpus` — at
it. `FREE_TEXT_TYPES` can stay literal (it is an insight-domain classification, not a structural
constraint) OR be derived as "registered types minus option types"; deriving is the cleaner
long-term shape but is optional and slightly less explicit — leave that to taste.

**Confidence: HIGH** (as part of F1 — strictly collapses duplication onto an existing source).

---

### F1 (re-verified, widened) — Field-type identifier list duplicated, despite an authoritative registry  **[HIGH]**

Still valid in the current tree; the registry-derived path (`FieldOps::validTypes()` →
`array_keys(...getAllFieldTypes())`, `mcp/tools/support/FieldOps.php:39-42`, used `:96`) remains
the template.

**8-type valid list — hard-coded copies:**
- `services/FieldSyncService.php:20` — `const VALID_TYPES = ['text','email','textarea','select','checkbox','radio','date','number']` (used `:58`).
- `controllers/FieldsController.php:256` — `$validTypes = [...same 8...]` (used `:257`).

**"Option types" subset — hard-coded copies:** the four PHP sites in **N1** above.

**Canonical source:** `services/FieldTypeRegistry.php:64` (`getAllFieldTypes()`); `init()`
(`:25-32`) registers exactly the 8.

**Drift risk:** adding a 9th field type means registering it in `FieldTypeRegistry::init()` AND
remembering to edit two unrelated literal arrays, or the CP batch-save (`FieldSyncService`) /
CP single-add (`FieldsController`) silently rejects it as "invalid type" while the MCP path
accepts it (it already derives from the registry).

**Proposal (canonical = the registry):**
- Replace `FieldSyncService::VALID_TYPES` usage and `FieldsController`'s inline `$validTypes`
  with the registry-derived list (mirror `FieldOps::validTypes()`).
- Introduce **one** "option types" declaration on `FieldTypeRegistry` — lowest-risk a
  `public const OPTION_TYPES` (or an `isOptionType(string $type): bool` accessor) — and point
  `FieldSyncService.php:21,62`, `FieldsController.php:261`, `FieldOps.php:31,100`, and
  `InsightCorpus.php:23,161` at it. (A per-field-type `requiresOptions(): bool` on the
  `FieldType` base is the more OO option but a larger change — leave for a follow-up.)

**Migration:** mechanical, behavior-preserving (same strings, same validation outcomes). No DB,
no schema, no public-API change. Smoke: add a field of each type via CP batch-save, CP
single-add, and MCP `add_field`; confirm select/checkbox/radio still require options and the
`categorize_submissions` auto-group still picks the first option field.

**Confidence: HIGH** — strictly removes duplication onto an existing single source of truth;
one path already does it this way.

---

### N2 — The two `ResourceProviderInterface` implementations are structurally near-identical  **[MEDIUM]**

`FormSchemaResource` and `SubmissionsDatasetResource` differ only in: the scheme string, the
descriptor text, and the body of `read()`. Everything else is duplicated verbatim:

- `private const SCHEME` + `private const MIME = 'application/json'` — both files (`FormSchemaResource.php:19-20`, `SubmissionsDatasetResource.php:23-24`).
- `scheme()` returning `self::SCHEME` — both.
- `handles()` = `str_starts_with($uri, self::SCHEME . '://')` — identical (`FormSchemaResource.php:56-59`, `SubmissionsDatasetResource.php:62-65`).
- `list()` loop over `Form::find()->siteId('*')->status(null)->all()` with the same
  `!$form instanceof Form || $form->handle === null` guard and the same descriptor keys
  (`uri/name/title/description/mimeType`) — `FormSchemaResource.php:35-54` vs `SubmissionsDatasetResource.php:41-60`.
- `read()` preamble: strip the `SCHEME . '://'` prefix, "Missing form handle" error, look up the
  form by handle, "Form not found" error — `FormSchemaResource.php:64-74` vs `SubmissionsDatasetResource.php:70-80`.

**Drift risk:** a third resource provider (likely, given the slice naming) copies this boilerplate
again; the URI-parse / not-found error wording / list-guard can diverge per provider. The URI
scheme is also **stringly-typed** — each provider hand-builds `self::SCHEME . '://'` in three
spots (`handles`, `list` uri, `read` substr length).

**Proposal:**
- Extract an `abstract class AbstractFormResourceProvider implements ResourceProviderInterface`
  that owns: `SCHEME`/`MIME` (template-method `scheme()`/`mimeType()`), `handles()`, the
  `parseHandle(string $uri): ?string` URI helper, the "missing handle"/"form not found" error
  returns, and the `list()` form-iteration (subclass supplies a `describe(Form $form): array`
  for the per-form descriptor and `readForm(Form $form, string $uri): array` for the contents).
  Both providers shrink to ~30 lines of their own logic.
- This also centralizes the `SCHEME . '://'` concatenation into one `prefix()` so the URI scheme
  stops being hand-spelled at 3 sites per provider.

**Migration:** behavior-preserving refactor; the JSON contract (`FormPresenter` / the
`submissions://` payload) is untouched. Run the MCP resources smoke (`resources/list`,
`resources/read` for both schemes, scope-gating) after.

**Confidence: MEDIUM** — clear duplication with a real third-provider drift risk, but it is a
2-implementation surface today so it sits just under HIGH. Do it when touching the resources
layer; pairs naturally with N3.

---

### N3 — Resource result/descriptor array shapes are declared informally  **[MEDIUM]**

`ResourceProviderInterface` documents its contracts as docblock array shapes but they are not
named `@phpstan-type`s, so `McpServer` consumes them as bare `array<string,mixed>` /
`list<array<string,mixed>>`:

- `read()` return: `array{contents:list<array<string, mixed>>}|array{isError:true,error:string}`
  (`ResourceProviderInterface.php:51-53`), consumed at `McpServer.php:359-365` via
  `array_key_exists('isError', $result)` then `$result['contents']` / `$result['error']`.
- `list()` return: `list<array<string, mixed>>` of descriptors with `uri/name/title/description/mimeType`
  (`ResourceProviderInterface.php:37-38`), aggregated at `McpServer.php:300-303`.
- The MCP "contents entry" `{uri, mimeType, text}` block (`FormSchemaResource.php:80-84`,
  `SubmissionsDatasetResource.php:106-110`) is hand-built identically in both providers.

This is the **same pattern round-1 praised** in `SimpleFormObjectType` (`@phpstan-type
GqlFieldDefinition` shared via `@phpstan-import-type`). The interface is an MCP *output contract*
— the kind the round-1 doc flagged as worth typing — so the alias has real value.

**Proposal:** declare on `ResourceProviderInterface` (or a small `mcp/resources` types holder):
`@phpstan-type ResourceDescriptor array{uri:string, name:string, mimeType:string, title?:string, description?:string}`,
`@phpstan-type ResourceContents array{uri:string, mimeType:string, text:string}`, and
`@phpstan-type ResourceReadResult array{contents:list<ResourceContents>}|array{isError:true, error:string}`.
Use them in the interface signatures and `@phpstan-import-type` into `McpServer` and the two (or,
post-N2, the abstract base) providers. **Zero runtime change**; turns the informal contract into
a static-analysis-checked one.

**Confidence: MEDIUM** — net-positive on an output contract that will grow more providers;
no runtime risk. Best landed together with N2.

---

### N4 — `resolveForm()` is byte-identical across all three insight tools  **[LOW — dedup, not a type]**

`DetectSpamPatternsTool::resolveForm()` (`:186-198`), `CategorizeSubmissionsTool::resolveForm()`
(`:173-185`), and `SummarizeSubmissionsTool::resolveForm()` (`:133-146`) are the same method:
`formId` → `Form::find()->siteId('*')->status(null)->id(...)`, else `form` handle → same with
`->handle(...)`, else fall back to `$submissions[0]?->getForm()`. The `siteId('*')->status(null)`
form lookup by id/handle is also the same shape used in `UpdateFormTool` (`:71-83`) and both
resource providers (`:71` / `:77`).

This is method duplication, not an array-shape/type issue, so it is mostly out of concern-#2
scope — but it belongs to the same "insight slice" and a reviewer will see it. The natural home
is `InsightCorpus` (it already owns the insight-tool support surface): a static
`InsightCorpus::resolveForm(array $arguments, array $submissions): ?Form`.

**Proposal:** OPTIONAL — note for the dedup/structure concern, not type consolidation. If touched
here, move the one method onto `InsightCorpus` and have the 3 tools call it.

**Confidence: LOW** — real duplication, but it's behavior code; defer to the dedup pass unless
trivially co-changed with N1.

---

### N5 — `InsightCorpus::fieldTypes()` is a new consumer of the resolved-field-row shape  **[MEDIUM — reinforces F3]**

`InsightCorpus::fieldTypes()` (`:31-42`) reads `$row['name']` (as the field *handle*) and
`$row['type']` straight off the `Form::getFields()` rows — i.e. the **resolved-field-row** shape
that round-1 F3 identified. It keys the handle as `name` (matching `FormGqlResolver`, unlike
`FormPresenter` which re-keys it `handle`). So the round-1 count of "3 consumers re-shaping the
row" is now **4** (GQL resolver, MCP `FormPresenter`, `FieldModel`, and now `InsightCorpus`).

This does not change F3's recommendation or risk — it strengthens the case for the cheap step:
a shared `@phpstan-type ResolvedFieldRow` on `FieldQueryHelper`, imported by consumers. See F3.

**Confidence: MEDIUM** (as part of F3).

---

### F3 (re-verified) — The "resolved field row" shape is re-keyed by (now) 4 consumers  **[MEDIUM]**

Unchanged from round 1 in substance; lines drifted and a consumer was added (N5).

`FieldQueryHelper::fieldsForForms()` (`helpers/FieldQueryHelper.php:36-87`, documented `:16-19`)
is the single source producing rows of
`{ id, formId, type, name, required(bool), config(array, 'required' merged), sortOrder, label(falls back to name), helpText }`.
It flows out via `Form::getFields()`. Consumers re-shape it and disagree on the handle key and
on config flattening:

- `gql/resolvers/FormGqlResolver.php` — keys handle as **`name`**, flattens `config` into
  top-level `placeholder` + nested `options` + a `validation` object.
- `mcp/tools/support/FormPresenter.php:39-57` — keys handle as **`handle`**, passes `config`
  through opaque.
- `models/FieldModel.php` — yet another projection.
- `mcp/tools/support/InsightCorpus.php:31-42` — reads `name`/`type` only (new; see N5).

**Proposal (low-risk first step only):** formalize the row as
`@phpstan-type ResolvedFieldRow array{id:int, formId:int, type:string, name:string, required:bool, config:array<string,mixed>, sortOrder:int, label:string, helpText:?string}`
on `FieldQueryHelper`, `@phpstan-import-type`'d into `FormGqlResolver`, `FormPresenter`,
`FormStructureService`, `Form::getFields`, `FieldModel`, and `InsightCorpus`. **Zero runtime
change.** Defer the full `ResolvedField` value object (touches public GQL + MCP output) and the
`{label,value}` option-decode dedup (still real, still MEDIUM, see round-1 F3).

**Confidence: MEDIUM** for the type alias; **LOW** for the value object (unchanged).

---

### F4 (re-verified) — `PropagationMethod` enum values re-listed inline in 2 MCP tool schemas  **[MEDIUM]**

Unchanged. `traits/HasPropagation.php` already uses `craft\enums\PropagationMethod`, and the tool
`call()` bodies already round-trip through `PropagationMethod::tryFrom(...)`
(`CreateFormTool.php:83`, `UpdateFormTool.php:118`). Only the *advertised allowed values* in the
input schemas are hard-coded literals:

- `mcp/tools/CreateFormTool.php:53` — `'enum' => ['none', 'siteGroup', 'language', 'all']`.
- `mcp/tools/UpdateFormTool.php:52` — same literal list.

**Drift risk:** low frequency (Craft's enum is stable) but the two schemas advertise a set
independent of the enum the `call()` bodies actually accept.

**Proposal:** derive the schema `enum` once from
`array_map(static fn(PropagationMethod $c) => $c->value, PropagationMethod::cases())` — ideally
behind a tiny shared accessor (e.g. a `propagationMethodSchema()` static next to
`FieldOps::typeSchema()`, which is the existing precedent for a registry/enum-derived schema
fragment) referenced by both tools.

**Migration:** mechanical, behavior-preserving. **Confidence: MEDIUM** — small win; do it
alongside F1 when touching the MCP layer.

---

### N6 — Minor: `freeTextHandles` docblock says "returns null", returns `[]`  **[LOW — doc only]**

`InsightCorpus::freeTextHandles()` (`:44-62`) docblock claims "returns null so callers fall back"
but the signature is `: array` and it returns `[]` (`:61`). The callers correctly treat empty as
"no schema" (`SummarizeSubmissionsTool.php:125-126`, and `textValues()` `:76` honors empty), so
behavior is fine — only the comment is stale. Trivial doc fix; no type change.

**Confidence: LOW** (cosmetic).

---

## Non-findings (insight/resources slice — already good)

- **`InsightCorpus`** is the correct shared-support class for the 3 insight tools (text shaping,
  free-text/option classification). Its output corpus shapes (`{id,dateCreated,fields,text}` etc.)
  are tool-local and don't recur across tools in a way that warrants a DTO — leave inline.
- **Both resource providers reuse `FormPresenter` / `SubmissionQueryBuilder`** for contents
  (`FormSchemaResource.php:77`, `SubmissionsDatasetResource.php:84-95`) — exactly the round-1
  "resource and tool never disagree" goal. Do not duplicate presentation into the providers.
- **`QuerySubmissionsTool::filterProperties()`** is correctly reused as the shared input-filter
  fragment by all 3 insight tools (`DetectSpamPatternsTool.php:64`, `CategorizeSubmissionsTool.php:44`,
  `SummarizeSubmissionsTool.php:44`) — the input-schema-fragment sharing round-1 F5 wanted is
  already happening for the part that matters. No action.
- **`McpServer` tool/resource dispatch** is clean; tool list and provider list are simple typed
  arrays. No new types needed there.

---

## High-confidence implementation checklist

Ordered by value/safety. Only F1 (incl. N1) is unambiguously HIGH.

1. **[HIGH] Collapse the field-type lists onto the registry (F1 + N1).**
   - Replace the 8-type literal at `FieldSyncService.php:20` and `FieldsController.php:256`
     with the registry-derived list (mirror `FieldOps::validTypes()`).
   - Add ONE "option types" declaration to `FieldTypeRegistry` (`const OPTION_TYPES` or
     `isOptionType()`); point `FieldSyncService.php:21`, `FieldsController.php:261`,
     `FieldOps.php:31`, and `InsightCorpus.php:23` at it (resolves the divergent ordering too).
   - Verify: add each field type via CP batch-save, CP single-add, MCP `add_field`; confirm
     select/checkbox/radio still require options and `categorize_submissions` auto-grouping still
     picks the first option field.

2. **[MEDIUM] Type the resource contracts as `@phpstan-type` aliases (N3).**
   - `ResourceDescriptor`, `ResourceContents`, `ResourceReadResult` on `ResourceProviderInterface`;
     import into `McpServer` and the providers. No runtime change; run PHPStan.

3. **[MEDIUM] Extract `AbstractFormResourceProvider` base (N2).**
   - Lift scheme/MIME/`handles()`/URI-parse/error-returns/`list()`-loop into a base; subclasses
     supply `describe()` + `readForm()`. Pairs with item 2. Smoke the resources endpoints after.

4. **[MEDIUM] Formalize `ResolvedFieldRow` as a `@phpstan-type` (F3 + N5).**
   - Add the alias to `FieldQueryHelper`; import into `FormGqlResolver`, `FormPresenter`,
     `FormStructureService`, `Form::getFields`, `FieldModel`, `InsightCorpus`. No runtime change.

5. **[MEDIUM] Derive the propagation-method `enum` schema from `PropagationMethod` (F4).**
   - Replace the literals at `CreateFormTool.php:53` / `UpdateFormTool.php:52` with a list from
     `PropagationMethod::cases()`, ideally behind one shared accessor.

Deferred / out of this concern: N4 (`resolveForm()` dedup — belongs to the structure/dedup
concern); N6 (stale docblock — trivial); F3 full `ResolvedField` value object; the
`{label,value}` option-decode dedup; F2 submission-status enum upgrade (round-1 item, not in
this round's scope).

---

## HIGH-confidence item count: **1** (F1, re-confirmed + widened by N1)
