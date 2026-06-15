# Concern #2 — Type Definitions & Consolidation

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Scope:** All `src/` PHP classes/interfaces/enums/traits/DTOs and the repeated array shapes passed between them.
**Phase:** ASSESSMENT ONLY — no source files were edited. This document is the sole output.
**Date:** 2026-06-14

> "Types" here means PHP classes/interfaces/enums/value objects, model/data shapes, and the untyped associative-array shapes the codebase passes around in lieu of types.

---

## Summary

Simple Form is small (72 PHP files) and its *named* types are already reasonable: there is one `FieldType` hierarchy (8 concrete field types behind an abstract base + a `FieldTypeRegistry`), one `Form` and one `Submission` element, two thin domain models (`FormModel`, `FieldModel`), a clean GraphQL object-type hierarchy under a shared `SimpleFormObjectType` base, and a deliberate `Scopes` class for MCP authorization. The architecture is not over-typed and does **not** need a wave of new DTOs.

The real drift risk is **two well-known string domains that are duplicated as ad-hoc literal arrays**, and **one canonical "resolved field row" array shape** that is produced once but re-keyed differently by three independent consumers (Twig/CP, GraphQL, MCP). These are where a field rename or a new field type can silently desync one surface from another.

Findings cluster into three buckets:

1. **Stringly-typed field-type identifiers** — the 8 type strings (`text`, `email`, …) and the "option types" subset (`select`, `checkbox`, `radio`) are hard-coded as literal arrays in **three** places (`FieldSyncService`, `FieldsController`, `FieldOps`), even though the `FieldTypeRegistry` is already the authoritative source and `FieldOps::validTypes()` already derives from it. **HIGH** — collapse the two hard-coded copies onto the registry.
2. **Stringly-typed submission statuses** — `new`/`read`/`archived` appear as literal arrays/strings in the migration plus 6+ runtime sites. A `SubmissionStatus` enum (or one constant set) removes the drift. **HIGH** for a constant set; **MEDIUM** if pushed all the way to a backed enum (touches a public element property type).
3. **The "resolved field row" shape** (`id, formId, type, name, required, config, sortOrder, label, helpText`) defined informally by `FieldQueryHelper` and re-shaped by `FormGqlResolver::mapField()`, `FormPresenter::fields()`, and `FieldModel`. The drift here is real (GQL/MCP key it `name`-vs-`handle`, flatten config differently), but a full DTO is a larger refactor touching public GraphQL/MCP output. **MEDIUM** for a shared PHPStan `@phpstan-type` alias (zero runtime risk); **LOW** for a full value object.

The single highest-value, lowest-risk action is **(1)** — there's no reason for three copies of the field-type list when a registry-backed accessor already exists and is used by the MCP path.

---

## Inventory: named types under `src/`

| Type | File | Represents |
|---|---|---|
| `FieldType` (abstract) + 8 concrete | `fields/FieldType.php`, `fields/{Text,Email,Textarea,Select,Checkbox,Radio,Date,Number}FieldType.php` | A field *variant* (render + validate + `getType()`/`getLabel()`) |
| `FieldTypeRegistry` | `services/FieldTypeRegistry.php` | Authoritative type→class map; `getAllFieldTypes()` is the canonical type list |
| `Form` (element) | `elements/Form.php` | The form domain entity (persisted, multi-site) |
| `Submission` (element) | `elements/Submission.php` | A stored submission (`readStatus`, `data` blob) |
| `FormModel`, `FieldModel` | `models/FormModel.php`, `models/FieldModel.php` | Thin read-model wrappers over a form / a resolved field row |
| `Settings` | `models/Settings.php` | Plugin settings |
| GraphQL: `SimpleFormObjectType` (base), `FormType`, `FormFieldType`, `FieldOptionType`, `FieldValidationType`, `FieldValueInputType`, `SubmissionErrorType`, `SubmitFormPayloadType` | `gql/types/*` | The headless schema describing form/field/option/validation/submit |
| `FormGqlResolver` | `gql/resolvers/FormGqlResolver.php` | Maps a `Form` + resolved field rows into the GraphQL shape |
| `Scopes` | `mcp/Scopes.php` | MCP authorization scope constants (`forms:manage`, `submissions:read`, `submissions:export`) |
| `ToolInterface` + 13 tools + `support/{FormPresenter,FieldOps,SubmissionQueryBuilder}` | `mcp/tools/*` | MCP tool schemas + serialization of form/field/submission to JSON |
| `FieldQueryHelper` | `helpers/FieldQueryHelper.php` | **Single source of truth for the resolved field-row shape** |
| `HasPropagation` (trait) | `traits/HasPropagation.php` | Multi-site propagation; holds the Craft `PropagationMethod` enum |
| `SubmissionEvent` | `events/SubmissionEvent.php` | Event payload |

Domain concepts represented multiple ways:
- **A "field"** → `FieldModel`; `FormFieldType` (GraphQL); `FormPresenter::fields()` (MCP); `FieldQueryHelper` row (Twig/CP); `FieldSyncService` input item. Five representations of one entity.
- **A "field type"** → 8 `getType()` strings; registry map; 3 hard-coded literal arrays; GraphQL/MCP description strings.
- **A "submission status"** → element property + migration enum + 2 hard-coded literal arrays + scattered string literals.

---

## Findings

### F1 — Field-type identifier list duplicated 3× despite an authoritative registry  **[HIGH]**

The set of 8 valid field types and the "option types" subset (`select`, `checkbox`, `radio`) are hard-coded as literal arrays in three places, while a fourth path already derives them correctly from the registry:

- Canonical source: `services/FieldTypeRegistry.php:64` (`getAllFieldTypes()`), keys = the type strings.
- Already correct (derives from registry): `mcp/tools/support/FieldOps.php:40-42` (`validTypes()`), used at `FieldOps.php:96`.
- Hard-coded duplicate #1: `services/FieldSyncService.php:20` `const VALID_TYPES = ['text','email','textarea','select','checkbox','radio','date','number']` (used `:58`).
- Hard-coded duplicate #2: `controllers/FieldsController.php:256` `$validTypes = [...same 8...]` (used `:257`).
- "Option types" subset hard-coded 3×: `services/FieldSyncService.php:21`, `controllers/FieldsController.php:261`, `mcp/tools/support/FieldOps.php:31`.

**Drift risk:** Adding a 9th field type means registering it in `FieldTypeRegistry::init()` *and* remembering to edit two unrelated literal arrays, or the CP batch-save / single-field-add silently rejects it as "invalid type" while MCP accepts it.

**Proposal (canonical = the registry):**
- Replace `FieldSyncService::VALID_TYPES` usage and `FieldsController`'s `$validTypes` with the registry-backed list (mirror `FieldOps::validTypes()` → `array_keys($registry->getAllFieldTypes())`).
- For the "option types" subset, introduce **one** declaration. Lowest-risk: a `public const OPTION_TYPES` on `FieldTypeRegistry` (or a tiny `isOptionType(string $type): bool` accessor) and have all three call sites use it. (A per-field-type `requiresOptions(): bool` on the `FieldType` base is the more OO option but is a larger change — leave for a follow-up.)

**Migration:** mechanical; behavior-preserving (same strings, same validation outcomes). No DB, no schema, no public-API change. Worth a quick test of "add field" via both CP and MCP after the swap.

**Confidence: HIGH** — strictly removes duplication onto an existing single source of truth; one path already does it this way.

---

### F2 — Submission status strings (`new`/`read`/`archived`) duplicated across migration + 6 runtime sites  **[HIGH for constants / MEDIUM for enum]**

No type backs the three statuses; they appear as raw literals everywhere:

- Schema definition: `migrations/m240614_000001_init.php:86` `enum('readStatus', ['new','read','archived'])->defaultValue('new')`.
- Hard-coded valid-list #1: `services/SubmissionService.php:165` `$validStatuses = ['new','read','archived']` (used `:166`).
- Hard-coded valid-list / cycle order #2: `controllers/SubmissionsController.php:167` `$statuses = ['new','read','archived']` (drives the status-cycle at `:168-170`).
- Default-value literals: `elements/Submission.php:18` `= 'new'`; `services/SubmissionService.php:136` `= 'new'`.
- Per-status SQL counters: `controllers/SubmissionsController.php:118-120` (`'new'`, `'read'`, `'archived'`).
- Default query param: `controllers/SubmissionsController.php:37` (`'new'`).
- Query-param description: `mcp/tools/QuerySubmissionsTool.php:29`.

**Drift risk:** renaming a status, adding a 4th, or reordering the cycle requires touching the migration plus 3+ unrelated files; the "valid statuses" list and the "cycle order" list are independently maintained and can disagree.

**Proposal:**
- **HIGH path (constant set):** introduce a `SubmissionStatus` holder (mirroring the existing `Scopes` class shape) with `const NEW/READ/ARCHIVED`, an `all(): list<string>` (display/cycle order), and `isValid()`. Point all 6+ sites at it; keep `Submission::$readStatus` typed `string`. Zero runtime/behavior change, mirrors a pattern already in the codebase.
- **MEDIUM path (PHP 8.1 backed enum):** `enum SubmissionStatus: string`. Cleaner, but `Submission::$readStatus` is a public `string` property populated by Craft's Typecast from a DB column and read as a raw string across queries (`SubmissionQuery`, raw SQL, MCP presenters) — converting the property type to the enum touches the element's public shape and several `->readStatus` string comparisons. Defer unless a broader element refactor is in flight.

**Migration:** constant-set version is mechanical and safe. Recommend HIGH for the constant set, treat the backed-enum upgrade as a separate MEDIUM item.

**Confidence: HIGH (constant set) / MEDIUM (enum).**

---

### F3 — The "resolved field row" shape is re-keyed differently by 3 consumers  **[MEDIUM]**

`FieldQueryHelper::fieldsForForms()` (`helpers/FieldQueryHelper.php:48-86`, documented `:17-19`) is the documented single source of truth producing rows of:
`{ id, formId, type, name, required(bool), config(array, 'required' merged in), sortOrder, label(falls back to name), helpText }`.

It flows out as `array<int,array<string,mixed>>` through `FormStructureService::getFieldSet()` (`services/FormStructureService.php:39`) and `Form::getFields()` (`elements/Form.php:78`). Three consumers then re-shape it, and they disagree on keys and on how `config` is flattened:

- `FormGqlResolver::mapField()` — `gql/resolvers/FormGqlResolver.php:43-60`: keys the handle as **`name`**, and **flattens** config into top-level `placeholder` + a nested `options` (mapped to `{label,value}`) + a nested `validation` object (`minLength/maxLength/min/max/pattern`).
- `FormPresenter::fields()` (MCP) — `mcp/tools/support/FormPresenter.php:39-57`: keys the handle as **`handle`**, and **passes `config` through as an opaque blob** (no flattening, no `validation` object).
- `FieldModel` — `models/FieldModel.php:9-64`: yet another projection (`id/type/name/label/helpText/config`), constructed from the same rows in `FormModel::loadFields()` (`models/FormModel.php:27-36`).

So one stored field is exposed to GraphQL clients under `name` with a structured `validation`, to MCP clients under `handle` with a raw `config`, and to Twig/PHP via `FieldModel`. The `{label,value}` option-decode logic is itself duplicated: `gql/resolvers/FormGqlResolver.php:68-87` vs `fields/SelectFieldType.php:44-54` (and the same loop again in `CheckboxFieldType`/`RadioFieldType`).

**Drift risk:** medium and real. A new structural column or a `config` key (e.g. a new validation rule) must be threaded into each projection by hand; the GraphQL `validation`/`options` mapping and the field types' own config reads can fall out of sync (the validation rule the schema advertises vs. the rule the field type actually enforces).

**Proposal (incremental, low-risk first):**
- **MEDIUM (recommended first step):** formalize the row shape as a PHPStan type alias — e.g. `@phpstan-type ResolvedFieldRow array{id:int, formId:int, type:string, name:string, required:bool, config:array<string,mixed>, sortOrder:int, label:string, helpText:?string}` on `FieldQueryHelper`, and `@phpstan-import-type` it into `FormGqlResolver`, `FormPresenter`, `FormStructureService`, `Form`, `FieldModel`. **Zero runtime change**; turns the informal docblock contract into a checked one so a missing/renamed key is a static-analysis error. This is the safe consolidation.
- **MEDIUM:** extract the duplicated `{label,value}` option-normalization (`FormGqlResolver::mapOptions` ↔ `SelectFieldType::getOptions` decode loop) into one shared helper (e.g. a static on `FieldQueryHelper` or a small `FieldOptions` helper). Behavior-preserving.
- **LOW (do not do yet):** a full `ResolvedField` value object replacing the array everywhere. It would touch GraphQL output, MCP output, Twig, and the caches in `FormStructureService` — the public GraphQL schema and MCP JSON contract make this a stability-sensitive, larger refactor. Over-engineering relative to the current size.

**Confidence: MEDIUM** for the type-alias + option-helper steps; **LOW** for a value object (correct direction, but risk/effort outweigh benefit now).

---

### F4 — `PropagationMethod` enum values re-listed inline in 2 MCP tool schemas  **[MEDIUM]**

`HasPropagation` already uses Craft's `craft\enums\PropagationMethod` enum (`traits/HasPropagation.php:19,28-36`), and form/presenter output serializes `$form->propagationMethod->value` (`elements/Form.php:209`, `mcp/tools/support/FormPresenter.php:29`). But the *allowed values* are hard-coded as a literal `enum` array in two MCP input schemas:

- `mcp/tools/CreateFormTool.php:51-55` — `'enum' => ['none','siteGroup','language','all']`.
- `mcp/tools/UpdateFormTool.php:50-54` — same literal list.

**Drift risk:** low-frequency (Craft's enum is stable), but if the supported set ever changes, these two JSON schemas advertise a stale option set independently of `PropagationMethod`.

**Proposal:** derive the schema `enum` array from `PropagationMethod` cases once (e.g. `array_map(fn($c) => $c->value, PropagationMethod::cases())`) in a shared helper, or a single `FormPresenter`/`FieldOps`-style accessor, and reference it from both tools.

**Migration:** mechanical, behavior-preserving. **Confidence: MEDIUM** (small win, slightly more verbose than the literal; do it alongside F1/F2 if touching the MCP layer).

---

### F5 — Repeated MCP input/output array shapes (form / field / submission)  **[LOW]**

The MCP tool layer reuses several associative-array shapes inline. Most are *already* centralized well (the support classes are the right pattern): `FormPresenter::form()`/`fields()` is the single form/field output shape (`mcp/tools/support/FormPresenter.php:17,39`) used by 7 tools; `SubmissionQueryBuilder::present()` is the single submission output shape (`support/SubmissionQueryBuilder.php:96-116`) used by 4 tools; `QuerySubmissionsTool::filterProperties()` is reused by stats/export. These are *good* — no action.

The remaining inline-duplicated **input-schema** fragments are minor:
- "id OR handle" form identifier: `GetFormTool` (`:34-37`), `UpdateFormTool` (`:40-41`), `DeleteFormTool` (`:37-39`).
- Field-definition input (`handle/label/required/helpText/config`): `AddFieldTool:40-48` ↔ `UpdateFieldTool:40-48`.
- Form-metadata input (`name/title/description/emailTo/emailSubject/emailReplyTo`): `CreateFormTool:40-59` ↔ `UpdateFormTool:35-57`.

**Proposal:** *optional* — these could become shared `ToolSchemas`/`FieldOps` schema-fragment helpers, but each is a few lines of declarative JSON-schema, low drift risk, and the support-class pattern already covers the output side. Consolidating input fragments is borderline over-engineering for a 13-tool surface.

**Confidence: LOW** — defer; not net-positive enough to prioritize.

---

### Non-findings (already good — do not change)

- `Scopes` (`mcp/Scopes.php`) — exemplary: single source for the 3 MCP scopes with `all()`/`label()`/`isValid()` and every tool declaring `scope()` against the constants. This is the **template** to copy for F2's `SubmissionStatus`.
- `SimpleFormObjectType` base + `@phpstan-type GqlFieldDefinition`/`GqlFieldDefinitionMap` (`gql/types/SimpleFormObjectType.php:16-17`) — the GraphQL types already share a typed base and import the field-definition alias. Good. (It is also the precedent for F3's PHPStan type-alias approach.)
- `FieldTypeRegistry` — correct single source of truth for field types; the fix in F1 is to *use* it more, not change it.
- MCP `FormPresenter` / `SubmissionQueryBuilder` / `FieldOps` support classes — already the right centralization for MCP output/validation.

---

## High-confidence implementation checklist

Ordered by value/safety. Only F1 and F2(constant-set) are unambiguously HIGH.

1. **[HIGH] Collapse the field-type lists onto the registry (F1).**
   - In `services/FieldSyncService.php:20,58` and `controllers/FieldsController.php:256-257`, replace the hard-coded 8-type array with the registry-derived list (`array_keys(...getAllFieldTypes())`), matching `mcp/tools/support/FieldOps.php:40-42,96`.
   - Introduce one "option types" declaration (constant or `isOptionType()`/`OPTION_TYPES` on `FieldTypeRegistry`) and point `FieldSyncService.php:21,62`, `FieldsController.php:261`, `FieldOps.php:31,100` at it.
   - Verify: add a field of each type via CP batch-save, CP single-add, and MCP `add_field`; confirm `select/checkbox/radio` still require options.

2. **[HIGH] Introduce a `SubmissionStatus` constant holder (F2, constant-set path).**
   - New class mirroring `Scopes`: `const NEW='new'/READ='read'/ARCHIVED='archived'`, `all(): list<string>` (cycle/display order), `isValid()`.
   - Replace literals at `SubmissionService.php:136,165`; `SubmissionsController.php:37,118-120,167`; `Submission.php:18`; and reference in `QuerySubmissionsTool.php:29`'s description. Leave the migration enum as the schema definition but it should match `all()`.
   - Keep `$readStatus` typed `string` (no element-shape change). Verify status cycling + per-status counts in the CP submissions index.

3. **[MEDIUM] Formalize the resolved-field-row shape as a PHPStan `@phpstan-type` (F3, step 1).**
   - Add `@phpstan-type ResolvedFieldRow {...}` to `helpers/FieldQueryHelper.php` and `@phpstan-import-type` into `FormGqlResolver`, `FormPresenter`, `FormStructureService`, `Form::getFields`, `FieldModel`. No runtime change; run PHPStan to surface any key mismatch.

4. **[MEDIUM] De-duplicate the `{label,value}` option normalization (F3, step 2).**
   - Extract one shared option-decode helper; call it from `FormGqlResolver::mapOptions` and the `Select/Checkbox/Radio` field types.

5. **[MEDIUM] Derive the propagation-method `enum` schema from `PropagationMethod` (F4).**
   - Replace the literal arrays at `CreateFormTool.php:51-55` and `UpdateFormTool.php:50-54` with a value list generated from `PropagationMethod::cases()`.

Deferred (not in this pass): F2 backed-enum upgrade; F3 full `ResolvedField` value object; F5 MCP input-schema-fragment helpers.

---

## HIGH-confidence item count: **2** (F1; F2 constant-set path)
