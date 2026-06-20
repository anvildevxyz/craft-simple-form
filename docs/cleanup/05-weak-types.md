# Concern #5 — Weak Types (Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2) · **PHPStan:** level 7 · **Gate:** `composer check` (ECS + PHPStan).
**Scope:** 157 PHP files under `src/`. Read-only assessment — no source files modified.

> Re-audited 2026-06-20 (independent second pass). Confirms the prior assessment and adds an
> empirically-verified explanation of the few remaining untyped *properties*.

## Summary

The codebase is **strongly typed to an unusually high standard.** A full sweep for `mixed`, bare
`array`, untyped params/returns/properties, loose equality, and `@phpstan-ignore` suppressions
turned up no actionable weak typing:

- **Zero loose `==` / `!=`** — strict `===`/`!==` everywhere.
- **Zero `@phpstan-ignore` inline suppressions** in `src/`. The phpstan.neon ignores are all
  documented, justified framework/soft-dep cases (Commerce `class_exists` guards, Yii `addColumn`
  ColumnSchemaBuilder, Yii closure `$this` rebind for SSRF validation, `Plugin::$plugin`).
- **Zero bare `@param array` / `@return array`** without a shape — every documented array carries an
  `array<...>` / `array{...}` / `list<...>` shape.
- **Zero `: array` returns missing a `@return` PHPDoc** (verified by AWK scan of every `): array`
  site against the two preceding lines).
- **Zero missing return-type declarations** on real PHP methods (the single grep hit,
  `RecaptchaProvider.php:115` `function refreshToken()`, is JavaScript inside a JS string).
- Every `mixed` sits at a **genuinely-dynamic boundary** and is the honest type, with an immediate
  type-guard/cast before use.

**High-confidence native-type strengthenings: 0.** **Optional cosmetic PHPDoc tweaks: 2 (low value).**

## Untyped properties — framework-imposed, cannot be fixed (verified)

Four `public` properties carry no native type. All inherit an *untyped* property from a Yii base
class, and **PHP forbids adding a native type to an inherited untyped property** — verified
empirically this pass: a minimal repro throws
`Fatal error: Type of Child::$data must not be defined (as in class Base)`.

| File:line | Property | Base (untyped) | Verdict |
| --- | --- | --- | --- |
| `src/events/SubmissionEvent.php:14` | `public $data = null;` | `yii\base\Event::$data` (`vendor/yiisoft/yii2/base/Event.php:53`) | Stays. Already mitigated with `@var array<string, mixed>\|null`. |
| `src/controllers/SubmitController.php:23` | `public $enableCsrfValidation = true;` | `yii\web\Controller::$enableCsrfValidation` (`vendor/yiisoft/yii2/web/Controller.php:39`) | Stays. |
| `src/controllers/FieldsController.php:22` | `public $enableCsrfValidation = true;` | same | Stays. |
| `src/controllers/McpController.php:54` | `public $enableCsrfValidation = false;` | same | Stays. |

## Genuinely-polymorphic `mixed` (correct — do NOT change)

- **Field-type value surface** — `validate(mixed $value)`, `renderInput(string $name, mixed $value = null)`,
  `hasValue`/`validateOptionMembership` across `src/fields/*FieldType.php` + base `src/fields/FieldType.php`.
  Posted values are legitimately `string|array<int,string>|null`. The abstract base fixes the contract.
- **`FormField::normalizeValue`/`serializeValue`/`inputHtml`** (`src/fields/FormField.php:42,56,76`) —
  override `craft\base\Field` (`mixed $value): mixed`). Invariance forces `mixed`. Verified vs `vendor/craftcms/cms`.
- **`SubmissionExporter::export(): mixed`** (`src/elements/exporters/SubmissionExporter.php:26`) — overrides
  Craft's `ElementExporter::export(): mixed`.
- **JSON/DB narrowing helpers** — `mapRules`/`mapOptions`/`mapConditional`/`stringOrNull`/`intOrNull`/`floatOrNull`
  (`src/gql/resolvers/FormGqlResolver.php`), `valueForField` (`src/services/SubmissionService.php:324`),
  `normalizeSettings` (`src/services/IntegrationsService.php:422`), `formatFieldValue`/`formatFileValue`
  (`src/services/EmailService.php:252,271`), `ConditionalEvaluator::compare/eq/contains/comparable`
  (`src/helpers/ConditionalEvaluator.php`). Each takes raw decoded JSON / posted `mixed` and returns a
  *precise* narrowed type — the textbook-correct `mixed` boundary. The `@return` shapes are already strong.
- **`$request->getBodyParam()/getQueryParam()`** — Yii `Request::getBodyParam()` is `@return mixed`
  (`vendor/yiisoft/yii2/web/Request.php`); code guards before use.

## Optional, low-value PHPDoc tightenings (not required; gate already green)

1. **`IntegrationTypeInterface::defineSettingsRules()` — `@return array<int, mixed>`**
   (`src/integrations/IntegrationTypeInterface.php:33`).
   - Proposed: `@return array<int, array<int|string, mixed>>` to match the implementations'
     own rule methods (`IntegrationModel.php:25`, `NotificationModel.php:34`).
   - Confidence: **High** (additive PHPDoc, no runtime effect). Value: low. Note: this mirrors Yii's
     own untyped `Model::rules(): array`, so leaving it is also defensible.

2. **JSON-RPC `mixed $id` → `string|int|null`** (`src/mcp/McpServer.php:211,319,379,388`,
   `src/controllers/McpController.php:159`).
   - JSON-RPC 2.0 constrains `id` to String/Number/Null.
   - Confidence: **Medium — risky, leave as-is.** `$id` comes from `json_decode` of an untrusted body;
     a malformed client could send an array/object. Native `string|int|null` would throw `TypeError`
     *before* the JSON-RPC error path runs. Keep `mixed` unless the decode site pre-validates `id`.

## Conclusion

No corrective action is warranted on type-safety grounds. **0 weak native types can be safely
strengthened** — the only native-type gaps are PHP-illegal to fix (inherited untyped properties) and
are already mitigated with PHPDoc. The two optional items are cosmetic. **Recommend closing this
concern as clean.**
