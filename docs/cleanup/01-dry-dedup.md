# 01 — DRY / De-duplication Audit

**Plugin:** Simple Form (Craft CMS 5, PHPStan L7, ECS)
**Scope:** `src/` (223 PHP files, ~32k LOC)
**Date:** 2026-06-21
**Method:** 6 parallel read-only sweeps (fields, services, controllers+console, integrations, mcp+gql, helpers+elements+captcha+widgets) plus first-party cross-cutting greps. Every finding below was re-verified against real source before inclusion.

---

## 1. Critical Assessment of Current State

The codebase is **already well-factored for DRY** — this is not a copy-paste mess. The team has built the right shared seams:

- `controllers/SimpleFormControllerTrait` centralizes `getFormOrFail()`, the `asJson{Success,Error,Errors}` envelope, and permission gating via a `PERMISSION` const.
- `captcha/AbstractSiteverifyProvider` already collapses hCaptcha + Turnstile to pure config.
- Integrations have a proper adapter hierarchy (`AbstractCrmIntegration`, `AbstractMarketingIntegration`, `AbstractChatIntegration`, `AbstractGoogleIntegration`).
- `mcp/tools/support/` is exemplary: `SubmissionQueryBuilder::present()`, `FormPresenter::resolveByIdOrHandle()`, and `QuerySubmissionsTool::filterProperties()` are reused across 4–6 tools each rather than duplicated.
- `fields/ElementRelationFieldType` is a real base with `applySources()`/`elementType()` hook points.

The genuine duplication that remains falls into three buckets:

1. **One acknowledged structural duplication** — the field-write CRUD is implemented twice (CP `FieldsController` and MCP `FieldOps`), with `FieldOps` docblocks literally saying *"deliberately mirrors FieldsController"*. This is the single highest-value consolidation in the plugin.
2. **A cluster of small, byte-identical DB/query helpers** duplicated between `FormCloneService` and `FormPortabilityService` (handle-exists, field-id-by-handle, form-content field list).
3. **Transaction + timestamp boilerplate** repeated across 3 services *and* `FieldsController` — a natural fit for a shared `withTransaction()` helper.

Most "looks duplicated" hits are **structural-by-design and should stay** (per-field input triads, per-integration adapters, per-tool MCP schemas, explicit `validate(): []` stubs). Those are catalogued in §4.

Estimated safe reduction from the High-confidence items: **~150–250 LOC** plus removal of two genuinely divergent write paths that already drift (`FieldsController` invalidates form-structure cache and seeds per-site rows from a posted site; `FieldOps` does the same independently — a drift risk every time field schema changes).

---

## 2. Findings

Confidence key: **High** = clearly safe, no behavior change, removes real risk. **Medium** = safe but needs a small judgement call (divergent shapes / scoping). **Low** = marginal ROI or stylistic.

### H1 — Field-write CRUD duplicated across CP and MCP write paths *(High)*

**Sites:**
- `controllers/FieldsController.php:44-95` (`actionAdd`), `:97-160` (`actionEdit`), `:170-185` (`actionDelete`), `:226-256` (`actionReorder`), `:317-325` (`supportedSiteIds`)
- `mcp/tools/support/FieldOps.php:179-222` (`add`), `:230-261` (`update`), `:262-` (`delete`), `:273-` (`reorder`), `:319-` (`supportedSiteIds`)

**What's duplicated:** Both paths hand-write the same two-table insert/update against `{{%simpleform_fields}}` (structural row: `formId/type/name/required/config/sortOrder/...`) + `{{%simpleform_fields_sites}}` (per-site `label/helpText`), with identical `maxSort + 1` computation, identical `helpText ?: null` coercion, identical `Plugin::getInstance()->getFormStructure()->invalidate($formId)` call, and a near-identical `supportedSiteIds()` wrapper around `Form::supportedSiteIds()`. `FieldOps`' own docblocks state: *"This deliberately mirrors FieldsController"* (FieldOps.php:16, 173, 225, 262, 273, 315).

**Proposed consolidation:** Extract a `FieldsService` (e.g. `services/FieldsService.php`) owning `add()/update()/delete()/reorder()`. `FieldOps` already has the right shape — promote it to that service (or have both controller + tool delegate to it). The CP-only concerns (request parsing, `validateFieldInput`, JSON envelope) stay in the controller; the DB writes move to the service.

**Complexity delta:** −80 to −120 LOC; eliminates a documented "keep in lockstep" hazard.

**Risk:** Medium-low. The two differ in two real ways: (a) `FieldsController::supportedSiteIds($formId, $currentSiteId)` falls back to the *posted* site, `FieldOps::supportedSiteIds($formId)` has no current-site fallback; (b) the controller wraps in a transaction, `FieldOps::add` does not. The service must take `?int $fallbackSiteId` and own the transaction. Verify with `SettingsTabsRenderTest` + the MCP smoke Cests before/after.

---

### H2 — `withTransaction()` boilerplate (3 services + 1 controller) *(High)*

**Sites:**
- `services/FormCloneService.php:211-267`
- `services/FormPortabilityService.php:134-144`
- `services/FieldSyncService.php:394-495`
- `controllers/FieldsController.php:53-94` (`actionAdd`), `:125-160` (`actionEdit`), `:242-255` (`actionReorder`)

**What's duplicated:** The identical `$transaction = $db->beginTransaction(); try { … $transaction->commit(); } catch (\Throwable $e) { $transaction->rollBack(); throw $e; }` shell. The controller variant swaps the re-throw for `Craft::warning(...) + return $this->asJsonError(...)`.

**Proposed consolidation:** A `withTransaction(callable $work): mixed` helper. Service variant lives on a small `services` trait or a base; controller variant can live on `SimpleFormControllerTrait` returning the callable's `Response` and converting exceptions to `asJsonError`.

**Complexity delta:** −30 to −50 LOC; removes 6 hand-rolled rollback blocks (rollback-on-throw is easy to get subtly wrong).

**Risk:** Low for the service trait (re-throw is uniform). Slightly higher for the controller version because each action has a bespoke error string and one (`actionAdd`) does a post-commit cache invalidate — keep cache invalidation *inside* the callback, before the implicit commit, or as an explicit post-return step.

---

### H3 — `handleExists()` is byte-identical in two services *(High)*

**Sites:**
- `services/FormCloneService.php:608-614`
- `services/FormPortabilityService.php:415-421`

**What's duplicated:** Identical body:
```php
return (new Query())->from('{{%simpleform_forms}}')->where(['handle' => $handle])->exists();
```
(One imports `craft\db\Query`, the other fully-qualifies `\craft\db\Query` — same call.)

**Proposed consolidation:** Single `formHandleExists(string $handle): bool` on whichever service owns form identity (FormCloneService already exposes `uniqueHandle` logic). Both callers delegate.

**Complexity delta:** −7 LOC.
**Risk:** Trivial / none. *Side note (out of scope):* the FormCloneService docblock claims "case-insensitive" but `where(['handle' => …])` is exact-match — worth a separate correctness ticket, not part of this dedup.

---

### H4 — `fieldIdsByHandle()` query duplicated *(High)*

**Sites:**
- `services/FormCloneService.php:370-383` (`fieldIdsByHandle()`)
- `services/FormPortabilityService.php:570-577` (inline in `overlaySiteContent()`)

**What's duplicated:** Same `select(['id','name'])->from('{{%simpleform_fields}}')->where(['formId' => …])->all()` then folded into a `name => (int)id` map.

**Proposed consolidation:** Promote `FormCloneService::fieldIdsByHandle()` (or a static on a fields helper) and have FormPortabilityService call it instead of the inline loop.

**Complexity delta:** −10 LOC.
**Risk:** Low — identical transform; confirm both want the *same* handle key casing (both cast `(string)$row['name']`).

---

### H5 — Form-content field list duplicated 3× *(High)*

**Sites:**
- `services/FormCloneService.php:504-514` (`contentFrom()`) and `:521-528` (`applyContent()`)
- `services/FormPortabilityService.php:502-510` (`applyFormContent()`)

**What's duplicated:** The canonical 6-attribute list `['title','description','emailTo','emailSubject','emailReplyTo','emailBody']` is hard-coded in three places. Adding a 7th content column today means editing all three.

**Proposed consolidation:** One `private const CONTENT_ATTRS = [...]` on a shared form-content helper (or `Form` element), plus `extractContent(Form): array` / `applyContent(Form, array): void`. FormPortabilityService's `title ?? name` fallback stays as a caller-side override.

**Complexity delta:** −15 LOC, but the real win is a **single source of truth** for the content schema.
**Risk:** Low. Mind the one genuine difference: Portability casts `(string)$content['title']` and falls back to `$form->name`; Clone copies verbatim. Parameterize the title fallback.

---

### H6 — `RecaptchaProvider::verify()` re-implements `AbstractSiteverifyProvider::verify()` *(Medium → High)*

**Sites:**
- `captcha/RecaptchaProvider.php:36-78`
- `captcha/AbstractSiteverifyProvider.php:36-69`

**What's duplicated:** The entire siteverify POST flow — secret check, token fallback from `getBodyParam`, the `httpClient()->post($url, ['form_params' => ['secret','response','remoteip']])`, `json_decode`, `GuzzleException` catch, and the `is_array && !empty($result['success'])` gate — is copy-pasted. The class comment defends the split ("reCAPTCHA differs enough — v3 scoring, per-context key encoding"), but the *verify* path's only real delta is the v3 score threshold (lines 73-75); the per-context key encoding is in `renderWidget()`, not `verify()`.

**Proposed consolidation:** Make `RecaptchaProvider extends AbstractSiteverifyProvider`, implement the 5 abstract config methods, and add a `protected function passesResult(array $result, Settings $settings): bool` hook (default `true`) that RecaptchaProvider overrides for the v3 score. Keep `RecaptchaProvider::renderWidget()` overridden (the v2/v3 markup + key-encoding genuinely differs — that part stays).

**Complexity delta:** −25 LOC in `verify()`; the bespoke `renderWidget()` is untouched.
**Risk:** Medium. RecaptchaProvider currently uses `Settings::getParsedSecretKey()`/`getActiveSiteKey()` whereas the base calls abstract `secretKey()/siteKey()` + a private `parse()`. The subclass would supply those from the Settings accessors — straightforward, but it's a behavioral surface (env parsing, warning message text) so smoke-test all three providers' verify + render after.

---

### M1 — Form/field array-shaping diverges between MCP and GQL *(Medium)*

**Sites:**
- `mcp/tools/support/FormPresenter.php:62-78` (form) / `:85-102` (fields)
- `gql/resolvers/FormGqlResolver.php:28-43` (form) / `:71-98` (`mapField`)

**What's duplicated:** Both shape `id/handle/name/title/description/siteId` from a `Form`, and both extract the same raw field row (`id/type/name/label/required/helpText/sortOrder/config`).

**Proposed consolidation:** A `FormPresenter::formMetadata(Form): array` + `fieldRow(array): array` reused by both. **But note the divergence:** MCP keys the handle as `handle`, GQL keeps `name`; GQL's `mapField()` adds computed `validation/conditional/options/relation/page/placeholder`; MCP omits integrations (separate tool) while GQL inlines them.

**Complexity delta:** −15 LOC at best.
**Risk:** Medium — this is where a shared helper risks becoming **leaky**. The two consumers have different contracts (GQL is a published schema; MCP is tool output). Only extract the truly-identical *metadata* tuple; do **not** force the field mappers together. If the key naming (`handle` vs `name`) can't be unified without changing the GQL schema, leave it. Recommend extracting only the 6-key form-metadata tuple, or skipping entirely.

---

### M2 — Submission "find by id or 404" repeated in controllers *(Medium)*

**Sites:** `controllers/SubmissionsController.php:212-219`, `:270`, `:311-314`, `:364`; `controllers/SubmissionEditController.php:143`; `controllers/IntegrationsController.php:221`.

**What's duplicated:** `Submission::find()->…->id($id)->one()` + `throw new NotFoundHttpException` envelope, mirroring the existing `getFormOrFail()`.

**Proposed consolidation:** A `getSubmissionOrFail(int $id, ?int $siteId = null): Submission` on `SimpleFormControllerTrait`.

**Complexity delta:** −20 LOC.
**Risk:** Medium — **the siteId scoping is inconsistent across the call sites** (`->siteId($siteId)` vs `->siteId('*')` vs none). A naive helper would silently change which submissions resolve from non-primary sites. The helper must take an explicit `siteId` (defaulting to `'*'` only if that matches every caller's intent — verify first). Do **not** blindly unify.

---

### M3 — Timestamp source inconsistency: `date('Y-m-d H:i:s')` vs `Db::prepareDateForDb()` *(Medium)*

**Sites (raw `date()`):** `controllers/FieldsController.php:45,123,247`; `mcp/tools/support/FieldOps.php:182,233,281`; `services/FieldSyncService.php:372`; `services/FormPortabilityService.php:568`.
**Sites (`Db::prepareDateForDb(new \DateTime())`):** `services/FormCloneService.php:438,575`, `services/IntegrationsService.php:128,159,364`, `services/NotificationsService.php:56`, `elements/Form.php:501`, `elements/Submission.php:142`, etc.

**What's duplicated:** Two idioms for "now, for a DB column" coexist. `Db::prepareDateForDb()` is timezone-correct; `date()` uses PHP's default TZ.

**Proposed consolidation:** Standardize on `Db::prepareDateForDb(new \DateTime())`. Note that H1 (FieldsService) and H2 (withTransaction) will absorb most of the `date()` sites automatically.

**Complexity delta:** Neutral LOC; correctness/consistency win.
**Risk:** Low-medium — only a behavior change if the app TZ ≠ DB TZ. Worth a quick check of `config/general` TZ before flipping. Not strictly a *dedup* item, but it rides along with H1/H2.

---

### M4 — Console form-by-handle resolution duplicated *(Medium)*

**Sites:** `console/controllers/FormsController.php:65-68`; `console/controllers/SubmissionsController.php:98-102`.

**What's duplicated:** `Form::find()->handle($this->form)->siteId('*')->…->one()` + `stderr("No form found with handle …")` + error-code return.

**Proposed consolidation:** A `BaseFormCommand` (or console trait) with `resolveFormByHandle(string): ?Form`. Note one differs by `->status(null)` — fold that in as the safe default.

**Complexity delta:** −10 LOC.
**Risk:** Low. The two return different error codes (`ExitCode::DATAERR` vs `false`) — the helper returns `?Form` and lets each caller map to its own code.

---

### L1 — Per-integration settings-field HTML snippets *(Low)*

**Sites:** email-field handle textField in `HubSpotIntegration.php:87-92`, `MailchimpIntegration.php:110-115`, `ActiveCampaignIntegration.php:110-115`; message-template textarea in `SlackIntegration.php:67-73` + `DiscordIntegration.php:62-68`; username textField in `SlackIntegration.php:62-66` + `DiscordIntegration.php:56-61`.

**What's duplicated:** Identical `Cp::textFieldHtml([...])` / `textareaFieldHtml([...])` blocks differing only in instruction text.

**Proposed consolidation:** `protected function emailFieldHtml(array $settings)` on `AbstractCrmIntegration`/`AbstractMarketingIntegration`; `messageTemplateHtml()` / `usernameHtml(?string $instructions)` on `AbstractChatIntegration`.

**Complexity delta:** −40 LOC across 5 files.
**Risk:** Low, but ROI is modest (<2% of integrations LOC) and the labels intentionally differ per provider. Do only if you're already touching those abstract bases.

---

### L2 — `applySources()` boilerplate in 5 relation field types *(Low)*

**Sites:** `fields/AssetRelationFieldType.php:41-47`, `EntryRelationFieldType.php:41-46`, `CategoryRelationFieldType.php:41-46`, `TagRelationFieldType.php:41-46`, `UserRelationFieldType.php:41-46`.

**What's duplicated:** `$sources = $this->sources(); if ($sources !== ['*'] && $query instanceof XQuery) { $query->method($sources); }` — only the query class + method name vary.

**Proposed consolidation:** A `protected function sourceMethod(): ?string` hook in the base `ElementRelationFieldType`, with the base `applySources()` doing `if ($sources !== ['*'] && ($m = $this->sourceMethod())) { $query->$m($sources); }`.

**Complexity delta:** −20 LOC.
**Risk:** Low-medium. The `instanceof AssetQuery/EntryQuery/...` guard is type-safety (and PHPStan-relevant) — a dynamic `$query->$m()` loses the static guarantee and may trip PHPStan L7. **Test that PHPStan stays green** before adopting; if it complains, keep the per-class overrides (they're cheap and explicit). Marginal benefit.

---

## 3. Things That LOOK Duplicated But Should STAY

- **Per-field-type input/normalize/serialize/validate triads** (`fields/*FieldType.php`). Structural-by-design (Craft idiom). Specifically keep:
  - **Checkbox vs Radio rendering** (`CheckboxFieldType.php:46-72` vs `RadioFieldType.php:36-62`) — different `name[]` vs `name`, multi vs single, checked logic. A shared helper would need callbacks and read worse.
  - **Rating vs OpinionScale** (`RatingFieldType.php:91-115` vs `OpinionScaleFieldType.php:92-107`) — divergent markup (icons/aria vs anchored numeric).
  - **Explicit `validate(): []` stubs** in Heading/Divider/Html/Payment/Calculation field types — explicit "I don't validate" is clearer than silent inheritance.
- **Per-integration `defineSettingsRules()` `array_merge(parent::…, [...])`** — correct inheritance, not duplication. Provider-specific required-field checks in `send()` likewise stay.
- **MCP per-tool argument schemas / authz guards** — already centralized where it matters (`filterProperties()`, `resolveByIdOrHandle()`, `present()`); the remaining per-tool wiring is the framework contract.
- **GQL `FormMutations::formatErrors()`/`errorPayload()`** (FormMutations.php:293-317) — GQL-specific envelope, correctly isolated; do **not** merge with the MCP error shape (different consumers).
- **`SubmissionCsv::scalar()` (helpers/SubmissionCsv.php:250-275) vs `ConditionalEvaluator::scalarString()` (helpers/ConditionalEvaluator.php:302-315)** — only the null/false/true *preamble* matches (~5 lines); the array handling genuinely diverges (CSV does consent/repeater/pipe-join + formula-neutralization, the evaluator returns `''`). Keep separate — merging would couple export to conditional logic.
- **`ConsentText::isSafeUrl()` (lightweight scheme check) vs `SafeUrl::isPublicHttpUrl()` (full SSRF guard with DNS/private-IP rejection)** — different threat models; consolidating would force the markdown link-parser to pull DNS logic it doesn't need.
- **Form display-name fallback `$form->title ?? $form->name` scattered across ~10 sites** (`Submission.php:239`, `SubmissionCsv.php:64`, `FormsController.php:219,301,363`, MCP resources, etc.). This already has a canonical home: `Form::__toString()` (elements/Form.php:185-188). *Optional tidy:* callers that have a `Form` instance could use `(string)$form` instead of re-spelling the fallback — but it's a one-liner idiom, Low priority, and several sites add a third fallback (`?? $form->handle` / `?? $submission->formId`), so a single helper wouldn't fit all. Not worth a dedicated refactor.

---

## Recommended Order

1. **H1** (FieldsService) — highest value, removes a documented drift hazard. Ship with the MCP smoke Cests.
2. **H2** (`withTransaction`) — absorbs much of M3 along the way.
3. **H3 / H4 / H5** — trivial, byte-identical service helpers; bundle as one small PR.
4. **H6** (Recaptcha → base) — good cleanup, smoke-test all 3 captcha providers.
5. **M2 / M4 / L1** — only if already in the area; mind the siteId-scoping caveat on M2.
6. **M1 / L2** — lowest priority; both carry leaky-abstraction / PHPStan risk. Extract conservatively or skip.

**Do not** touch anything in §3.
