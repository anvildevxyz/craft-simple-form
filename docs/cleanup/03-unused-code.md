# Concern #3 — Unused Code (Assessment)

Plugin: **Simple Form** (Craft CMS 5, PHP 8.2) — `plugins/simple-form`
Scope: `src/` (incl. `src/templates/**`), `tests/`, `migrations/`, `config/`.
Phase: **ASSESSMENT ONLY** — no source files were edited, created, or deleted.
Date: 2026-06-14

---

## Tooling

`composer-unused` and `composer-require-checker` are **not** available in
`vendor/bin/` (verified). PHPStan **is** available, so two tool-based passes were
run as corroborating signals (grep remained the authority for every verdict):

1. **PHPStan level `max` on `src/`** — does not flag dead code by default
   (no unused-symbol output); confirmed clean of dead-code signal.
2. **`shipmonk/dead-code-detector` 0.5.1** (dev-installed into a throwaway
   `phpstan-deadcode.neon`, since removed) — run at level 4 over `src/`. It
   flagged ~25 leaf symbols, but it does **not** understand Craft/Yii entrypoints,
   so it mass-false-flagged every `actionXxx`, `Plugin::getName`, the
   `validateHandleUnique` rule-string validator, and the element-query setters.
   Its non-framework leaf findings were each then **grep-confirmed by hand** — the
   findings below are exactly the subset that survived grep verification.

All verdicts below rest on rigorous, whole-tree `grep`, each with the exact
command(s) shown. A deterministic whole-tree **unused-`use`-import scan**
(PHP token scan: collect every `use` alias, word-match it in the file body)
reported **zero** unused imports across all PHP files in `src/`.

### Important verification note (a real trap, avoided)

The MCP WIP in the working tree — `SummarizeSubmissionsTool`,
`CategorizeSubmissionsTool`, `DetectSpamPatternsTool`, the
`src/mcp/resources/*` providers, and `support/InsightCorpus` — initially *looks*
unused. The committed `McpServer.php` does not register them. But the
**working-tree** `McpServer.php` (modified, uncommitted) DOES:

- `tools()` registers all three new tools (lines 78–80).
- `resourceProviders()` (lines 90–98) registers `FormSchemaResource` +
  `SubmissionsDatasetResource`, dispatched via `resources/list` and
  `resources/read` (lines 144, 296, 320).
- `InsightCorpus::{fieldTypes,freeTextHandles,textValues}` are each called from
  all three new tools.

```
grep -nE "new .*Tool|Resource|InsightCorpus" src/mcp/McpServer.php   # registers the WIP
grep -rn "InsightCorpus::" src                                        # 9 call sites in the 3 new tools
```

**None of the MCP WIP is dead.** It is fully wired. Do not touch it.

`FieldSyncService` (also WIP) is wired into `FormsController::actionSave()`
(`new FieldSyncService()` → `validate()` / `sync()`), so it is live too.

---

## Summary

The codebase is lean. The only genuinely unreferenced code is a small cluster
of **dead accessor methods on two internal model wrappers** plus **one entirely
dead helper class**. Everything dispatched by the framework (controller
`actionXxx`, element overrides like `defineTableAttributes`/`defineSources`,
rule-string validators, GraphQL resolver callables, MCP tool/resource registries,
Twig functions, behaviors) was confirmed reachable.

| # | Symbol | Kind | Confidence |
|---|--------|------|------------|
| 1 | `ElementQueryHelper` (whole class, all 3 methods) | dead helper class | **HIGH** |
| 2 | `FormModel::getTitle/getDescription/getName/getHandle/getEmailTo/getEmailSubject/getConfig/getField` | dead model getters | **MEDIUM** |
| 3 | `FieldModel::getHelpText/getConfig/renderInput` | dead model methods | **MEDIUM** |
| 4 | `FieldType::getConfig()` (abstract base) | unused public API | **LOW** |
| 5 | Translation key `'Captcha verification failed. Please try again.'` | surplus catalog key (exhaustively verified) | **LOW** |

**HIGH-confidence removals: 1** (the `ElementQueryHelper` class).

---

## Findings

### 1 — `ElementQueryHelper` — entire class is dead — **HIGH**

`src/helpers/ElementQueryHelper.php` (class line 11) exposes three public static
methods: `forCurrentSite()` (l.20), `forSite()` (l.32), `forAllSites()` (l.44).
The class and every method are referenced **only by their own definitions** —
nowhere in `src/`, `tests/`, `migrations/`, `config/`, or templates.

Proof:

```
grep -rn "ElementQueryHelper" src tests migrations config
#   src/helpers/ElementQueryHelper.php:11:class ElementQueryHelper      <- definition only

grep -rn "forCurrentSite\|forAllSites" src tests migrations    # only the two defs
grep -rn "forSite"            src tests migrations             # only the def (l.32)
grep -rln "ElementQueryHelper" tests                           # (no output) — no test
```

No import of the class exists anywhere (the unused-`use` scan would have flagged
a stray import; there is none). It is an internal helper (not registered, not
framework-dispatched, no public-API contract via `Plugin.php`). Equivalent
multi-site query logic is instead done inline with `->siteId('*')` /
`->siteId($id)` throughout the MCP tools and controllers, so this helper is
genuinely superseded.

**Recommendation:** delete the whole file `src/helpers/ElementQueryHelper.php`.

---

### 2 — `FormModel` dead getters — **MEDIUM**

`src/models/FormModel.php` is an internal wrapper constructed in exactly two
places, both inside `SubmissionService` (`new FormModel($formElement)` l.42,
`new FormModel($form)` l.103). It is **never passed to a template** (templates
operate on the `Form` *element*, whose native attributes — `form.title`,
`form.handle`, `form.emailTo` — are unrelated to these getters).

`SubmissionService` only ever calls `->getFields()` and (via the `instanceof`
branch) `->getId()`. The remaining getters have zero callers anywhere:

- `getTitle()` (l.44)
- `getDescription()` (l.49)
- `getName()` (l.54)
- `getHandle()` (l.59)
- `getEmailTo()` (l.64)
- `getEmailSubject()` (l.69)
- `getConfig()` (l.77)
- `getField(int $id)` (l.98)

Proof:

```
# what SubmissionService actually calls on the wrapper:
grep -nE '\$formModel->|\$form->get' src/services/SubmissionService.php
#   getFields() (l.46, l.109) and getId() (l.191) only

# the dead getters — zero call sites (method-call form, src+tests):
for m in getTitle getDescription getHandle getEmailTo getEmailSubject; do \
  grep -rn -e "->$m(" src tests; done      # (no output)

grep -rn -e "->getConfig()" src tests      # only Craft::$app->getConfig() — unrelated
grep -rn -e "getField(" src tests | grep -v 'getFields\|getFieldType\|getFieldSet\|function getField'   # (no output)

grep -rln "FormModel" tests                # (no output) — FormModel is not unit-tested
```

`getName()` is shadowed in grep by the `Form`/`FormQuery` `getName`/`name`
methods, so it was verified specifically as `->getName()` on a `FormModel`
instance (the only `->getName()` in `SubmissionService` is on a *FieldModel*,
l.118). No `FormModel` instance calls `getName()`.

**Confidence MEDIUM, not HIGH:** these are public getters on a model. They are
internal (not surfaced through `Plugin.php` nor any template), so removal is
almost certainly safe, but a public getter on a Model class is conventionally
treated as a soft API surface. Safe to remove `getConfig()` and `getField()`
outright; the per-attribute getters could reasonably be kept as a thin
convenience API or removed — recommend removing the truly-unused ones together.

---

### 3 — `FieldModel` dead methods — **MEDIUM**

`src/models/FieldModel.php`. Constructed once (`new FieldModel(...)` in
`FormModel::__construct`, l.28). `SubmissionService` uses `validateValue()`,
`getLabel()`, `getName()`, `getType()` on the field instances. These three are
never called:

- `getHelpText()` (l.53)
- `getConfig()` (l.61)
- `renderInput(string $name, mixed $value = null)` (l.87)

`renderInput()` is a dead parallel to the live rendering path: the real form
rendering calls `FieldType::renderInput()` directly in
`TwigExtension::renderForm()` (l.84, `$fieldType->renderInput($fieldName)`).
`FieldModel::renderInput()` is a separate wrapper that nothing invokes.

Proof:

```
grep -rn -e "->renderInput(" src tests | grep -v 'fieldType->renderInput\|function renderInput'   # (no output)
grep -rn -e "->getHelpText()" src tests        # (no output)
grep -rn -e "->getConfig()"   src tests        # only Craft::$app->getConfig() — unrelated
grep -rln "FieldModel" tests                   # (no output) — not unit-tested

# the live render path (for contrast — NOT this method):
grep -rn "renderInput" src/TwigExtension.php   # src/TwigExtension.php:84  $fieldType->renderInput(...)
```

`getHelpText()` is distinct from the `helpText` references in
`src/templates/forms/edit.html` (those are JS-side `f.helpText` on a plain JS
object, not a PHP method call).

**Confidence MEDIUM** for the same model-getter rationale as #2. `renderInput()`
in particular is dead duplicate logic and a clean removal candidate.

---

### 4 — `FieldType::getConfig()` (abstract base) — **LOW**

`src/fields/FieldType.php` l.25 — public `getConfig()` returning `$this->config`.
No caller in the whole tree.

Proof:

```
grep -rn -e "getConfig" src/fields tests | grep -v 'function getConfig'
#   only Craft::$app->getConfig() in tests — unrelated
```

**Confidence LOW / keep.** `FieldType` is the abstract base for the eight public
field-type classes (`TextFieldType`, `SelectFieldType`, …) registered in the
`FieldTypeRegistry`. A `public getConfig()` on a field-type base is plausibly
part of the intended extension API (third-party field types, or future template
access). Per the public-API rule this stays MEDIUM-at-most and is graded LOW —
**do not remove** without an explicit API decision.

---

### 5 — Translation keys — exhaustively verified — **LOW (1 surplus key)**

`src/translations/en/simple-form.php` holds **54** source-language keys consumed
via `Craft::t('simple-form', 'String')` (PHP) and `{{ 'X'|t('simple-form') }}`
(Twig). This WAS exhaustively verified: a PHP script loaded the catalog array and
literal-searched every key across all `src/**` (php/html/twig/js, excluding the
catalog itself) and `tests/**`.

Result — exactly **one** key is referenced nowhere:

- `'Captcha verification failed. Please try again.'` (catalog line 26)

Proof:

```
php -r '$t=require "src/translations/en/simple-form.php"; ... // load + literal-search every key
        across src/** + tests/**, excluding the catalog file'
# UNREFERENCED (1): Captcha verification failed. Please try again.

grep -rn "Captcha verification failed" src tests
#   src/translations/en/simple-form.php:26  (definition)
#   src/services/SubmissionService.php:99    'captcha' => ['Captcha verification failed']   <- NOTE: no period, NOT Craft::t()
```

Nuance: the actual runtime captcha error is the hardcoded string
`'Captcha verification failed'` (no trailing period, no `Craft::t()` wrapper) at
`SubmissionService.php:99`. The catalog key
`'Captcha verification failed. Please try again.'` is a *near-miss* that the
runtime never emits — so it is genuinely surplus. **Confidence LOW** because
removing a catalog entry is harmless either way (Craft falls back to the source
string); the more useful fix is arguably to route the runtime message through
`Craft::t()` and reuse this key. All other 53 keys are referenced.

---

## Things that LOOK unused but are NOT (do not remove)

Verified reachable via framework/dynamic dispatch:

- **All MCP WIP** — `SummarizeSubmissionsTool`, `CategorizeSubmissionsTool`,
  `DetectSpamPatternsTool`, `mcp/resources/FormSchemaResource`,
  `SubmissionsDatasetResource`, `ResourceProviderInterface`, and
  `support/InsightCorpus` — registered in the **working-tree** `McpServer.php`
  (`tools()` / `resourceProviders()`) and dispatched by name. (See "trap" note.)
- **`FieldSyncService`** — `new FieldSyncService()` in `FormsController::actionSave()`.
- **`Form`/`Submission` element overrides** with 0 direct callers —
  `defineSearchableAttributes`, `defineTableAttributes`,
  `defineDefaultTableAttributes`, `defineSources`, `attributes`/`defineRules`,
  `afterSave`, `afterDelete`, `eagerLoadingMap` — all dispatched by Craft's
  Element base. `validateHandleUnique` is dispatched via the rule string
  `[['handle'], 'validateHandleUnique']` (Form.php l.178).
- **`Form::eagerLoadFields()`** — called from `FormQueries`, `ListFormsTool`,
  `FormsController` (and a test).
- **Element-query builder methods** — `FormQuery::{handle,name}`,
  `SubmissionQuery::{form,formId,readStatus,status}` — all invoked as
  `->handle(...)`, `->formId(...)`, etc. across controllers, GraphQL, MCP tools,
  and `SubmissionQueryBuilder`.
- **GraphQL resolvers** — `resolveForm`/`resolveForms`/`resolveSubmit` wired as
  `[self::class, 'resolveX']` callables; `getFieldDefinitions` dispatched
  polymorphically by `SimpleFormObjectType::getType()`
  (`$class . '::getFieldDefinitions'`).
- **`TwigExtension::renderForm`** — registered as the `simpleForm` Twig function.
- **`Settings::behaviors()`** — Yii behavior hook (env parsing); all Settings
  getters (`getSenderEmail/getSenderName/getActiveSiteKey/getActiveSecretKey/
  getParsedSecretKey`) are used by `TwigExtension`/`CaptchaService`/`EmailService`.
- **`CaptchaService::{VERIFY_URL,TOKEN_PARAM}`**, **`Plugin::EVENT_*`**,
  **`SubmissionEvent`**, **`Settings::CAPTCHA_V3/V2`** — all referenced.
- **All controller `actionXxx`** — each maps to a URL rule in
  `Plugin::registerCpUrlRules()` / `addRules()`.

---

## High-confidence implementation checklist (grep-proven-safe removals)

Only one item is HIGH-confidence safe:

- [ ] **Delete `src/helpers/ElementQueryHelper.php`** (whole file — class +
      `forCurrentSite`/`forSite`/`forAllSites`). Zero references anywhere; no
      import to clean up; not framework-dispatched; not public Plugin API.
      Re-verify before deleting:
      `grep -rn "ElementQueryHelper" src tests migrations config` → expect only
      the definition line.

MEDIUM items (recommend removing, but confirm the model wrappers stay internal
first — they are not exposed via `Plugin.php` or templates today):

- [ ] `FieldModel::renderInput()` (dead duplicate of the live
      `FieldType::renderInput()` path) + `FieldModel::getHelpText()` +
      `FieldModel::getConfig()`.
- [ ] `FormModel::getConfig()` and `FormModel::getField()` (clearly unused);
      optionally the per-attribute getters
      `getTitle/getDescription/getName/getHandle/getEmailTo/getEmailSubject`.

LOW / leave alone: `FieldType::getConfig()` (possible extension API),
translation-key surplus.
