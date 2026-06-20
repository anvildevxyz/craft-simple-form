# Deduplication & DRY — Assessment (round 2)

Plugin: **Simple Form** (`plugins/simple-form`, Craft CMS 5, PHP 8.2)
Phase: **Read-only assessment** — no source files were edited. Every cited site was opened; line numbers are exact at time of review (2026-06-20).

> Note: an earlier pass (`docs/cleanup/01-duplication-dry.md`) flagged field-type duplication
> (triplicated `getOptions()`, copy-pasted `validateLength()` / option-membership). **Those are
> now fixed** — all three helpers live on the base `FieldType` (`src/fields/FieldType.php:58-117`)
> and the choice types call them. This report covers what genuinely remains in the *current* tree.

---

## Overall state

The codebase is well-factored and has clearly been through dedup passes. The MCP layer has
shared support classes (`FieldOps`, `FormPresenter`, `SubmissionQueryBuilder`); the field types
share a base class; integrations compose an `ApiConnector` trait under thin abstract bases; the
controllers share `SimpleFormControllerTrait` for the JSON envelope and permission gate. Most of
what's left is **small, localized copy-paste** rather than structural duplication.

The remaining duplication clusters in three places:

1. **Captcha providers** — `HcaptchaProvider` and `TurnstileProvider` are ~90% byte-identical;
   plus a `parse()` env-resolver triplicated across both providers and `Settings`.
2. **Form-by-id/handle resolution** — the same `Form::find()->siteId('*')->status(null)…`
   preamble (and its id-or-handle branch) is hand-rolled across 4 MCP tools and 3 controllers.
3. **Widget settings HTML** — the two dashboard widgets share an identical permission guard and
   form-options builder.

**HIGH-confidence items: 3** (Findings #1, #2, #5). The rest are MEDIUM/LOW — real, but the
abstraction risks adding indirection for modest gain, so they're optional.

---

## HIGH-confidence findings (safe to implement)

### Finding #1 — `HcaptchaProvider` and `TurnstileProvider` are near-identical — **HIGH**

**Sites:**
- `src/captcha/HcaptchaProvider.php` (whole file, 97 lines)
- `src/captcha/TurnstileProvider.php` (whole file, 97 lines)

The two files differ only in: the `VERIFY_URL` / `TOKEN_PARAM` constants, `handle()`/`displayName()`
strings, which `Settings` property holds the secret/site key, the widget CSS class
(`h-captcha` vs `cf-turnstile`), and the script `src`. The `verify()` bodies
(`HcaptchaProvider.php:35-68` vs `TurnstileProvider.php:35-68`) and `parse()` (lines 84-91 in both)
are **byte-for-byte identical**. `renderWidget()` (lines 70-82) differs only in two literals.

**Proposed change:** introduce an `AbstractSiteverifyProvider` base implementing `verify()`,
`parse()`, `tokenParam()`, and `httpClient()` once, with subclasses supplying:
- abstract/const: `VERIFY_URL`, `TOKEN_PARAM`
- `secretKey(Settings)` / `siteKey(Settings)` accessors
- `renderWidget()` (or a `widgetClass()` + `scriptSrc()` pair so even that collapses to the base).

This removes ~80 lines of exact duplication. `RecaptchaProvider` is deliberately *not* folded in —
its v3 score threshold and dual v2/v3 widget make it genuinely different; keep it standalone.

**Why it reduces complexity:** the siteverify request/parse logic (incl. the SSRF-relevant
`remoteip` handling and the warning-log strings) lives in one place, so a fix or a new
siteverify-style provider is a few lines, not a 97-line copy.

**Confidence: HIGH** — the two bodies are provably identical; the variation is a small fixed set
of literals that map cleanly to overridable members.

---

### Finding #2 — Captcha env-`parse()` helper triplicated — **HIGH**

**Sites (identical logic):**
- `src/captcha/HcaptchaProvider.php:84-91` — `private function parse()`
- `src/captcha/TurnstileProvider.php:84-91` — `private function parse()`
- `src/models/Settings.php:261-270` — `private function parseValue()`

All three are the same: null/empty → null, else `App::parseEnv()`, else keep only a non-empty
string. (`Settings` adds a one-line comment about bool env refs but the behaviour matches.)

**Proposed change:** this is mostly subsumed by Finding #1 (the two captcha copies collapse into
the shared base). The third copy in `Settings` can either stay (it's a model-local concern) or
both can delegate to a single small static helper — e.g. `App::parseEnv`-wrapping in
`fabianhaef\simpleform\helpers` (there's already a `helpers/` namespace). Lowest-risk: fold the two
captcha copies via Finding #1 and leave `Settings::parseValue` as-is.

**Confidence: HIGH** (for the captcha pair, via #1). Folding `Settings` too is **MEDIUM** — marginal
benefit, crosses a layer boundary (model vs provider).

---

### Finding #5 — Identical settings-HTML preamble in the two dashboard widgets — **HIGH**

**Sites:**
- `src/widgets/RecentSubmissionsWidget.php:75-80` and the body-guard at `:39-41`
- `src/widgets/SubmissionCountWidget.php:75-80` and the body-guard at `:55-57`

Both `getSettingsHtml()` open with a **byte-identical** form-options builder:
```php
$formOptions = [['label' => Craft::t('simple-form', 'All forms'), 'value' => '']];
foreach (Form::find()->siteId(Craft::$app->getSites()->getCurrentSite()->id)->all() as $form) {
    $formOptions[] = ['label' => (string) ($form->title ?? $form->name), 'value' => (string) $form->id];
}
```
And both `getBodyHtml()` open with the identical VIEW_SUBMISSIONS permission guard returning the
same translated string.

**Proposed change:** a small shared trait (e.g. `SubmissionWidgetTrait` in `src/widgets/`) with
`protected function formOptions(): array` and `protected function permissionDenied(): ?string`
(returns the guard message or null). Two widgets `use` it. ~12 lines removed, and the "current-site
form picker" gains one home.

**Why it reduces complexity:** the form-picker query is the kind of thing that drifts (e.g. if it
should ever respect `status`/propagation); one copy means one fix.

**Confidence: HIGH** — mechanical, both copies identical, only two consumers but the extraction is
trivial and the intent (shared widget plumbing) is clear.

---

## MEDIUM / LOW-confidence findings (optional — weigh indirection cost)

### Finding #3 — "Resolve form by id-or-handle" repeated across 4 MCP tools — **MEDIUM**

**Sites (same shape):**
- `src/mcp/tools/GetFormTool.php:53-66`
- `src/mcp/tools/UpdateFormTool.php:72-84`
- `src/mcp/tools/DeleteFormTool.php:68-74`
- `src/mcp/tools/ListIntegrationsTool.php:55-62`

Each opens with:
```php
$query = Form::find()->siteId('*')->status(null)->unique();
if (isset($arguments['id'])) { $query->id((int)$arguments['id']); }
elseif (isset($arguments['handle']) && is_string($arguments['handle'])) { $query->handle($arguments['handle']); }
else { return ['isError' => true, 'error' => 'Provide either "id" or "handle".']; }
$form = $query->one();
if (!$form instanceof Form) { return ['isError' => true, 'error' => 'Form not found.']; }
```

**Proposed change:** add `FormPresenter::resolve(array $arguments)` (or a new
`support/FormResolver`) returning `Form|array` (the element, or the `isError` payload), so each tool
does `$form = …; if (is_array($form)) return $form;`. The id/handle JSON-schema property pair
(`'Provide id OR handle.'`, 8 occurrences) could share a constant too.

**Why MEDIUM not HIGH:** the in-band `['isError' => …]` return contract means a shared helper has to
return a union type, which is slightly awkward and PHPStan-noisy. The win (~6 lines × 4 sites) is
real but each copy is simple and self-evident; this is a judgement call, not a clear-cut DRY win.
Implement only if a 5th tool is about to copy it again.

---

### Finding #4 — `getFormOrFail()` duplicated; same query inline in a third controller — **MEDIUM**

**Sites (identical private method):**
- `src/controllers/IntegrationsController.php:26-33`
- `src/controllers/NotificationsController.php:25-32`

Both define the exact same `getFormOrFail(int $formId): Form` doing
`Form::find()->siteId('*')->id($formId)->status(null)->one()` + `NotFoundHttpException`. The same
query also appears **inline 3×** in `FormsController.php:60, 91, 152`.

**Proposed change:** hoist `getFormOrFail()` into `SimpleFormControllerTrait` (already `use`d by all
three controllers) and replace the inline `FormsController` queries. Note `FormsController:91` is a
`?? Form::find(...)` fallback, so it'd be a small adaptation, not a drop-in.

**Why MEDIUM:** the two-copy method is a clean lift, but the trait currently only owns the JSON
envelope + permission gate — adding domain element-lookup to it slightly broadens its
responsibility. Defensible, but it's a small mixing-of-concerns trade-off. The `FormsController`
inline sites have enough local variation that the consolidation is less clean there.

---

### Finding #6 — `supportedSiteIds()` duplicated (flagged in-code) — **LOW/MEDIUM**

**Sites:**
- `src/controllers/FieldsController.php:301-308`
- `src/mcp/tools/support/FieldOps.php:319-329` (its docblock literally says
  *"Mirrors FieldsController::supportedSiteIds."*)

Both derive the field's target site IDs from the form's propagation method, with a primary-site
fallback. `FieldOps` adds an extra empty-array guard, so the two aren't byte-identical.

**Proposed change:** put one `supportedSiteIds(int $formId, ?int $fallbackSiteId): list<int>` on a
shared helper (the form-resolution helper from Finding #3, or `FormStructureService`). The in-code
"Mirrors …" comment signals the author already knows it's a duplicate.

**Why LOW/MEDIUM:** the two copies have intentionally different fallback behaviour and live across a
controller/service boundary; the shared home isn't obvious, and forcing one risks coupling the MCP
support layer to a web controller. Worth doing only alongside Finding #3's helper.

---

### Finding #7 — `httpClient()` Guzzle-config repeated — **LOW**

**Sites:**
- `src/integrations/support/ApiConnector.php:76-85`
- `src/integrations/AbstractChatIntegration.php:125-134`

Both build `Craft::createGuzzleClient([... timeout 10, connect_timeout 5, allow_redirects false])`
with the same SSRF comment. (`RecaptchaProvider`/captcha use a plain client — different needs, leave
them.)

**Why LOW:** only two copies, and they sit in two deliberately-separate composition roots
(`ApiConnector` trait for API integrations vs the chat-integration base). Sharing them would mean a
third tiny utility or making the chat base `use ApiConnector` purely for one method — net indirection
roughly equal to the savings. **Recommend leaving as-is** unless a third hardened-client consumer
appears.

---

## Explicitly NOT recommended (premature abstraction)

- **The three registries** (`FieldTypeRegistry`, `IntegrationTypeRegistry`, `CaptchaProviderRegistry`)
  share a register/get/`typeHandles`/`getAll` shape and even cross-reference each other in docblocks.
  A generic `Registry<T>` base is tempting but PHP generics are docblock-only; the bodies are short,
  the key derivation differs (`getType()` vs `handle()`), and two add an event-dispatch step the
  third doesn't. A shared base would add a type-parameter ceremony for ~15 saved lines. **Leave
  separate.**
- **Field-type `validate()` overrides** (`EmailFieldType`, `NumberFieldType`, `SelectFieldType`,
  `RadioFieldType`): the `parent::validate()` + `hasValue()` guard pattern repeats, but each body is
  genuinely different validation. The shared parts already live on the base. **No change.**
- **GQL types**: boilerplate is framework-mandated, not duplication. **No change.**

---

## Suggested order

1. Finding #1 + #2 (captcha base) — biggest single win, ~80 lines, mechanical.
2. Finding #5 (widget trait) — trivial, clearly correct.
3. Findings #3 / #4 / #6 together (one form-resolution helper) — only if doing the MCP/controller
   layer anyway; weigh the union-return awkwardness.
4. Finding #7 — skip unless a third consumer arrives.
