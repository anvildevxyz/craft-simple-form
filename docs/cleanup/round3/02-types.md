# Concern #2 (ROUND 3) — Type Definitions & Shared Types

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2, `fabianhaef\simpleform`)
**Scope:** All `src/` PHP — repeated PHPDoc array shapes, status/identifier string sets, default-operator/enum literals, inconsistent typing of the same conceptual value.
**Phase:** ASSESSMENT ONLY — no source files edited. This document + the filed GitHub issues are the output.
**Date:** 2026-06-20
**Branch:** `feature/cleanup-round3`
**Prior passes:** `docs/cleanup/02-types.md` (round 1, 2026-06-20) and `docs/cleanup/round2/02-type-consolidation.md` (round 2, 2026-06-15). Round-1 F1/F2/F3 were **implemented in PR #146** (the merge `7e31e17` on this branch's parent). This pass independently re-verifies the tree as it stands *after* #146 and targets what remains.

---

## Critical assessment

The codebase is in **very good shape** on types and has only gotten better. Two patterns are applied consistently and correctly:

- **Constant-holder classes** for closed string domains — `SubmissionStatus`, `DispatchStatus`, `mcp\Scopes`, `ConditionalEvaluator` (MATCH_*, OPERATORS), `FieldTypeRegistry::OPTION_TYPES`, `Submission::PAYMENT_*`, `Form::SUPPORTED_PROPAGATION_METHODS`. These are single sources of truth and are *mostly* consumed via the constant.
- **`@phpstan-type` / `@phpstan-import-type` aliases** — `GqlFieldDefinition`/`GqlFieldDefinitionMap` (defined once, imported into 9 GQL types), plus `TokenArray`, `McpError`, `McpResourceContents` (all added/wired in #146). The alias-import idiom is established and idiomatic here, so further use of it is consolidation, **not** new ceremony.

What #146 already fixed (independently re-verified — do NOT re-flag):

- **`TokenArray`** is now imported (not re-typed) into `TokenManager` and `Settings`. ✅ (round-1 F1)
- **`McpError`** (`mcp/tools/ToolInterface.php`) + **`McpResourceContents`** (`ResourceProviderInterface`) aliases exist and are imported across the MCP tool/resource layer. ✅ (round-1 F2)
- **`ConditionalEvaluator`** — the GQL resolver and notifications controller now reuse `normalizeMatch` + the match/action constants instead of bare `'all'/'any'/'eq'` literals. ✅ (round-1 F3)

What **prior passes flagged as "still HIGH/open" but is in fact now RESOLVED** (independently re-verified this pass — do NOT re-flag):

- **Field-type identifier list + option-type subset** (round-2 F1/N1, "still HIGH"). Now clean. `FieldTypeRegistry::typeHandles()` is the registry-derived valid list, consumed for validation in `FieldSyncService.php:56` and `FieldsController.php:282`. `FieldTypeRegistry::OPTION_TYPES` (`services/FieldTypeRegistry.php:27`) is the single literal, consumed via the constant in all four sites: `FieldSyncService.php:60`, `FieldsController.php:286`, `FieldOps.php:95`, `CategorizeSubmissionsTool.php:164`. No 8-type literal copies and no divergent-order `OPTION_TYPES` copy remain. `InsightCorpus::FREE_TEXT_TYPES` is the only remaining field-type literal and is an insight-domain *classification* (the complement set), correctly left as-is. **Resolved.**
- **`PropagationMethod` enum re-listed in MCP tool schemas** (round-2 F4, MEDIUM). Now clean. Both `CreateFormTool.php:54` and `UpdateFormTool.php:53` reference `Form::SUPPORTED_PROPAGATION_METHODS` (`elements/Form.php:24`) — the single source. **Resolved.** (Note: this constant is a hand-maintained `['none','siteGroup','language','all']` mirror of `craft\enums\PropagationMethod`, not derived from `PropagationMethod::cases()`; that's an acceptable trade — it's one literal, in one place, and the `call()` bodies still validate via `PropagationMethod::tryFrom()`. Deriving it would be marginal; not flagged.)

So the four genuine items below are **localized PHPDoc-shape drift**, not architectural gaps. There is **no need for new DTOs / value objects** beyond what already exists (`IntegrationResult` is a proper immutable value object; the element + thin-model + registry split is right for the plugin's size). The prior passes' conclusion holds.

- **HIGH: 1** (T1)
- **MEDIUM: 2** (T2, T3)
- **LOW: 1** (T4)

---

## Findings

### T1 — Submission `data` shape `array{label, type, value}` is built once, then typed as bare `array<string,mixed>` and re-described in prose by ~8 consumers  **[HIGH]**

The canonical submission-data structure is `field_<id> => ['label' => string, 'type' => string, 'value' => mixed]`. It is **built in exactly one place**:

- `services/SubmissionService.php:204-208`:
  ```php
  $data['field_' . $fieldId] = [
      'label' => $field->getLabel() ?? $field->getName(),
      'type'  => $field->getType(),
      'value' => $value,
  ];
  ```

…and **stored** on the element with no shape information:

- `elements/Submission.php:23-24` — `/** @var array<string, mixed>|null */ public ?array $data = null;`

Consumers read `$entry['label']` / `$entry['type']` / `$entry['value']` off this shape, but the shape is documented **inconsistently and only in prose**, never as a checked type:

| File:line | How `$data` is typed / described |
| --- | --- |
| `services/AkismetService.php:26` | `@param array<string, mixed> $data … (field_<id> => {label,type,value})` (full shape, prose) — reads `$entry['type']` for `email`/`text` at `:115,:117` |
| `integrations/support/SubmissionValues.php:9-11` | prose `field_<id> => {label, type, value}` — reads `$entry['value']` (`:33,:52`), `$entry['label']` (`:51`) |
| `helpers/SubmissionCsv.php:26-29,51-52,79-81,99` | no `@param`; reads `$entry['label']` and `$entry['value']` (with `is_array($entry)` guards) |
| `services/EmailService.php:24,52,127,229` | `@param array<string, mixed> $data` (no shape); reads `$fieldData['type'] === 'file'` and `$fieldData['value']` |
| `services/PaymentsService.php:62,206,226` | `@param array<string, mixed> $data … keyed by field_<id>` (no inner shape); reads `$data['field_'.$id]` entries |
| `services/NotificationsService.php:117` | `@param array<string, mixed> $data … keyed by field_<id>` (no inner shape) |
| `services/DraftService.php:31` | `@param array<string, mixed> $data field_<id> => value` — **disagrees**: draft data is the *value-only* map, not `{label,type,value}` |

**Why it's genuine:** the inner `{label,type,value}` shape is real, closed, and read by `$entry['type']` membership tests that drive behavior (Akismet author extraction, email file-vs-scalar formatting, payment email lookup). Adding/renaming an inner key (e.g. `value` → `raw`) means editing the build site and silently hoping every prose-documented consumer is updated — PHPStan checks none of it today. The `DraftService` mismatch is exactly the kind of confusion a named type prevents: the resume/draft map and the final stored map are *different shapes* both currently labelled `array<string, mixed>`.

**Proposed consolidation:** add one alias next to the element it describes —
`@phpstan-type SubmissionData array<string, array{label: string, type: string, value: mixed}>`
on `elements\Submission` (natural home; it owns `$data`). Type `Submission::$data` as `?SubmissionData`, and `@phpstan-import-type SubmissionData` into the consumers that read the inner shape (`AkismetService`, `SubmissionValues`, `SubmissionCsv`, `EmailService`, `PaymentsService`, `NotificationsService`). Leave `DraftService`'s param as the genuinely-different value-only map (or give it its own clearer doc) — that distinction becomes *visible*, which is the win. Pure PHPDoc; zero runtime change.

**Confidence:** HIGH. **Risk:** LOW (PHPDoc only; PHPStan L7 may surface a few `mixed`-access spots that already have runtime `is_array` guards — those are the value of the change, fixable with casts/`@var`). **Safe to auto-implement now:** YES, but verify `composer check` stays green (PHPStan may need a couple of narrow casts at read sites).

---

### T2 — "Resolved field row" shape (`FieldQueryHelper`) is prose-documented once and re-keyed by 6 consumers  **[MEDIUM]**

> This is round-2 **F3** (and round-1 F3). It was **not** touched by PR #146, so it is still open and re-verified here.

`helpers/FieldQueryHelper::fieldsForForm()/fieldsForForms()` is the single producer of the resolved-field row:
`{ id:int, formId:int, type:string, name:string (the handle), required:bool, config:array<string,mixed>, sortOrder:int, label:string, helpText:?string }` — documented only as prose at `FieldQueryHelper.php:16-18` and returned as `array<int, array<string, mixed>>`. It flows out through `Form::getFields()` (`elements/Form.php:84-101`) and `FormStructureService::getFieldSet()` (`services/FormStructureService.php:37-62`), both also typed `array<int, array<string,mixed>>`.

Consumers re-project it, and they **disagree on the handle key**:

- `gql/resolvers/FormGqlResolver.php:59-91` — reads `$row['name']` (handle), `$row['type']`, `$row['label']`; emits GQL keyed `name`.
- `mcp/tools/support/FormPresenter.php:40-58` — reads `$row['name']`, emits it as **`handle`** (re-keyed).
- `mcp/tools/support/InsightCorpus.php:28-39` — reads `$row['name']` as the handle.
- `models/FieldModel::loadFields()` — constructs `FieldModel` from `$rawField['type'/'name'/'label'/'config']`.
- `TwigExtension::renderFieldGroup()` (`:157-180`) — `@param array<string, mixed> $field a resolved field row`; reads `$field['label']`/`$field['name']`/`$field['id']`/`$field['type']`.
- `helpers/FormSteps.php:13-14` — `@param array<int, array<string, mixed>> $fields resolved field rows`.

**Why it's genuine but only MEDIUM:** the row is a real internal contract crossing GQL, MCP, Twig and the field model, and the `name`-vs-`handle` divergence is exactly the latent confusion a named type documents. But every consumer accesses it correctly today, and the producer is a helper (not a public API), so the blast radius is low. The fix is the cheap step only — **not** a `ResolvedField` value object (that would touch public GQL + MCP output shapes; explicitly deferred, as in round 2).

**Proposed consolidation:**
`@phpstan-type ResolvedFieldRow array{id:int, formId:int, type:string, name:string, required:bool, config:array<string,mixed>, sortOrder:int, label:string, helpText:?string}`
on `FieldQueryHelper`; `@phpstan-import-type` it into `Form::getFields`, `FormStructureService`, `FormGqlResolver`, `FormPresenter`, `FieldModel`, `InsightCorpus`, `TwigExtension`, `FormSteps`. Pure PHPDoc; zero runtime change.

**Confidence:** MEDIUM. **Risk:** LOW (PHPDoc only; PHPStan may want narrow casts where `mixed` row values are used unguarded). **Safe to auto-implement now:** YES, with a `composer check` re-run. (Defer the `ResolvedField` value object and the `{label,value}` option-decode dedup — out of scope.)

---

### T3 — MCP resource descriptor + contents-entry shapes are not aliased; the two providers are structurally near-identical  **[MEDIUM]**

> This is round-2 **N2/N3**. PR #146 added `McpResourceContents` (the `read()` success half) and `McpError`, but the **`resources/list` descriptor shape** and the **`{uri, mimeType, text}` contents-entry block** are still un-aliased, and the abstract-base extraction was not done.

`mcp/resources/ResourceProviderInterface::list()` returns `list<array<string, mixed>>` (`:36-38`) where each entry is in fact a fixed descriptor: `{uri:string, name:string, mimeType:string, title?:string, description?:string}`. Both providers hand-build it identically:

- `FormSchemaResource.php:45-52` and `SubmissionsDatasetResource.php:50-57` — same five keys, same `self::SCHEME . '://' . $form->handle` URI, same `name ?? handle` fallbacks.

The MCP "contents entry" block `{uri, mimeType, text}` is likewise hand-built identically in both `read()` bodies (`FormSchemaResource.php:82-86`, `SubmissionsDatasetResource.php:107-111`) but is hidden inside `McpResourceContents`'s `list<array<string, mixed>>` rather than being its own named shape.

Beyond the shapes, the two providers are near-duplicate structurally (the concern-#1 dedup pass owns the *code* dedup, but it's relevant context for "is a shared type worth it"): identical `SCHEME . '://'` concatenation in three spots each (`handles`, `list` URI, `read` substr), identical `handles()`, identical `list()` loop over `Form::find()->siteId('*')->status(null)` with the same `!$form instanceof Form || handle === null` dedupe guard, and identical `read()` preamble (`Missing form handle` / `Form not found` error returns).

**Why it's genuine but MEDIUM:** it's a real MCP *output contract* (the kind the prior passes correctly chose to type), and the slice is named to grow more providers — a third copy would re-spell all of the above. But it's a 2-implementation surface today, so it sits just under HIGH.

**Proposed consolidation (types only — pairs with the concern-#1 base-class dedup if that lands):**
- `@phpstan-type ResourceDescriptor array{uri:string, name:string, mimeType:string, title?:string, description?:string}` on `ResourceProviderInterface`; use it for `list()`'s return and `@phpstan-import-type` into the two providers and `McpServer` (which aggregates them at `McpServer.php:300-303`).
- `@phpstan-type ResourceContentsEntry array{uri:string, mimeType:string, text:string}` and redefine `McpResourceContents` as `array{contents:list<ResourceContentsEntry>}`.
- (Out of concern-#2 scope but note for #1: the providers can collapse onto an `AbstractFormResourceProvider` that owns SCHEME/MIME/`handles()`/the URI parse/the error returns/the `list()` loop, with subclasses supplying `describe()` + `readForm()`.)

**Confidence:** MEDIUM. **Risk:** LOW (PHPDoc only). **Safe to auto-implement now:** YES for the two aliases; the abstract-base extraction belongs to concern #1's dedup phase and should be coordinated, not done here.

---

### T4 — `ReportsService::dispatchHealth()` keys the dispatch-count map with raw literals instead of `DispatchStatus::*`  **[LOW]**

> Round-2 F5 watch-item; re-verified — a *second* consumer of the same vocabulary now exists, but as raw literals.

Two services return dispatch-outcome counts keyed on the `DispatchStatus` vocabulary. One uses the constants for the map keys, the other uses bare literals:

- `services/IntegrationsService.php:528,540-542` — builds `[DispatchStatus::SUCCESS => 0, DispatchStatus::FAILED => 0, DispatchStatus::PENDING => 0]` and spreads them into the return. ✅ constant-driven.
- `services/ReportsService.php:127` — builds `$health = ['success' => 0, 'failed' => 0, 'pending' => 0];` with **raw literal keys** (return shape `@return array{success: int, failed: int, pending: int}` at `:117`). The keys must match `simpleform_integration_logs.status` values, which are exactly `DispatchStatus::*` — so this is the same closed vocabulary re-spelled as array literals.

**Why it's genuine but LOW:** purely a consistency nit — the two methods are distinct (one global, one per-integration) so a *shared return-shape alias* (`DispatchCounts`) is borderline and probably not worth it. But `ReportsService` reaching for the same `DispatchStatus` constants `IntegrationsService` already uses is a strict, free consistency win and removes the only place where these status strings are still bare literals in runtime code.

**Proposed consolidation:** in `ReportsService::dispatchHealth()`, key the accumulator with `DispatchStatus::SUCCESS/FAILED/PENDING` (matching `IntegrationsService`). The return *array-shape* keys (`@return array{success:int,...}`) can stay literal — they're the documented public shape, and constants can't be used in `array{}` PHPDoc keys anyway. Do **not** add a `DispatchCounts` shared alias unless a third consumer appears.

**Confidence:** LOW (consistency, not safety). **Risk:** LOW (the constant values equal the literals — behavior-identical). **Safe to auto-implement now:** YES.

---

## Considered & rejected (leave as-is)

- **Converting constant holders to native enums** (`SubmissionStatus`, `DispatchStatus`, `mcp\Scopes`, `Submission::PAYMENT_*`). Re-verified: these back public element properties, the `simpleform_integration_logs.status` / `readStatus` columns, and token/scope storage. The migrations deliberately keep their own literals (documented on `DispatchStatus`'s docblock). Enum conversion is MEDIUM-risk, LOW-reward — **skip**, as both prior passes concluded.
- **New DTOs / value objects** for submission data, resolved fields, or integration configs. The element + thin-model + registry split fits the plugin's size; `IntegrationResult` already is a proper immutable value object where one's warranted. A `ResolvedField` VO would touch public GQL + MCP output — out of scope. **Skip** (concurs with rounds 1 & 2).
- **`InsightCorpus::FREE_TEXT_TYPES`** (`['text','textarea','email']`). An insight-domain *classification* (the complement of the option set), not a structural field-type constraint, and the only consumer. Deriving it from the registry would be less explicit. **Leave as-is** (concurs with round 2's note).
- **Deriving `Form::SUPPORTED_PROPAGATION_METHODS` from `PropagationMethod::cases()`.** It's now one literal in one place, with `tryFrom()` validation in the `call()` bodies. Deriving it is marginal and couples a CP/MCP schema constant to enum iteration. **Leave as-is.**
- **Single-type `=== 'file' / 'payment' / 'email' / 'text'` checks** (`TwigExtension`, `EmailService`, `PaymentsService`, `AkismetService`). These are individual behavioral branches, not a *list* of field types, and pointing each at a one-element constant adds ceremony without removing drift. **Leave as-is.**
- **Captcha provider identifiers in `Settings`** (round-1 F6) and **payment-status PHPDoc on `Submission`** (round-1 F7). Re-verified unchanged; both are LOW cosmetic. **Leave as-is.**
- **Integration settings shapes.** `integrations/IntegrationTypeInterface` types `$settings` as `array<string, mixed>` *deliberately* — each connector owns its own settings keys, so a shared shape would be wrong. **Leave as-is.**

---

## Prioritized list

| # | Finding | Confidence | Risk | Safe to auto-implement now |
| --- | --- | --- | --- | --- |
| T1 | `SubmissionData` `@phpstan-type` for the `{label,type,value}` map | HIGH | LOW | YES (re-run `composer check`) |
| T2 | `ResolvedFieldRow` `@phpstan-type` on `FieldQueryHelper`, imported by 6 consumers | MEDIUM | LOW | YES (re-run `composer check`) |
| T3 | `ResourceDescriptor` + `ResourceContentsEntry` aliases on the MCP resource interface | MEDIUM | LOW | YES for aliases; coordinate the abstract-base with concern #1 |
| T4 | `ReportsService::dispatchHealth()` key the count map with `DispatchStatus::*` | LOW | LOW | YES |

All four are pure-PHPDoc / behavior-identical changes consistent with the alias-import idiom already in the tree. The gate (`composer check` — ECS + PHPStan L7 + PHPUnit) must stay green; T1/T2 may surface a handful of `mixed`-access spots at read sites that already have runtime `is_array` guards — fixing those with narrow casts is the intended benefit, not a regression.
