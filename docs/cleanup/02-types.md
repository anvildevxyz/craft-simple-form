# Concern #2 (re-run) — Type Definitions & Shared Types

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Scope:** All `src/` PHP — named types, constant/enum-like sets, magic strings, repeated `array{…}` PHPDoc shapes.
**Phase:** ASSESSMENT ONLY — no source files edited. This document is the sole output.
**Date:** 2026-06-20
**Supersedes/extends:** `02-type-consolidation.md` (2026-06-14). That pass's three HIGH findings (field-type list, submission statuses, resolved-field shape) were **acted on** and are now resolved (see "Confirmed-clean" below). This re-run targets drift introduced by the code added since then (notifications, payments, audit log, integrations, captcha, expanded MCP).

---

## Summary

The codebase remains in good shape on types. The team has internalized two good patterns and applies them widely:

- **Constant-holder classes for string domains:** `SubmissionStatus`, `DispatchStatus`, `Scopes`, `ConditionalEvaluator` (MATCH_*, OPERATORS), `FieldTypeRegistry::OPTION_TYPES`, `PaymentsService::STATUS_*`. These are single sources of truth and are *mostly* consumed via the constant.
- **PHPStan type aliases** (`@phpstan-type` / `@phpstan-import-type`) in the GraphQL layer: `GqlFieldDefinition` / `GqlFieldDefinitionMap` are defined once in `SimpleFormObjectType` and imported into 9 GQL type classes. This is exactly the right tool and it's used well.

The remaining issues are **localized drift, not architectural gaps**: a couple of constant sets that exist but aren't referenced by every consumer, one PHPStan alias that's defined but re-written by hand in two other files, and one repeated MCP error-result shape that has no alias yet. There is **no need for new DTOs / value objects** — the prior pass's conclusion still holds.

- **HIGH-confidence recommendations: 3** (F1, F2, F3)
- **MEDIUM: 2** (F4, F5)
- **LOW / leave-as-is: 2** (F6, F7)

---

## Confirmed-clean (prior findings, now resolved — do NOT re-flag)

- **Field-type identifier list** — `FieldTypeRegistry::getAllFieldTypes()` is authoritative; `FieldOps::validTypes()` (`mcp/tools/support/FieldOps.php:34-46`) derives from it; the option subset is now the shared constant `FieldTypeRegistry::OPTION_TYPES` (`services/FieldTypeRegistry.php:27`), consumed via the constant in all 4 sites (`CategorizeSubmissionsTool.php:162`, `FieldOps.php:95`, `FieldsController.php:286`, `FieldSyncService.php:60`). No literal arrays remain. **Resolved.**
- **Submission statuses** — `elements/SubmissionStatus.php` is the source of truth (`NEW/READ/ARCHIVED/SPAM` + `all()/allValid()/isValid()`); runtime consumers (e.g. `ReportsService.php:39-42`) reference the constants. **Resolved.**
- **MCP scopes** — `mcp/Scopes.php` constant holder with `all()/label()/isValid()`. **Resolved.**
- **Integration-dispatch statuses** — `integrations/DispatchStatus.php` constant holder; consumed via constant (`IntegrationsService.php:540-542`). **Resolved.**
- **Integration & captcha type identifiers** — both are registry-driven via per-class `type()` methods (`IntegrationTypeRegistry::registerType()`, captcha providers). No literal duplication of the type list. **Resolved.**

---

## Findings (this pass)

### F1 — `TokenArray` alias exists but is re-typed by hand in two other files  **[HIGH]**

A `@phpstan-type TokenArray` is already defined on the MCP token model:

- `src/mcp/McpToken.php:16` — `@phpstan-type TokenArray array{id?:string,label?:string,hash?:string,scopes?:list<string>,dateCreated?:?string,lastUsed?:?string}`

…but the **identical literal shape** is re-written by hand in two other places instead of being imported:

- `src/mcp/TokenManager.php:179` — inline `/** @var array{id?:string,label?:string,hash?:string,scopes?:list<string>,dateCreated?:?string,lastUsed?:?string} $entry */`
- `src/models/Settings.php:139` — `@var array<int, array{id?:string,…same…}>` on the public `$mcpTokens` property.

This is genuine drift risk: adding a token field (e.g. `expiresAt`) means editing three identical literals, and the project already demonstrates the fix pattern in its GQL layer.

**Proposed change:** Import the alias instead of re-typing. Add `@phpstan-import-type TokenArray from \fabianhaef\simpleform\mcp\McpToken` to the class docblocks of `TokenManager` and `Settings`, then use `TokenArray` at `TokenManager.php:179` and `list<TokenArray>` / `array<int, TokenArray>` at `Settings.php:139`. Zero runtime change.
**Confidence:** HIGH. **Reduces complexity:** yes — one definition, three usages.

---

### F2 — MCP error-result shape `array{isError:true, error:string}` duplicated 9×, no alias  **[HIGH]**

The MCP tool/resource "error envelope" is written as a raw literal in the return type of nearly every tool and resource:

- `src/mcp/McpServer.php`, `src/mcp/tools/CategorizeSubmissionsTool.php:63`, `src/mcp/tools/DetectSpamPatternsTool.php:82`, `src/mcp/tools/SummarizeSubmissionsTool.php:63`, `src/mcp/tools/support/SubmissionQueryBuilder.php:21`, `src/mcp/resources/ResourceProviderInterface.php:48`, `src/mcp/resources/FormSchemaResource.php:61`, `src/mcp/resources/SubmissionsDatasetResource.php:66` (9 occurrences total).

Resources additionally repeat the **success half** too: `array{contents:list<array<string, mixed>>}` appears verbatim at `ResourceProviderInterface.php:48`, `FormSchemaResource.php:61`, `SubmissionsDatasetResource.php:66`.

**Proposed change:** Define `@phpstan-type McpError array{isError:true, error:string}` once (natural home: the MCP tool/resource interface, e.g. `mcp/tools/ToolInterface.php` or a small `mcp/McpResult.php` holder), and `@phpstan-import-type` it across the tools/resources. Optionally also alias the resource success shape `McpResourceContents array{contents:list<array<string, mixed>>}`. Pure PHPDoc, zero runtime risk.
**Confidence:** HIGH. **Reduces complexity:** yes — the most-repeated shape in the codebase.

---

### F3 — `ConditionalEvaluator` constants bypassed by raw literals in the GQL resolver  **[HIGH/MEDIUM]**

`helpers/ConditionalEvaluator.php` is the source of truth for the conditional vocabulary:

- `:45-46` `MATCH_ALL = 'all'`, `MATCH_ANY = 'any'`
- `:48` `OPERATORS = ['eq','neq','empty','notEmpty','contains','gt','lt']`

`NotificationsController::normalizeOperator()` (`controllers/NotificationsController.php:178`) correctly validates against `ConditionalEvaluator::OPERATORS`. But the GraphQL resolver re-hardcodes the same vocabulary as bare literals:

- `src/gql/resolvers/FormGqlResolver.php:110` — `($conditional['match'] ?? 'all') === 'any' ? 'any' : 'all'`
- `:112` — same `'all'/'any'` pattern for `requiredMatch`
- `:132` — `'operator' => (string) ($rule['operator'] ?? 'eq')` (the `'eq'` default duplicates the controller default)
- `controllers/NotificationsController.php:164` — `'match' => 'all'` literal
- `controllers/NotificationsController.php:169` — `'eq'` default literal (also in the resolver and evaluator)

The `'eq'` fallback default is now written in **three** places. A new default operator or a renamed match keyword desyncs the GQL surface from the evaluator/controller.

**Proposed change:** Reference `ConditionalEvaluator::MATCH_ALL` / `MATCH_ANY` at `FormGqlResolver.php:110,112` and `NotificationsController.php:164`; introduce a `ConditionalEvaluator::DEFAULT_OPERATOR = self::EQ` (or just `MATCH_*`-style per-operator constants) and use it for the three `'eq'` defaults. Note: the `switch ($operator)` in `ConditionalEvaluator::compare()` (`:184-205`) using bare `case 'eq':` etc. is intrinsic to that method and fine to leave — but per-operator constants (`EQ='eq'`, …) would let `OPERATORS` be built from them and let the switch use them too.
**Confidence:** HIGH that the GQL/controller `'all'/'any'/'eq'` literals should reference the existing constants; MEDIUM on whether to add per-operator constants for the switch (cosmetic).
**Reduces complexity:** yes for the resolver/controller; marginal for the switch.

---

### F4 — Audit `action` + `targetType` are unconstrained magic strings (enum-like set, no holder)  **[MEDIUM]**

`AuditService::log(string $action, string $targetType, …)` (`services/AuditService.php:23`) takes two free-form strings that form a small, closed vocabulary in practice:

- **action:** `'form.save'`, `'form.delete'` (`controllers/FormsController.php:138,161`), `'submission.status'` (`services/SubmissionService.php:291`), `'integration.create'`/`'integration.save'` (`services/IntegrationsService.php:174-175`), `'integration.delete'` (`:300`), `'notification.delete'` (`services/NotificationsService.php:96`).
- **targetType:** `'form'`, `'submission'`, `'integration'`, `'notification'` (same call sites).

Nothing enforces this set; the filter UI (`AuditController.php:26`) passes whatever string arrives. A typo (`'fom.save'`) silently creates a new, unfilterable audit category. Drift risk is **low** (write-only display/filter labels, no behavioral coupling), but a holder would document the catalog and catch typos.

**Proposed change:** Add an `AuditAction` (or constants on `AuditService`) holding the `action` strings, and reuse the existing `targetType` vocabulary (the same four nouns appear in `Scopes`/element handles — `targetType` could even point at existing element refHandles). Lower-effort variant: just a `const` block on `AuditService` + use at the 7 call sites. Keep migrations' own literals (migrations stay self-contained, per the `DispatchStatus` docblock convention).
**Confidence:** MEDIUM (set is genuinely closed, but low blast radius). **Reduces complexity:** modestly — mainly typo-safety + a documented catalog.

---

### F5 — `success/failed/pending` integration-health stat shape declared twice  **[MEDIUM]**

Two services return overlapping stat shapes keyed on the dispatch outcomes:

- `services/ReportsService.php:117` — `@return array{success: int, failed: int, pending: int}` (built at `:127`)
- `services/IntegrationsService.php:518` — `@return array{total: int, success: int, failed: int, pending: int, lastStatus: ?string, lastDispatchedAt: ?string, lastResponseCode: ?int}` (a superset; built at `:540-542` correctly via `DispatchStatus::*` constants)

The `success/failed/pending` triple is the `DispatchStatus` vocabulary re-expressed as **array keys** in two return shapes. The values are already constant-driven (good); only the *keys* are literal. Minor — these are two distinct return shapes for two distinct methods, so a shared alias is borderline.

**Proposed change (optional):** A `@phpstan-type DispatchCounts array{success:int, failed:int, pending:int}` on `DispatchStatus` (alongside the existing constants), imported by both services; `IntegrationsService` spreads it into its richer shape. Low value; consider only if a third consumer appears.
**Confidence:** MEDIUM. **Reduces complexity:** marginal — flag as watch-item, not a must-do.

---

### F6 — Captcha provider identifiers referenced as literals in `Settings`  **[LOW — leave as-is]**

Captcha provider type IDs are authoritatively defined by each provider's `type()` (`captcha/RecaptchaProvider.php:23` etc.), but `Settings` references the raw literals in validation `when` closures:

- `models/Settings.php:30` (default `'recaptcha'`), `:194,:201` (`=== 'recaptcha'`), `:207` (`=== 'turnstile'`), `:212` (`=== 'hcaptcha'`).

These run at model-validation time where instantiating providers to call `type()` is awkward, and the set is stable. **Leave as-is**, or at most introduce light constants if a provider is ever renamed.
**Confidence:** LOW value. **Reduces complexity:** no.

---

### F7 — Payment status PHPDoc on `Submission` restates `PaymentsService::STATUS_*`  **[LOW — leave as-is]**

`PaymentsService::STATUS_PENDING/STATUS_PAID` (`services/PaymentsService.php:23-24`) are the source of truth, consumed via the constants within the service. `elements/Submission.php:24` documents the same domain as a PHPDoc comment literal (`'pending' = awaiting payment; 'paid' = complete`). It's a doc string, not code, and it's accurate. **Leave as-is** (a property can't reference a service constant in its docblock cheaply). Cosmetic only.
**Confidence:** LOW. **Reduces complexity:** no.

---

## Recommended order (by value / risk)

1. **F2** (MCP error/result alias) — highest repetition (9×+3×), pure PHPDoc, zero risk. **HIGH.**
2. **F1** (import `TokenArray`) — 3 identical literals → 1 def, pure PHPDoc, zero risk. **HIGH.**
3. **F3** (GQL/controller use `ConditionalEvaluator` constants) — real cross-surface drift on the `'all'/'any'/'eq'` vocabulary; small runtime-literal swap. **HIGH.**
4. **F4** (audit action/targetType constants) — typo-safety + documented catalog; low blast radius. **MEDIUM.**
5. **F5** (`DispatchCounts` alias) — watch-item; do only if a third consumer appears. **MEDIUM.**
6. **F6 / F7** — leave as-is.

## Explicitly NOT recommended

- New DTOs / value objects for fields, submissions, or integration configs. The element + thin-model + registry split is appropriate for the plugin's size; promoting array shapes to objects would add ceremony without removing drift. (Concurs with the 2026-06-14 pass.)
- Converting the existing constant holders (`SubmissionStatus`, `DispatchStatus`, `Scopes`) to native PHP 8.1 backed enums. They back public element properties / migration enum columns / token storage; the holder-class form is deliberate and the conversion buys little. **MEDIUM-risk, LOW-reward — skip.**
