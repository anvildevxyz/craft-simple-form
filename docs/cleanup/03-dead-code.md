# Dead Code Audit — Simple Form

**Date:** 2026-06-20
**Concern:** Unused / dead code (unreferenced methods, classes, helpers, constants, properties, imports, config keys, templates, translation keys, assets).
**Method:** READ-ONLY research. Exhaustive ripgrep cross-referencing across `src/`, `src/templates/*.html` (Twig), `tests/`, `src/translations/`, `composer.json`, and `Plugin.php`, with explicit attention to dynamic dispatch (Yii/Craft convention methods, service-component access via `$this->get('…')`, Twig `craft.simpleForm.*`, GraphQL/MCP/integration registries, `actionFoo` console/web routing, element/field/widget/exporter/action interface methods, migration-by-name execution).

## Critical Assessment

**This codebase is clean. There is no meaningful dead code to remove.**

The plugin has been through prior cleanup passes and the result holds up under scrutiny. Four parallel research agents swept services/helpers, models/elements/fields, MCP/integrations/GraphQL, and templates/translations/assets. **Every concrete "dead" candidate the agents produced was either a hallucination (a method that does not exist in the file) or a false positive (a symbol that is in fact referenced, often via a path the agent's grep missed — tests, an internal caller, or a base-class/registry).** Those candidates were each independently re-verified against the actual source and dismissed. This is a useful caution: automated dead-code passes over a Craft/Yii plugin produce a high false-positive rate because of convention dispatch, and every hit must be confirmed against the real file.

Confirmed-clean dimensions:

- **Imports (`use`):** Zero unused imports. Consistent with ECS (Craft set, includes the no-unused-imports sniff) being clean. A regex-based scan flagged only `use SomeTrait;` *trait-use statements inside class bodies* (not imports) and `\t`-corrupted false hits — all verified used.
- **Controller actions / private helpers:** All `actionFoo` methods are live route/CLI entry points; all 24 private controller helpers are referenced (verified individually).
- **Console commands:** All 6 `actionX` console actions are CLI entry points (Craft `controllerNamespace` convention).
- **Settings (config keys):** All 27 public Settings properties and all 4 constants (`CAPTCHA_V3/V2`, `AKISMET_FLAG/BLOCK`) are read in services/controllers/templates/rules.
- **Plugin constants/events:** All 5 (`EVENT_*`, `EDITION_PRO`) are referenced.
- **Templates:** All 20 `.html` templates are rendered (`renderTemplate`) or dynamically included; the 7 `settings/_tabs/*.html` are pulled in by the `{% include 'simple-form/settings/_tabs/' ~ … %}` loop matching `SettingsController::TAB_FIELDS`.
- **Assets:** All 5 dist files are declared in `SimpleFormCpAsset`/`FormAsset` (or loaded via `FormAsset::distPath()` in the inline fallback). The top-level `/cpresources/*` hashed dirs are *published copies* (build artifacts), not source — ignored.
- **Translations:** All ~109 en keys are used. (One *inverse* issue noted below — a key used but missing — not dead code.)
- **MCP:** All 17 tool classes instantiated in `McpServer::tools()`; both resource providers registered; `Scopes`/`McpToken`/`TokenManager` all used.
- **Integrations:** All 7 connectors registered via `IntegrationTypeRegistry`; `IntegrationResult` (only `success()`/`failure()` exist), `DispatchStatus` (incl. `all()` used by `isValid()`), and all 6 `support/` classes referenced.
- **GraphQL:** All 9 registered types plus the base `SimpleFormObjectType` (extended by all) and `FieldValueInputType` (used in `FormMutations`) referenced.
- **Traits/widgets/captcha/elements:** `HasPropagation` used by `Form`; both widgets registered in `Plugin`; all captcha providers registered; all element/field framework-convention methods are live by dispatch.

## HIGH-confidence removals (safe to remove)

**None.** No symbol meets the bar of being genuinely unreferenced including dynamic paths.

## Uncertain / judgment-call (do NOT remove without a decision)

### 1. `SubmissionService::getSubmission(int $submissionId): ?Submission` — LOW priority, NOT recommended for removal
- **Location:** `src/services/SubmissionService.php:263`
- **Finding:** The method's only caller is `SubmissionService::updateStatus()` (same class, line 274). No external callers in controllers, tests, MCP, GraphQL, or docs.
- **Why NOT dead:** It is a thin public service method (`Submission::find()->id($id)->one()`). It is *used* — just internally. As public service API it is reasonable to keep (third-party / Twig `craft.app.plugins…->submissionService.getSubmission()` access is possible and untestable from here). Inlining it into `updateStatus()` would be a micro-simplification, not a dead-code removal, and carries a (small) public-API-break risk.
- **Verification:** `grep -rn 'getSubmission\b' src tests docs` → definition + the one internal call only.
- **Recommendation:** Leave as-is, or fold the simplification into a separate "internal API tidy" pass — not a dead-code change.

## Related non-dead-code finding (out of scope, worth flagging)

### Missing translation key (NOT dead code — the inverse)
- The Twig string `'Dispatch failures'|t('simple-form')` is used in `src/templates/settings/integrations/failures.html:3` but is **absent** from `src/translations/en/simple-form.php` (and the other catalogs). Craft falls back to the source string, so it renders, but it's an i18n gap. Belongs to an i18n-parity audit, not dead-code removal.

## Notes for future auditors

- Treat automated dead-code agent output as *leads only*. In this run, 100% of agent-named candidates failed verification (`FieldModel::getOptions`, `Submission::getStatusLabel`, `IntegrationResult::retryable`/`permanentFailure`/`$retryAfter` — **none of these methods/properties exist**; `SimpleFormVariable::forms()`/`submissions()` and `DispatchStatus::all()` — **all referenced**). Always `cat` the file and re-grep before believing a "dead" verdict.
- `cpresources/` is published output. Never audit it as source.
- Migrations (incl. `m260618_000003_global_form_integrations`) run by name and are always live; the global-integrations feature is present and wired (routes, pivot table, controller actions) in this checkout.
