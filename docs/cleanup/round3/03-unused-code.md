# Round 3 Cleanup Audit 03 — Unused / Dead Code

**Plugin:** Simple Form (Craft CMS 5)
**Scope:** Full re-audit of `src/` (210 PHP files), `src/templates/` (31 Twig), 8 translation
catalogs, GraphQL SDL, `.phpstorm.meta.php`, `config/`, `tests/`.
**Branch:** `chore/code-quality-round3` (baseline GREEN: PHPStan L7 0 errors, ECS clean, 463 tests).
**Audit baseline diff:** `b812987..HEAD` — 112 files changed, +3295/-2579 (~39 commits of new code:
payments #116, dev-events #219, JS hooks #220, GraphQL SDL #224, make/* generators #222, forms-as-code
#218/#225/#226, migration collapse, multi-field rows, tabbed editor).
**Date:** 2026-06-24
**Mode:** Research-only. No source modified.

---

## 1. Critical Assessment

**The codebase contains exactly one category of removable dead code: 3 orphaned translation keys.**
Every other surface — PHP symbols, Twig partials, GraphQL types, events, jobs, console actions, MCP
tools, field types — is referenced or dynamically dispatched. This matches and slightly extends the two
prior audits (`docs/cleanup/03-unused-code.md`, `docs/cleanup/delta/03-unused-code.md`): both reported
**zero** removable PHP dead code; that conclusion still holds after the ~39 new-code commits. The 3
translation keys are a *new* finding (leftovers from the Notifications-tab UI refactor and a shortened
captcha error string).

### Why the PHP surface is clean
- **PHPStan L7 is green** over `src` + `tests/unit` with the `craftcms/phpstan` ruleset, which already
  flags unused **private/protected** methods/properties and unused `use` imports. Those entire categories
  are therefore clean by construction — I verified the config (`phpstan.neon` analyses `src`, level 7) and
  spot-checked new files (`FormContentHelper`, `SubmissionBodyRenderer`, `MakeController`, `Install`) for
  unused imports: none. ECS (`SetList::CRAFT_CMS_4`) is clean.
- The only fertile ground is **unused `public` methods/classes/constants** (PHPStan never flags these — they
  may be dynamically dispatched) and **non-PHP assets** (Twig partials, translation keys). I focused there.

### Method
Read `Plugin.php` (wiring), `phpstan.neon`, `ecs.php`, `composer.json`, and the **API-stability contract**
(`docs/extending/api-stability.md`). The contract is decisive: it declares `helpers\*`, `jobs\*`,
`controllers\*`, `gql\resolvers\*`, GraphQL *type-class internals*, and undocumented `services\*` methods as
**internal** (fair game), while protecting Elements + their query classes, `events\*`, documented service
methods, the Twig var, extension interfaces, console names, and the GraphQL SDL *shape*.

Then fanned out 5 read-only agents across non-overlapping directory groups, each enumerating public symbols
and grepping the **whole** repo (src, templates, tests unit+integration+smoke, config, SDL, meta) and ruling
out every dynamic-dispatch vector (`self::CONST` in `match`, default params, `Craft::createObject`, string
refs, reflection, Yii inline validators, queue `push(new Job)`, registry tables, route maps, polymorphic
field dispatch, Twig property magic). I independently re-verified the Twig partial map, all 272 translation
keys (via a PHP `require`-and-grep script over src + JS dist), the migration collapse, every new event's
dispatch site, and re-checked the prior audits' cleared items.

### False-positive risk: HIGH
This dimension is the most dangerous to act on in a Craft plugin. My own first-pass Twig grep produced a
**confirmed false positive** (`_form/errors.twig` looked orphaned but is dispatched by *name* via
`FormRenderService::PARTIALS`). The 3 translation-key findings were each verified against the JS dist, all 8
locales, and dynamic-concatenation before flagging. Do not act on anything outside §3 without re-verifying.

---

## 2. Verified-clean areas (no findings)

| Area | Why clean | Verified by |
|---|---|---|
| **helpers/** (15 classes) | All public methods/constants referenced; private parser/state methods called via `self::`. Prior cleared items (`HiddenValueResolver::USER_ATTR_*`, `Formula::FUNCTIONS`, `SignaturePng::DEFAULT_MAX_BYTES`, `SafeUrl::*`, `SiteHelper::*`) re-confirmed live. New `FormContentHelper` (`handleExists`, `fieldIdsByHandle`, `CONTENT_ATTRS`) all used. | agent + manual |
| **events/** (10 classes) | **All 10 dispatched.** 6 new dev-extension events (#219) confirmed: `BeforeIntegrationDispatchEvent`→IntegrationsService:251, `BeforeSendNotificationEvent`→EmailService:49/198, `BeforeValidateSubmissionEvent`→SubmissionService:539, `DefineFieldSetEvent`→FormStructureService:85, `ModifyRenderContextEvent`→FormRenderService:319, `RegisterFieldTypesEvent`→FieldTypeRegistry:125. | agent + manual |
| **services/** (25 classes) | All registered via `Plugin::setComponents()`; documented public methods are API; undocumented public methods all have callers. New `SubmissionBodyRenderer::render()`→EmailService:304/PdfService:186. Prior `FormPortabilityService`/`PaymentsService` items re-confirmed. | agent |
| **elements/ + gql/** | Element + query public methods are public API (incl. `SubmissionQuery::paymentStatus()/orderId()`, `Submission::isPaid()`). All 9 registered GQL types + nested `FieldRelation*`/`FieldValueInputType` live; `FormMutations::getMutations()`→Plugin:389. Framework hooks auto-live. | agent + manual |
| **controllers/ + console/ + jobs/** | All `actionX` route-wired (incl. new `MakeController` #222 — private stub helpers all called); both jobs `push(new …)`'d; `AuditController`/`NotificationsController` namespace-routed (Plugin:668-687). | agent + manual |
| **fields/ mcp/ integrations/ captcha/ pdf/ stencils/ models/ traits/ web/** | All 25 field types + bases registered in `FieldTypeRegistry`; all MCP tools/resources in `McpServer` registry (incl. new `DetectSpamPatternsTool`→McpServer:85); 9 connectors in `IntegrationTypeRegistry`; `NotificationModel::validatePdfAvailable` is a Yii inline validator (defineRules:61); `SimpleFormVariable` methods are the `craft.simpleForm.*` public template API. | agent + manual |
| **Twig templates** (31) | All referenced — incl. `_form/errors.twig` (FALSE-POSITIVE corrected: dispatched by name via `FormRenderService::PARTIALS` line 63, not by include path). | manual |
| **migrations/** | Collapsed to a single `Install.php` in HEAD; all 25 incremental migrations *deleted*. The historical `m260620_000005_*` name-collision is gone. No dead migration. | manual |
| **Constants** | `Form::GUEST_LIMIT_IP` used via `self::` in `GUEST_LIMIT_KEYS` (line 64 → rules line 442); documented "reserved; not stored in v1" — live, not dead. | manual |

---

## 3. Findings

### Removable dead code

| # | file:line | What | Reference-check evidence | Recommended removal | CONFIDENCE | Risk |
|---|---|---|---|---|---|---|
| 1 | `src/translations/en/simple-form.php:18` (+ de/fr/es/it/ja/nl/pt) | Translation key `'Email Subject'` | `php require`-script over all of `src/` (PHP+Twig+JS, ex-translations) → 0 hits; only appearance is in `docs/smoke-tests/SMOKE_TESTS.md` prose. The live notification UI label was renamed to `'Subject'` (`forms/notifications/edit.twig:61`). Absent from JS dist; present in all 8 locales. | Remove the key from all 8 catalogs. | **HIGH** | Low — orphaned source string; if ever re-added, Craft falls back to the source text. |
| 2 | `src/translations/en/simple-form.php:19` (+ 7 locales) | Translation key `'Email Reply-To'` | Same script → 0 code/Twig hits; only in smoke-test docs prose. Live label renamed to `'Reply-to'` (`forms/notifications/edit.twig:67`). Absent from JS dist; present in all 8 locales. | Remove from all 8 catalogs. | **HIGH** | Low — same as #1. |
| 3 | `src/translations/en/simple-form.php:26` (+ 7 locales) | Translation key `'Captcha verification failed. Please try again.'` | Code emits the *shorter*, untranslated string `'Captcha verification failed'` (no `Craft::t`) at `SubmissionService.php:507`. The full-sentence key is never passed to `Craft::t`/`|t` anywhere (grep over src + Twig + JS dist → 0). Present in all 8 locales. | Remove from all 8 catalogs (or, if you prefer to translate the captcha error, wire `SubmissionService:507` to `Craft::t('simple-form', 'Captcha verification failed. Please try again.')` and keep the key). | **MEDIUM** | Low-Medium — choose remove-key OR adopt-key; current behaviour ships an untranslated, slightly different string. |

### Investigated but CONFIRMED USED (do not flag — recorded for future audits)

- **`Submission::isPaid()`** (`src/elements/Submission.php`) — 0 refs in src/templates/tests, but it is a
  **public method on an Element**, which `api-stability.md` declares public API (callable as `submission.isPaid`
  from end-user Twig / host projects). Companion accessor to `isAwaitingPayment()` (heavily used). Same category
  the prior delta audit ruled un-flaggable. **Keep.**
- **`_form/errors.twig`** — dispatched by name (`'errors'`) via `FormRenderService::PARTIALS` (line 63), part of
  the documented overridable-partial render contract. **Live.**
- **`Form::GUEST_LIMIT_IP`** — used in the `GUEST_LIMIT_KEYS` const array + validation; intentionally reserved.
  **Live.**
- **`NotificationModel::validatePdfAvailable`** — Yii inline validator referenced by string in `defineRules()`.
  **Live.**
- **All prior-audit cleared items** (`Formula::FUNCTIONS`, `SignaturePng::DEFAULT_MAX_BYTES`, `HiddenValueResolver::USER_ATTR_*`,
  `FormPortabilityService::import`, `FormAsset::distPath`, all `SimpleFormVariable` methods, all MCP/integration/captcha/field/GQL
  registry-dispatched symbols, `PaymentsService::*`, `SubmissionQuery::paymentStatus()/orderId()`) — re-confirmed live.

---

## 4. HIGH-CONFIDENCE RECOMMENDATIONS

Two HIGH-confidence removals, both with proven zero references and no dynamic-use vector:

1. **Remove translation key `'Email Subject'`** from all 8 locale catalogs
   (`src/translations/{en,de,fr,es,it,ja,nl,pt}/simple-form.php`).
   *Evidence:* `require`-and-grep over `src/` (PHP/Twig/JS) and JS dist returns 0 hits; the live UI label is
   `'Subject'` (`forms/notifications/edit.twig:61`). Only appearance is smoke-test docs prose.

2. **Remove translation key `'Email Reply-To'`** from all 8 locale catalogs.
   *Evidence:* same — 0 code/Twig/JS hits; live label is `'Reply-to'` (`forms/notifications/edit.twig:67`).

A third item — translation key **`'Captcha verification failed. Please try again.'`** — is dead at MEDIUM
confidence: the code emits the un-`t()`'d shorter string `'Captcha verification failed'` (`SubmissionService.php:507`),
so the full-sentence key is unreferenced. Resolve by **either** removing the key **or** wiring the captcha error
through `Craft::t(...)` with this exact key (the better fix, since it would translate the message). Decide before
removing.

**No PHP dead code is recommended for removal.** Every candidate is registered, routed, dispatched, framework-hooked,
or public API per the stability contract.

---

## 5. Bottom line

- **Removable dead code: 3 translation keys** (2 HIGH, 1 MEDIUM) across all 8 locale catalogs.
- **0 removable PHP symbols, 0 dead Twig templates, 0 orphaned classes, 0 dead migrations.**
- 1 false positive caught and corrected (`_form/errors.twig`).
- The PHP surface remains as clean as the two prior audits reported, including across ~39 commits of new code.
