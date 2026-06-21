# Cleanup Audit 03 — Unused / Dead Code

**Plugin:** Simple Form (Craft CMS 5)
**Scope:** `src/` (223 PHP files). JS dist artifacts ignored per brief.
**Date:** 2026-06-21
**Mode:** Research-only. No source modified.

---

## 1. Critical Assessment & Methodology

**Headline: the codebase is essentially free of removable dead code.** After a full
sweep of every source directory, there is **not a single orphaned class, unreachable
branch, dead constant, or truly-unreferenced public method** that can be deleted. The
handful of "findings" all reduce to *visibility could be tightened* (a `public` symbol
whose only callers are inside its own class) — these are style nits, not dead code, and
removing them would break nothing because they are live.

This is the expected result for a plugin that:

- Passes **PHPStan level 7 with zero errors** (`composer check` green). PHPStan 1.x at
  level 7 already reports unused **private/protected** methods and properties and unused
  `use` imports. Because the gate is green, *those entire categories are already clean* —
  I did not re-hunt them and report none.
- Has been through multiple prior cleanup/refinement loops (per project memory).

### What PHPStan does NOT catch (where I focused)

PHPStan never flags **unused `public` methods/classes/constants**, because any of them
could be called dynamically. That is the only fertile ground for this dimension, and
Craft makes it treacherous: methods are invoked via DI service getters, event handlers,
controller/console `actionX` route convention, Twig functions + `craft.simpleForm.*`
variable API, GraphQL type/query/mutation registration, MCP tool/resource dispatch,
integration-connector registries, captcha/PDF/stencil registries, queue-job `execute()`,
element framework hooks (`defineTableAttributes`, `defineActions`, `beforeSave`, …), and
polymorphic field-type dispatch.

### Method

I read `Plugin.php` (the central wiring), `phpstan.neon`, and `composer.json` autoload
first, then fanned out five read-only agents across non-overlapping directory groups.
Each agent enumerated public symbols and grepped the **whole** plugin —
`src/`, `src/templates/`, `tests/` (unit **and** integration), config — ruling out every
dynamic-dispatch path before flagging. I independently cross-verified each registry
(`FieldTypeRegistry`, `CaptchaProviderRegistry`, `IntegrationTypeRegistry`, `McpServer`,
GraphQL registration in `Plugin.php::registerGraphQl()`), every public constant, every
class short-name's cross-file reference count, and personally re-checked every candidate
the agents returned.

### False-positive risk: HIGH

This dimension is the single most dangerous to act on in a Craft plugin. Several agent
candidates were **confirmed false positives** on re-check (see §4) precisely because the
real caller was a `self::CONST` inside a `match`, a default parameter value, or an
integration test — none of which a naive "grep the method name" turns up cleanly. **Do
not delete anything from §3 without re-verifying;** the §3 entries are visibility
suggestions only and even those are optional.

---

## 2. Verified-clean areas (no findings)

| Area | Why clean |
|---|---|
| **Fields** (`src/fields/`, 33 files) | All 25 concrete `*FieldType` classes registered in `FieldTypeRegistry`. `FieldType` (abstract base), `ElementRelationFieldType` (relation base), `CompositeFieldType`/`CompositeSubField` (Name/Address support) all referenced by subclasses — not orphaned. Field methods dispatched polymorphically. |
| **Elements / GQL** | All `Form`/`Submission` framework hooks live; GQL types either registered in `registerGraphQl()` or used as nested types (`FieldRelationType`, `FieldRelationOptionType`, `FieldValueInputType`, `SimpleFormObjectType`). |
| **Controllers / console / jobs** | Every `actionX` is route-wired or auto-routed by Craft convention; both queue jobs' `execute()` invoked by the queue; `SimpleFormControllerTrait` methods all used. |
| **MCP / integrations** | All tools/resources in `McpServer`'s hardcoded registry; all 9 integration connectors in `IntegrationTypeRegistry`; abstract bases are template-method parents. |
| **Helpers / events / exceptions** | All event-object props read by handlers; all result/exception classes (`IntegrationResult`, `ImportResult`, `DispatchStatus`, `GoogleAuthException`, `FormulaException`) referenced. |
| **Public constants (all 110)** | Every `public const` in `src/` is referenced 2+ times. |
| **Class orphans** | Only `AuditController`/`NotificationsController` appear in a single file by short-name — but they are routed by namespace convention (`simple-form/audit/*`, `simple-form/notifications/*`). Live. |

---

## 3. Findings (visibility-tightening only — nothing removable)

All five entries below are **live code**. None can be deleted. The only available action
is narrowing `public` → `private`/`protected`, which is cosmetic and carries a small risk
of breaking an out-of-repo consumer (third-party MCP/integration extensions, user
templates). **Recommendation: leave as-is unless doing a deliberate encapsulation pass.**

### Low confidence (public, but only internal callers)

| Symbol | Location | Notes / risk |
|---|---|---|
| `Settings::getActiveSecretKey()` | `src/models/Settings.php:319` | Only caller is internal `getParsedSecretKey()` (line 331). Could be `private`. Risk: settings models are sometimes read by external tooling. |
| `FieldOps::validTypes()` | `src/mcp/tools/support/FieldOps.php:34` | Only caller is internal `validate()` (line 91). Could be `private`. |
| `SubmissionQueryBuilder::build()` | `src/mcp/tools/support/SubmissionQueryBuilder.php:26` | Only runtime caller is internal `buildWithForm()` (line 71); referenced in a unit-test docblock. Could be `private`, but it reads as an intentional public entry point alongside `buildWithForm()`. |
| `Formula::FUNCTIONS` (const) | `src/helpers/Formula.php:46` | Used internally (`tokenize`, `parseFunction`). Plausibly intended as a public "supported functions" surface — keep public. |
| `SignaturePng::DEFAULT_MAX_BYTES` (const) | `src/helpers/SignaturePng.php:26` | Used only as the default value for the `$maxBytes` param of `decode()`/`isValid()`. This IS public API (a caller can override). **Keep.** |

**No High- or Medium-confidence removable findings exist.**

---

## 4. Investigated but CONFIRMED USED (do not re-flag)

These were raised as candidates during the sweep and then **disproven** on re-check.
Recorded so a future audit does not waste time re-flagging them.

- **`HiddenValueResolver::USER_ATTR_EMAIL` / `USER_ATTR_ID` / `USER_ATTR_USERNAME`**
  (`src/helpers/HiddenValueResolver.php:32-34`) — flagged "0 references" by an agent, but
  all three are used in the `match` inside `resolveUser()` (lines 89/91/92) via
  `self::USER_ATTR_*`. The agent's grep missed the `self::`-qualified hits. **Live.**
- **`Formula::FUNCTIONS`, `SignaturePng::DEFAULT_MAX_BYTES`** — see §3; both have internal
  consumers (parser / default param). Not dead.
- **`FormPortabilityService::import()`** (`src/services/FormPortabilityService.php:116`) —
  called by `importJson()` and directly by `tests/integration/FormPortabilityTest.php`.
  Live. (Lesson: integration tests are outside `phpstan` analysis paths and must be
  grepped explicitly.)
- **`FormAsset::distPath()`** (`src/web/assets/form/FormAsset.php:35`) — called twice by
  `FormRenderService` for inline CSS/JS. Live.
- **`AuditController`, `NotificationsController`** — routed by Craft's
  controller-namespace convention, not by class reference. Live.
- **`SimpleFormVariable` methods** (`render`, `formStart`, `formEnd`, `field`, `editForm`,
  `editUrl`, `submissions`, `forms`) — registered as the `craft.simpleForm.*` template API
  in `Plugin.php:183`. Only `form` is used in the plugin's own templates; the rest are
  **public template API for end-user sites** and must NOT be treated as dead.
- **All MCP tool / integration / captcha / field / GQL methods** matching an interface or
  registry contract — dynamically dispatched.

---

## 5. Bottom line

Zero removable dead code. Five `public` symbols *could* be narrowed to
`private`/`protected` (§3) but all are live and the change is cosmetic with mild
external-consumer risk. The recommended action for this dimension is **no change**.
