# Concern #5 — Remove Weak Types (Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2) · **PHPStan:** level 7, currently `[OK] No errors` (re-verified during this assessment).
**Scope:** ~72 PHP files under `src/`. Assessment only — no source files were modified.

## Summary

The codebase is already **strongly typed to an unusually high standard.** A full sweep for `mixed`,
bare `array`, `object`, missing return types, `iterable`, and weak PHPDoc turned up almost no lazy
typing. Specifically:

- **Zero** bare `@param array` / `@return array` PHPDoc (no shape) anywhere — every documented array
  carries an `array<...>` or `array{...}` shape.
- **Zero** missing return-type declarations on real methods (the one grep hit, `TwigExtension.php:173`
  `function refreshToken()`, is JavaScript inside a Twig/JS string, not PHP).
- Every `object` occurrence is legitimate: JSON-RPC `(object)[]` empties, JSON-Schema `'type' => 'object'`
  strings, `array|object` on the JSON-RPC result envelope, or prose in comments. None is a lazy stand-in
  for a known class.
- Every native `array` hint that returns/accepts a structured value already has a matching `array<...>` /
  `array{...}` / `list<...>` PHPDoc — checked across all GraphQL types, field types, MCP tools, models,
  elements, helpers, and services.
- Every `mixed` is at a **genuinely-dynamic boundary** and is the honest type:
  `$request->getBodyParam()/getQueryParam()/getRequiredBodyParam()` (Yii `Request::getBodyParam()` is
  documented `@return mixed`, confirmed `vendor/yiisoft/yii2/web/Request.php:659`), `json_decode(..., true)`,
  `Cache::get()`, GraphQL resolver `$source`, and JSON-RPC `$id`. In every case the code immediately
  type-guards (`is_array()`, casts, `??`) before use, which is exactly the correct pattern. These must
  **stay** `mixed`.

The two subagent sweeps surfaced ~45 raw "weak-type" candidates, but after vendor research **all but a
handful are false positives** — they are the legitimate dynamic boundaries above, or methods that already
carry a correct PHPDoc shape the grep didn't see on the same line. The subagents also produced two outright
false positives that I verified against source (see "Rejected" below).

**Net genuine findings: 6 — all PHPDoc-only, all HIGH confidence, all cosmetic strengthening.** There is
no `any`/`unknown`-equivalent rot here; only a few methods whose native `: array` could carry a (currently
absent) precise PHPDoc shape, plus two whose existing shape is slightly looser than the Yii/Craft base they
already inherit.

---

## Findings

### HIGH confidence (PHPDoc-only; confirmed against source; will not break PHPStan or inheritance)

#### H1 — `src/elements/Form.php:123` `defineSearchableAttributes(): array`
- **Current:** native `: array`, no PHPDoc.
- **Actual value:** `return ['name', 'handle', 'title', 'description'];` → a positional list of attribute
  handles, i.e. `list<string>`.
- **Evidence:** body is a literal list of strings. Base `craft\base\Element::defineSearchableAttributes()`
  (`vendor/craftcms/cms/src/base/Element.php:1250`) is documented only as `array`, so a narrower PHPDoc is
  covariant-safe.
- **Change:** add `@return list<string>` docblock. **PHPDoc-only.**

#### H2 — `src/elements/Form.php:141` `defineDefaultTableAttributes(string $source): array`
- **Current:** native `: array`, no PHPDoc.
- **Actual value:** `return ['title', 'handle', 'emailTo', 'dateCreated'];` → `list<string>`.
- **Evidence:** literal list; base `Element::defineDefaultTableAttributes()`
  (`vendor/craftcms/cms/src/base/Element.php:1648`) returns `array_keys(...)`, documented `array`.
- **Change:** add `@return list<string>`. **PHPDoc-only.**

#### H3 — `src/elements/Submission.php:157` `defineDefaultTableAttributes(string $source): array`
- **Current:** native `: array`, no PHPDoc.
- **Actual value:** `return ['form', 'dateCreated', 'readStatus'];` → `list<string>`.
- **Evidence:** literal list; same base method as H2.
- **Change:** add `@return list<string>`. **PHPDoc-only.**

#### H4 — `src/models/Settings.php:82` `behaviors(): array`
- **Current:** native `: array`, no PHPDoc.
- **Actual value:** one named behavior config:
  `array{parser: array{class: class-string, attributes: list<string>}}`.
- **Evidence:** body returns `['parser' => ['class' => EnvAttributeParserBehavior::class, 'attributes' => [...]]]`.
  Yii base `yii\base\Component::behaviors()` is documented
  `@return array<array-key, class-string|array{class: class-string, ...}>`
  (`vendor/yiisoft/yii2/base/Component.php:466`). The precise local shape is a strict subtype, so it is safe.
- **Change:** add `@return array{parser: array{class: class-string, attributes: list<string>}}`
  (or, more conservatively matching the base, `@return array<string, array{class: class-string, attributes: list<string>}>`).
  **PHPDoc-only.**

#### H5 — `src/models/Settings.php:101` `defineRules(): array` — tighten existing `@return array<int, mixed>`
- **Current:** `@return array<int, mixed>` (a documented shape, but `mixed` element is loose).
- **Actual value:** a list of Yii validation rules.
- **Evidence:** Craft's `defineRules()` wraps `yii\base\Model::rules()`, documented
  `@return array<int, array<array-key, mixed>|Validator>` (`vendor/yiisoft/yii2/base/Model.php:168`).
  Tightening the element type from `mixed` to `array<array-key, mixed>|Validator` matches the framework
  contract and is what the body actually returns.
- **Change:** `@return array<int, array<array-key, mixed>|\yii\validators\Validator>`. **PHPDoc-only.**
  *(Recommend running PHPStan after — Craft's stubs occasionally add rule shapes; if it complains, fall
  back to `array<int, array<int|string, mixed>>`. Confidence HIGH that one of these two is accepted.)*

#### H6 — `src/elements/Form.php:162` `defineRules(): array` — same tightening as H5
- **Current:** `@return array<int, mixed>`.
- **Evidence/Change:** identical to H5 — `@return array<int, array<array-key, mixed>|\yii\validators\Validator>`.
  **PHPDoc-only.**

---

### LOW confidence / judgement calls (document, do not act without owner sign-off)

#### L1 — `src/Plugin.php:216` `getCpNavItem(): ?array` — `@return array<string, mixed>|null`
- The base `PluginInterface::getCpNavItem()` is documented `@return array|null`
  (`vendor/craftcms/cms/src/base/PluginInterface.php:175`). The method merges `parent::getCpNavItem()`
  (an opaque Craft nav-item array whose full shape Craft does not publish) and adds a `subnav`. The
  parent's contribution is genuinely open-ended, so `array<string, mixed>` is **the honest ceiling** here —
  the `subnav` sub-shape could be documented (`array{subnav?: array<string, array{label: string, url: string}>, ...}`)
  but it would be a partial/`...`-open shape over a vendor-owned base array. **Leave as-is** unless the
  owner wants the documentary value; classified LOW because it does not remove real weakness.

#### L2 — `src/models/FieldModel.php:17` `private array $config` (`@var array<string, mixed>`)
- This is the **decoded JSON `config` column** (field-type options, `required`, `placeholder`, `options`,
  etc.). Its keys vary by field type, so `array<string, mixed>` is the correct honest type for a JSON blob.
  A union of per-field-type `array{...}` shapes would be over-engineering at a real dynamic boundary.
  **Leave as-is.** Same reasoning applies to `FieldType::$config`, `Submission::$data`, and every
  `array<string, mixed>` carrying decoded-JSON or request payloads.

#### L3 — Native `: array` on `defineSources()` / `defineTableAttributes()` (Form & Submission)
- These already carry correct `array<int, array<string, mixed>>` / `array<string, array<string, mixed>>`
  PHPDoc. Could in principle be tightened to Craft's documented source-shape `array{key: string, label: string, ...}`,
  but Craft itself documents these as plain `array`, the shapes are large/optional-heavy, and PHPStan is
  already green. **Not worth the churn; LOW.**

---

### Rejected (subagent false positives — verified against source, NOT findings)

- **`src/fields/FieldType.php:48` `getInputAttributes()` "missing return type."** False — source reads
  `protected function getInputAttributes(string $name, mixed $value = null): string`. Return type present.
- **All `*FieldType::validate()` "weak `array` return."** False — every one carries `@return string[]`
  (Select/Checkbox/Radio/Number/Text/Textarea/Email/Date + base `FieldType::validate`), and
  `FieldModel::validateValue()` likewise (`src/models/FieldModel.php:67`). Already strong.
- **All `$request->get*Param()` / `json_decode` / `Cache::get` → `mixed` "weak types."** Rejected as
  findings — these are legitimately `mixed` at the framework boundary (Yii `Request::getBodyParam()`
  documented `@return mixed`) and are correctly type-guarded before use. Forcing a narrower type here would
  be **dishonest typing**, not a fix.
- **GraphQL resolver `mixed $source` (`FormMutations`, `FormQueries`, `FormGqlResolver::mapOptions`).**
  Rejected — `$source` is the parent graphql-php resolver value and is contractually `mixed`; narrowing it
  would violate the resolver signature. Correct as-is.

---

## High-confidence implementation checklist

All six are **PHPDoc-only**, additive, and covariant with the inherited Craft/Yii contracts. Apply, then
run `composer phpstan` (expect it to stay `[OK] No errors`).

- [ ] **H1** `src/elements/Form.php:123` — add `@return list<string>` to `defineSearchableAttributes()`.
- [ ] **H2** `src/elements/Form.php:141` — add `@return list<string>` to `defineDefaultTableAttributes()`.
- [ ] **H3** `src/elements/Submission.php:157` — add `@return list<string>` to `defineDefaultTableAttributes()`.
- [ ] **H4** `src/models/Settings.php:82` — add `@return array<string, array{class: class-string, attributes: list<string>}>` to `behaviors()`.
- [ ] **H5** `src/models/Settings.php:101` — tighten `defineRules()` PHPDoc from `array<int, mixed>` to `array<int, array<array-key, mixed>|\yii\validators\Validator>`.
- [ ] **H6** `src/elements/Form.php:162` — same tightening on `defineRules()` as H5.

After applying, re-run `composer phpstan`; if H5/H6 trip a Craft-stub rule-shape mismatch, fall back to
`array<int, array<int|string, mixed>>` (still strictly stronger than the current `mixed` element).
