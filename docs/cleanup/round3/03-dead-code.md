# Dead Code Audit (Round 3) — Simple Form

**Date:** 2026-06-20
**Concern #3:** Unused / dead code — unreferenced private/public methods, properties, class constants, whole classes, traits, never-used parameters, dead `use` imports, unreachable branches.
**Scope:** `src/` (162 files), namespace `fabianhaef\simpleform`, PHP 8.2. READ-ONLY on source.
**Method:** Machine verification (PHPStan 1.12 @ level 7, with its built-in unused-private detection proven active) + three parallel read-only Explore agents over disjoint subtrees (services/controllers/helpers/jobs/events; models/elements/fields/gql/traits/widgets/captcha/web; mcp/integrations/migrations/Plugin) + my own grep cross-checks against every dynamic-reference channel.

## Critical Assessment

**This codebase is clean. Nothing is grep-proven dead. Zero GitHub issues filed.**

This is the second audit (PR #146 ran the first earlier today) to reach the same verdict, and it holds under fresh, independent scrutiny with a stronger machine baseline. The prior round's central lesson — *100% of automated/agent-named dead-code candidates failed verification* — repeated here: every "suspect" surfaced by naive grep heuristics resolved to a live symbol once the real dynamic-dispatch channel was checked. A Craft/Yii plugin routes most of its surface by convention (route strings, `::class` registration arrays, migration-by-name, interface dispatch, Twig variable access), so raw "appears once" counts are worthless without per-symbol channel verification.

### Machine baseline (strongest single signal)

- **PHPStan 1.12 @ level 7 is clean** (`[OK] No errors`) over `src` + `tests/unit`.
- I **proved PHPStan's unused-private detection is active** in this config: injecting a throwaway `private function __deadProbe9988()` into `src/helpers/SiteHelper.php` produced `Method ...::__deadProbe9988() is unused.`; removing it restored the clean run. PHPStan 1.x reports unused private methods/properties/constants by default at any level.
- **Conclusion:** there are **zero statically-detectable unused private methods, properties, or class constants** anywhere in `src/` or `tests/unit`. That removes the entire "private dead code" category by machine proof — the easiest and normally most-fruitful slice of concern #3 is empty here.

### My own cross-checks (all confirm clean)

- **Class constants:** all **80** `const` definitions referenced ≥2× across `src`+`tests` (script: count each name, flag ≤1 → none flagged).
- **Dead `use` imports:** a plugin-wide scanner flagged only `use SimpleFormControllerTrait;` (×7 controllers) and `use SubmissionWidgetTrait;` (×2 widgets) — these are **trait-use statements inside class bodies**, not imports, and all referenced traits are live. **Zero dead imports** (consistent with ECS clean).
- **Orphan whole-classes:** a class-short-name scan flagged 14 migrations + `AuditController` + `NotificationsController` as "referenced only in their own file." **All false positives:** migrations run **by name** (always live); the two controllers are dispatched by **route strings** in `Plugin::registerCpUrlRules()` (e.g. `'simple-form/notifications/save' => 'simple-form/notifications/save'`, `'simple-form/settings/audit' => 'simple-form/audit/index'`), never by class name.

### Agent sweep (three disjoint subtrees, all "nothing proven dead")

Each agent `cat`-confirmed each suspect exists, then grep-proved references across templates, `::class` registration, event handlers, GraphQL, MCP, console, migrations, and tests.

- **Services/controllers/helpers/jobs/events:** every service method is called from a controller/service/job; every `actionXxx` is a route/CLI entry point; helper statics are used; event properties are read by listeners; framework hooks (`init`, `beforeAction`, `behaviors`) are dispatched.
- **Models/elements/fields/gql/traits/widgets/captcha/web:** all element hooks (`actions()`, `exporters()`, `defineTableAttributes`, …), field hooks (`normalizeValue`, `getInputHtml`, …), query-builder methods, GraphQL types (incl. base `SimpleFormObjectType`, extended by all 10), widget UI methods, and captcha providers are wired.
- **MCP/integrations/migrations/Plugin:** all **17** MCP tools `new`-instantiated in `McpServer::tools()`; both resource providers registered; all **7** integration connectors registered in `IntegrationTypeRegistry::init()`; 3 abstract bases extended; `ApiConnector` trait + `SubmissionValues` helpers used; all 5 `Plugin` constants (`EVENT_*`, `EDITION_PRO`) referenced; ~60 imports all referenced; migrations live by name.

## PROVEN-dead findings

**None.** No symbol meets the grep-proven-zero-reference bar across all channels. **No issues filed**, per the task rule (file only on proof).

## Investigated, NOT dead (referenced via …)

| Symbol | Where it's actually used |
| --- | --- |
| `AuditController`, `NotificationsController` (whole classes) | Craft route strings in `Plugin::registerCpUrlRules()` (`simple-form/notifications/*`, `simple-form/settings/audit` → `audit/index`); dispatched by controller-name convention, not class ref. |
| All 14 `m26…` migration classes + `safeUp`/`safeDown` | Run **by name** by Craft's migrator; never referenced by class name. Always live. |
| `SubmissionService::getSubmission()` (`SubmissionService.php:263`) | Called internally by `updateStatus()` (`:274`). Public service API used internally — **not dead** (same finding as round-1; do not "remove," at most an internal-tidy candidate outside dead-code scope). |
| `HasPropagation::getSupportedSites()` / `supportedSiteIds()` | Called via `$form->getSupportedSites()` / `$form->supportedSiteIds()` in `FormsController`, `FieldsController`, `FieldSyncService`, `FieldOps`. |
| `TwigExtension::renderForm()` + class | Registered in `Plugin.php:136`; `renderForm` called from `SimpleFormVariable::render()`. |
| `SubmissionExporter`, `SetSubmissionStatus` | Registered in `Submission::exporters()` / `Submission::actions()` via `::class`. |
| `DispatchStatus::all()` / `isValid()` | `isValid()` used in `IntegrationsService.php:316`; `all()` backs `isValid()`. |
| `SimpleFormObjectType` (GQL base) | Extended by all 10 concrete GQL types. |
| `PaymentsService` (Commerce soft dep) | Registered (`Plugin.php:123`); `prepare`/`handleOrderCompleted`/`markPaid` called from `SubmissionService` + order-completed event handler. PHPStan ignores guarded `craft\commerce\*` per config. |
| All 17 MCP tools, both MCP resources, all 7 integration connectors, all 80 constants, ~60 `Plugin` imports | Registered/instantiated/referenced (see agent sweep). |
| `use SimpleFormControllerTrait;` ×7, `use SubmissionWidgetTrait;` ×2 | Trait-use inside class bodies (not imports); traits are live. False positive of any import-scanner regex. |

## Notes for future auditors

- **Don't re-run a from-scratch dead-code hunt on this plugin.** Two independent audits today both found zero. The PHPStan unused-private baseline is the durable proof for the private surface; re-run `composer phpstan` and confirm `[OK] No errors` — if still clean, the private-dead category is empty by machine, full stop.
- **Heuristic "appears once / only in own file" counts are noise here.** Every orphan candidate this round was dispatched by route string or by migration-name. Always resolve the dynamic channel before believing a verdict; `cat` the file first (round-1 had hallucinated symbols that didn't even exist).
- **`cpresources/` is published build output, not source — never audit it.**
- Throwaway scripts used this round live in `/tmp` only (`/tmp/dead_use.php`, `/tmp/consts.txt`, `/tmp/pubmethods.txt`); no source was modified.
