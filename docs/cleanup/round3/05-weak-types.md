# Concern #5 — Weak Types (Round 3, independent pass)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2, `fabianhaef\simpleform`) · **PHPStan:** level 7 · **Gate:** `composer check` (ECS + PHPStan).
**Scope:** 162 PHP files under `src/`. **Read-only** — no source modified.
**Date:** 2026-06-20. Independent re-audit; cross-checks the round-1 report (`docs/cleanup/05-weak-types.md`, PR #146).

## Verdict

The codebase is strongly typed to a high standard. The round-1 conclusion ("mostly PHPDoc-mitigated,
2 cosmetic items, recommend closing") is **substantially correct**, but a fresh sweep of method
*parameters* (which round-1 did not enumerate) surfaced a small set of **untyped params**, of which
**two are genuine, safe native-type strengthenings** the round-1 report missed:

- `SubmissionService::createFromRequest($form, …)` / `resolveForm($form)` — plugin's own methods, PHPDoc
  `@param FormModel|Form|string`, no native type. → add native `FormModel|Form|string`.
- `SubmissionQuery::status($value = null)` — drops the parent's `array|string|null` native type. → restore it.

Everything else (every `mixed`, every typed `array` property, the inherited untyped properties, the
remaining untyped params) is either a genuine dynamic boundary or framework-imposed and **must stay**.

Empirical results from this pass:
- **Zero bare `@param array` / `@return array` / `@var array`** — every documented array carries a
  shape (`array<…>` / `array{…}` / `list<…>`). Verified by regex + an AWK scan of all 229 `): array`
  return sites against their preceding 6 lines: **every one** has a shaped `@return`.
- **Every typed `array` property** carries a `@var` value shape (or, for `McpToken::$scopes`, a
  constructor `@param list<string>` that PHPStan applies to the promoted property).
- **Zero loose `==`/`!=`**, **zero inline `@phpstan-ignore`** in `src/`.
- **Loose `iterable`/`object`:** only one `object` — `McpServer::result(mixed $id, array|object $result)`
  (line 379), correctly documented `array<string, mixed>|object` (a JSON-RPC result is genuinely either).

## Genuine findings (issues filed)

### F1 — `SubmissionService::createFromRequest()` / `resolveForm()` untyped `$form`
`src/services/SubmissionService.php:35` and `:301`.
- Weak: `function createFromRequest($form, …)` and `private function resolveForm($form)` — no native
  type; PHPDoc already says `@param FormModel|Form|string`.
- Strong: native `FormModel|Form|string $form`. Both are the plugin's own methods on a plain
  `Component` (no interface/parent constrains them — verified: `class SubmissionService extends Component`,
  no `implements`). The body already branches on `instanceof Form` / `instanceof FormModel` and otherwise
  treats `$form` as a handle string, so the union is exact.
- Confidence: **High.** Risk: **Low** (additive native type matching existing PHPDoc + actual usage).
  PHPStan L7 stays green. Keep `createFromRequest` public-API behavior identical.

### F2 — `SubmissionQuery::status()` drops the parent's native param type
`src/elements/db/SubmissionQuery.php:50`.
- Weak: `public function status($value = null): static` — untyped param.
- Parent: `craft\elements\db\ElementQuery::status(array|string|null $value): static`
  (`vendor/craftcms/cms/src/elements/db/ElementQuery.php:990`; interface at
  `ElementQueryInterface.php:572`). The override **drops** the type.
- Strong: `public function status(array|string|null $value = null): static`. The type `array|string|null`
  equals the parent's (LSP-legal — invariant/contravariant-compatible), and **adding** the `= null`
  default is permitted on an override. The body already handles `is_array($value) ? ($value[0] ?? null) : $value`,
  so all three members are exercised.
- Confidence: **High.** Risk: **Low.** PHPStan L7 stays green. Note: keep the `= null` default — callers
  rely on `->status()` with no arg (the plugin allows clearing the status filter).

## Legitimately dynamic / framework-imposed (keep — do NOT file)

**Untyped params that are framework overrides (PHP forbids narrowing an inherited untyped param — adding
a native type is a fatal error; round-1 verified the analogous property case empirically):**
- `IntegrationsController::options($actionID)` / `SubmissionsController::options($actionID)` (console) —
  override `yii\console\Controller::options($actionID)` (untyped; `vendor/yiisoft/yii2/console/Controller.php:452`). PHPDoc `@param string` present. Keep native untyped.
- `SubmissionsController::beforeAction($action)` (CP) and `SimpleFormControllerTrait::beforeAction($action)` —
  override `yii\base\Controller::beforeAction($action)` (untyped; `Controller.php:307`). Keep.
- `SendIntegrationJob::execute($queue)` — `yii\queue\JobInterface::execute($queue)` (untyped;
  `vendor/yiisoft/yii2-queue/src/JobInterface.php:21`). Keep.
- `SendIntegrationJob::canRetry($attempt, $error)` — `yii\queue\RetryableJobInterface::canRetry($attempt, $error)`
  (untyped; `RetryableJobInterface.php:27`). PHPDoc `@param int`/`@param \Throwable` present. Keep.

**Inherited untyped properties (PHP-illegal to type, already `@var`-mitigated; per round-1, verified):**
- `events/SubmissionEvent.php:14` `public $data` (← `yii\base\Event::$data`).
- `controllers/{SubmitController:23, FieldsController:22, McpController:54}` `$enableCsrfValidation`
  (← `yii\web\Controller::$enableCsrfValidation`).

**Genuinely-polymorphic `mixed` (honest dynamic boundary, narrowed immediately at use):**
- Field-type value surface: `validate(mixed $value)` / `renderInput(string, mixed $value = null)` /
  `hasValue`/`validateOptionMembership` across `src/fields/*FieldType.php` + base `FieldType.php`.
  Posted values are legitimately `string|array<int,string>|null`; the abstract base fixes the contract.
- `FormField::normalizeValue`/`serializeValue` (`mixed): mixed`) and `inputHtml(mixed $value, …)`
  (`src/fields/FormField.php:42,56,76`) — override `craft\base\Field`'s invariant `mixed` signatures.
- `SubmissionExporter::export(ElementQueryInterface): mixed` (`elements/exporters/SubmissionExporter.php:26`)
  — overrides Craft `ElementExporter::export(): mixed`.
- JSON/DB/posted-value narrowing helpers, each taking raw `mixed` and returning a *precise* narrowed type:
  `FormGqlResolver::{mapConditional,mapRules,mapOptions,stringOrNull,intOrNull,floatOrNull}`,
  `FormQueries::{resolveForm,resolveForms}` + `FormMutations::resolveSubmit` (GraphQL `$source`),
  `SubmissionService::valueForField(): mixed`, `IntegrationsService::normalizeSettings(mixed $raw)`,
  `AssetUploadService::resolveFolderId(mixed $volumeHandle)` (`$fieldConfig['volume'] ?? null` — decoded
  JSON config), `EmailService::{formatFieldValue,formatFileValue}`, `SubmissionCsv::scalar`,
  `NotificationsController::nullableString`, `FieldModel::validateValue(mixed $value, …)`, and the whole of
  `ConditionalEvaluator` (`compare/normalizeMatch/isEmpty/eq/contains/comparable/scalarString`).
  Each is the textbook-correct `mixed` boundary; the returns already carry strong shapes.
- JSON-RPC `mixed $id` (`mcp/McpServer.php:211,319,379,388`, `controllers/McpController.php:159`) — from
  `json_decode` of an untrusted body; a malformed client can send array/object, so native
  `string|int|null` would `TypeError` *before* the JSON-RPC error path. Keep `mixed`.
- Yii `$request->getBodyParam()/getQueryParam()` are `@return mixed`; all call sites guard before use.

**`array<…, mixed>` PHPDocs that are honest (keep):**
- Yii rules arrays: `defineRules()` in `widgets/RecentSubmissionsWidget.php:93` &
  `SubmissionCountWidget.php:98` (`array<int, array<int|string, mixed>>`), and
  `IntegrationTypeInterface::defineSettingsRules(): array` (`@return array<int, mixed>`). Rule entries mix
  positional attribute-lists with keyed options — `mixed` is the honest element type, mirroring Yii's own
  untyped `Model::rules(): array`. (Round-1's optional item #1 — leaving as-is is defensible; not filed.)
- Submission-data / env-resolved-settings / decoded-DB-row maps across `EmailService`, `AkismetService`,
  `NotificationsService`, `PaymentsService`, `IntegrationsService`, `FieldSyncService` — `array<string, mixed>`
  is the honest type for arbitrary, field-id-keyed user input and per-connector settings.

## Over-broad unions reviewed — all correct
- `console/.../SubmissionsController.php:92` `resolveFormId(): int|null|false` and
  `elements/Submission.php:104` `eagerLoadingMap(…): array|null|false` — the `false` sentinel is meaningful
  (Craft's eager-loading-map contract / "no resolution"); not narrowable. Keep.
- `controllers/{McpController:51,SubmitController:21}` `protected array|bool|int $allowAnonymous` — matches
  Craft `Controller::$allowAnonymous`'s own union. Keep.
- `controllers/SettingsController.php:191` `normalizeTab(string|int|float|bool|null $raw)` — `$raw` is a
  `getBodyParam` scalar; the wide union is the honest posted-scalar boundary. Keep.

## Prioritized list

| # | Finding | Confidence | Risk | Auto-safe now |
| - | ------- | ---------- | ---- | ------------- |
| F1 | `SubmissionService::createFromRequest`/`resolveForm` `$form` → `FormModel\|Form\|string` | High | Low | Yes |
| F2 | `SubmissionQuery::status($value)` → `array\|string\|null $value = null` | High | Low | Yes |

Net change vs. round-1: **+2 genuine native-type findings** (both param-level, both safe). All other
items confirmed legitimately dynamic or framework-imposed. The round-1 "recommend closing" stands once
F1 and F2 land.
