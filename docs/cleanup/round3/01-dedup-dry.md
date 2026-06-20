# Deduplication & DRY — Assessment (round 3)

Plugin: **Simple Form** (`plugins/simple-form`, Craft CMS 5, PHP 8.2)
Phase: **Read-only assessment** — no `src/` files were edited. Every cited site was opened and verified firsthand against the current tree (post-PR #146). Line numbers exact at 2026-06-20.

## How this relates to prior passes

Two prior dedup reports exist: `docs/cleanup/01-dedup-dry.md` (round 1/2 field+captcha+widget+MCP) and `docs/cleanup/round2/01-duplication-dry.md` (MCP insight tools + deferred items). **PR #146 (merged) implemented most of their HIGH-confidence findings.** I re-verified each:

| Prior finding | Status now |
|---|---|
| #1/#2 captcha base (`AbstractSiteverifyProvider`) | **DONE** — `src/captcha/AbstractSiteverifyProvider.php` exists; Hcaptcha/Turnstile extend it. |
| #5 widget trait (`SubmissionWidgetTrait`) | **DONE** — `src/widgets/SubmissionWidgetTrait.php` exists, both widgets `use` it. |
| #A1 insight `resolveForm()` | **DONE** — single-sourced on `InsightCorpus::resolveForm()`; 3 tools delegate. |
| #B1 `supportedSiteIds` normalizer | **DONE** — single loop on `HasPropagation::supportedSiteIds()`; all 3 callers delegate (only form-load + fallback differ, as recommended). The "Mirrors FieldsController" docblock in `FieldOps` is now stale but harmless. |
| #4 `getFormOrFail()` (MEDIUM) | **STILL OPEN** — see Finding R3-1. |
| #B2 `resolveByIdOrHandle` (MEDIUM) | **STILL OPEN**, now **4 sites** (was 3). See Finding R3-2. |
| #A2 6-tool submission preamble (MEDIUM) | **STILL OPEN** — verbatim across 6 tools. See Finding R3-3. |
| #A3 `AbstractFormResource` (MEDIUM) | **STILL OPEN**, now slightly *more* duped (both providers gained an identical `$seen` dedupe). See Finding R3-4. |
| #7 `httpClient()` Guzzle config (LOW, "2 sites, leave it") | **REFUTED / UPGRADED** — it is actually **3 sites**, and the broader integration HTTP plumbing is duplicated far more than round 2 saw. See Finding R3-5 (the largest new finding). |

## Overall state

The codebase remains genuinely well-factored: field types share a base, GQL object types share `SimpleFormObjectType`, the MCP layer has `SubmissionQueryBuilder`/`FormPresenter`/`FieldOps`/`InsightCorpus`, controllers share `SimpleFormControllerTrait`, and CRM/marketing integrations share the `ApiConnector` trait. The remaining duplication is concentrated in **the integration HTTP layer** (the one area the prior passes under-weighted) plus a handful of carried-over MEDIUM items the prior passes consciously deferred.

**New HIGH findings: 2** (R3-5 integration HTTP plumbing, the largest; R3-7 a clean single-file `SubmissionCsv` lift). Plus one new MEDIUM (R3-8 submission-data value extraction across 6 sites). The carried-over items (R3-1..R3-4) are all MEDIUM — real, but each introduces a new helper/base, so none is "purely mechanical" except R3-1.

---

## Findings

### Finding R3-5 — Integration HTTP plumbing duplicated; `AbstractChatIntegration` + `WebhookIntegration` bypass `ApiConnector` — **HIGH (value), MEDIUM (mechanicalness)**

This is the biggest genuine duplication in the plugin and the prior passes saw only a sliver of it (round-2 #7 graded `httpClient()` as 2-site LOW "leave as-is"). The reality:

**Class shape:** `AbstractMarketingIntegration` and `AbstractCrmIntegration` `use ApiConnector` (which already provides `httpClient()`, `resultFromResponse()`, `resolveEmail()`, `mappedFields()`). But **`AbstractChatIntegration` and `WebhookIntegration` implement `IntegrationTypeInterface` directly and reimplement the HTTP plumbing themselves.**

Four duplicated pieces:

1. **`httpClient()` — byte-identical in 3 places:**
   - `src/integrations/support/ApiConnector.php:76-85`
   - `src/integrations/AbstractChatIntegration.php:125-134`
   - `src/integrations/WebhookIntegration.php:197-206`
   All three: `Craft::createGuzzleClient(['timeout'=>10,'connect_timeout'=>5,'allow_redirects'=>false])` with the same SSRF comment.

2. **2xx-response handler — byte-identical in 3 places:**
   - `ApiConnector.php:67-74` (`resultFromResponse`)
   - `AbstractChatIntegration.php:78-83`
   - `WebhookIntegration.php:146-151`
   ```php
   $code = $response->getStatusCode();
   if ($code >= 200 && $code < 300) { return IntegrationResult::success($code, 'OK'); }
   return IntegrationResult::failure($code, substr((string) $response->getBody(), 0, 500));
   ```

3. **SSRF guard — byte-identical in 6 places** (only the variable name varies):
   - `AbstractChatIntegration.php:65-67`, `WebhookIntegration.php:127-129`, `PipedriveIntegration.php:51-53`, `ActiveCampaignIntegration.php:50-52` (and the two CRM/marketing forms)
   ```php
   if (!SafeUrl::isPublicHttpUrl($url)) {
       return IntegrationResult::failure(null, 'Blocked request to a non-public address');
   }
   ```

4. **try/catch request wrapper — structurally identical across 7 request sites** (Mailchimp, HubSpot, Pipedrive, ActiveCampaign×2, + the inlined chat/webhook):
   ```php
   try {
       $response = $this->httpClient()->request($method, $url, [ /* auth/json/headers */, 'http_errors' => false ]);
   } catch (\Throwable $e) {
       return IntegrationResult::failure(null, $e->getMessage());
   }
   return $this->resultFromResponse($response);
   ```
   The only per-integration variation is the options array (auth scheme + body).

**Proposed change (two steps, both behaviour-preserving):**
- Add a single `protected function request(string $method, string $url, array $options): IntegrationResult` to `ApiConnector` that does the SSRF guard + try/catch + `resultFromResponse` in one place. Each CRM/marketing `send()` collapses to building `$options` and `return $this->request('POST', $url, $options);`.
- Make `AbstractChatIntegration` and `WebhookIntegration` `use ApiConnector` (they already reimplement its methods byte-for-byte, so adopting the trait is a **net deletion** of `httpClient()`, the 2xx handler, the guard, and the try/catch). They keep only their payload-building.

**Why it reduces complexity (not just lines):** the SSRF policy, the redirect/timeout policy, and the failure-string vocabulary are *security-relevant* and currently live in up to 6 copies that must stay in agreement. A future SSRF tweak (or adding logging/retry) is today a 6-file edit; after this it is one. ~60-80 lines removed.

**Confidence: HIGH** that the duplication is real and the bodies are identical. **MEDIUM** on mechanicalness: it touches ~8 files and the exact `request()` signature is a small design call (e.g. whether to expose `resultFromResponse` separately for ActiveCampaign's two-call flow). Risk: low-medium — pure consolidation, well covered if the integration tests exercise each provider; verify by running `composer test` + the integration smoke for each provider (chat, webhook, each CRM/marketing).

---

### Finding R3-6 — Identical URL-validation rule closure in 4 integration settings — **MEDIUM**

**Sites (byte-identical closures):**
- `AbstractChatIntegration.php:38-42`
- `WebhookIntegration.php:82-86`
- `ActiveCampaignIntegration.php:33-37`
- `PipedriveIntegration.php:34-38`
```php
[['<field>'], function($attribute, $params, $validator, $value): void {
    if (is_string($value) && !SafeUrl::isAcceptableSettingUrl($value)) {
        $this->addError($attribute, Craft::t('simple-form', 'The URL must be a public http(s) address.'));
    }
}],
```

**Proposed change:** add a static rule factory to the existing `SafeUrl` helper, e.g. `SafeUrl::settingUrlRule(string $attribute): array` returning the `[[$attribute], \Closure]` rule (the closure does the `isAcceptableSettingUrl` check + the translated `addError`). Each `defineRules()` then does `SafeUrl::settingUrlRule('url')`. `SafeUrl` is the right home — it already owns `isAcceptableSettingUrl`/`isPublicHttpUrl`/`assertPublicHttpUrl`.

**Why MEDIUM:** the closure binds `$this` (the model) via `addError`, so the factory must return a closure that receives the validator/attribute rather than capturing `$this` — a small, well-understood Yii idiom, but not a blind copy-paste. Real win: one definition of "this setting must be a public http(s) URL" + one translated string. Risk: low. Verify: the 4 integrations' settings still reject a private/loopback URL (existing validation tests / a quick CP save).

---

### Finding R3-1 — `getFormOrFail()` duplicated; same query inline in a third controller — **MEDIUM** *(carried from round-1 #4, re-verified)*

**Sites (byte-identical private method):**
- `src/controllers/IntegrationsController.php:26-33`
- `src/controllers/NotificationsController.php:25-32`
Both: `Form::find()->siteId('*')->id($formId)->status(null)->one()` + `throw new NotFoundHttpException('Form not found')`. The same query also appears inline in `FormsController.php:60` and `:153` (drop-in), and as a `?? fallback` at `:91`.

**Proposed change:** hoist `getFormOrFail(int $formId): Form` into `SimpleFormControllerTrait` (already `use`d by all three controllers); replace the two private copies and the two drop-in `FormsController` inline queries. Leave `FormsController:90-91` (per-site-then-`*` fallback) as-is — it has genuine local variation.

**Why MEDIUM:** clean lift for the two copies, but the trait currently owns only the JSON envelope + permission gate; adding a domain element-lookup slightly broadens it. Defensible. Risk: low. Verify: `composer test` + a 404 still returned for a bad `formId`.

---

### Finding R3-2 — "resolve form by id-or-handle" block in 4 MCP tools — **MEDIUM** *(carried from #B2; now 4 sites)*

**Sites (byte-identical, modulo whitespace):**
- `src/mcp/tools/GetFormTool.php:53-66`
- `src/mcp/tools/DeleteFormTool.php:68-80`
- `src/mcp/tools/UpdateFormTool.php:72-83`
- `src/mcp/tools/ListIntegrationsTool.php:55-67` **(4th site — new since round 2)**
```php
$query = Form::find()->siteId('*')->status(null)->unique();
if (isset($arguments['id'])) { $query->id((int)$arguments['id']); }
elseif (isset($arguments['handle']) && is_string($arguments['handle'])) { $query->handle($arguments['handle']); }
else { return ['isError' => true, 'error' => 'Provide either "id" or "handle".']; }
$form = $query->one();
if (!$form instanceof Form) { return ['isError' => true, 'error' => 'Form not found.']; }
```
The matching `inputSchema` id/handle property pair (`'The form id. Provide id OR handle.'` / handle) is also duplicated across the same 4 tools.

**Proposed change:** `FormPresenter::resolveByIdOrHandle(array $arguments): Form|array` (returns the `Form` or the `isError` payload), mirroring the well-liked `SubmissionQueryBuilder::build()` query-or-error pattern. Each tool collapses to `$form = FormPresenter::resolveByIdOrHandle($arguments); if (is_array($form)) return $form;`. Optionally add `FormPresenter::idOrHandleProperties(): array` for the shared inputSchema pair. **Do NOT** merge with the insight-tool `InsightCorpus::resolveForm` or `GqlQueries::resolveForm` — those are deliberately different resolvers (no `->unique()`, different defaults, different return contract).

**Why MEDIUM:** safe and low-risk, but spans 4 files + a new helper with a union return type (slightly PHPStan-noisy). Promotable to HIGH once `FormPresenter::resolveByIdOrHandle` is accepted as the canonical home. Verify: each tool still errors identically on missing id/handle and on not-found.

---

### Finding R3-3 — Submission-tool preamble duplicated across 6 MCP tools — **MEDIUM** *(carried from #A2)*

**Sites (verbatim):**
- `QuerySubmissionsTool.php:77-85`, `ExportSubmissionsTool.php:66-74`, `SubmissionStatsTool.php:51-59`, `SummarizeSubmissionsTool.php:69-77`, `CategorizeSubmissionsTool.php:69-77`, `DetectSpamPatternsTool.php:88-96`
```php
$built = SubmissionQueryBuilder::build($arguments);
if (is_array($built)) { return $built; }
/** @var SubmissionQuery $query */
$query = $built;
$query->with(['form']);
$fieldMatch = is_array($arguments['fieldMatch'] ?? null) ? $arguments['fieldMatch'] : [];
```

**Proposed change:** add thin accessors to `SubmissionQueryBuilder`: `fieldMatch(array $args): array` (the coercion, mechanical) and `buildWithForm(array $args): SubmissionQuery|array` (does `build()` + `with(['form'])`, leaving the `is_array` bail to the caller). Keep `QuerySubmissionsTool` on the lower-level `build()` only if its paging needs it — actually it too eager-loads `form`, so all 6 can use `buildWithForm`.

**Why MEDIUM:** safe, but spans 6 files and the helper signature is a small design call (the `is_array` bail must remain `return`-able in the caller). The `fieldMatch()` coercion accessor alone is a clean near-mechanical win. Verify: each tool returns identical results; `composer test`.

---

### Finding R3-4 — Two MCP resource providers duplicate scheme/handles/list-loop/read-preamble — **MEDIUM** *(carried from #A3)*

**Sites (`FormSchemaResource` vs `SubmissionsDatasetResource`):**
- `handles()` — byte-identical `str_starts_with($uri, self::SCHEME.'://')` (`FormSchemaResource.php:58-61` vs `SubmissionsDatasetResource.php:63-66`).
- `read()` preamble — byte-identical strip + missing-handle guard + `Form::find()->siteId('*')->status(null)->handle()->one()` + not-found guard (`FormSchemaResource.php:68-77` vs `:73-80`).
- `list()` loop — same skeleton incl. the **identical `$seen[]` handle-dedupe** (both gained it since round 2: `FormSchemaResource.php:35-55` vs `:38-60`); only the per-resource `name`/`title`/`description` differ.
- json-encode-into-`contents` tail — identical (`FormSchemaResource.php:79-85`).

**Proposed change:** `abstract class AbstractFormResource implements ResourceProviderInterface` providing `handles()`, a `protected resolveForm(string $uri): Form|array` (strip+guards+lookup), the `list()` loop against an abstract `describe(Form): array`, and the `contents()` tail. Each provider declares only its scheme/scope/MIME + payload shaping. The differing scopes (`forms:manage` vs `submissions:read`) and payloads stay overridden — that is policy, not plumbing.

**Why MEDIUM:** bodies are identical so the merge is safe, but it introduces a new abstract base touching both interface implementers. Strong candidate the moment a 3rd resource scheme is added. Verify: both resources still list/read identically; `composer test`.

---

### Finding R3-7 — `SubmissionCsv::fromSubmissions()` and `toRows()` duplicate the field-column-building loop — **HIGH** *(new, single-file, mechanical)*

**Sites (byte-identical loop):**
- `src/helpers/SubmissionCsv.php:24-31` (`fromSubmissions`)
- `src/helpers/SubmissionCsv.php:77-84` (`toRows`)
```php
$fieldCols = [];
foreach ($submissions as $submission) {
    foreach (($submission->data ?? []) as $key => $entry) {
        if (!isset($fieldCols[$key])) {
            $fieldCols[$key] = is_array($entry) ? (string) ($entry['label'] ?? $key) : (string) $key;
        }
    }
}
```
The two public methods are near-duplicate overall (CSV-stream vs assoc-rows), and the value-extraction line is also identical at `:52` / `:99` (`is_array($entry) ? ($entry['value'] ?? '') : $entry`).

**Proposed change:** extract `private static function fieldColumns(array $submissions): array` (the union-of-columns loop) and call it from both methods. The per-row value-extraction line can use the shared extractor from R3-8 (with `''` default).

**Confidence: HIGH** — byte-identical, single file, two consumers, obvious home. **Risk: low** — pure local lift. Verify: exported CSV + element-exporter rows byte-identical for a multi-form result set (`composer test` covers the exporter).

---

### Finding R3-8 — `field_<id> => {label,type,value}` value-extraction repeated across 6 sites — **MEDIUM** *(new)*

**Sites (same shape `is_array($entry) ? ($entry['value'] ?? <default>) : $entry`):**
- `src/services/NotificationsService.php:199` (default `null`)
- `src/services/PaymentsService.php:207` and `:227` (default `null`)
- `src/helpers/SubmissionCsv.php:52` and `:99` (default `''`)
- `src/integrations/support/SubmissionValues.php:33` (default `null`)

This line encodes the submission-data storage shape (`{label, type, value}` vs scalar). It is the kind of structural knowledge that should have one home — if the stored shape ever changes, all 6 must change together.

**Proposed change:** add `SubmissionValues::value(mixed $entry, mixed $default = null): mixed` (returns `is_array($entry) ? ($entry['value'] ?? $default) : $entry`). `SubmissionValues` is the right home — it already owns reading this shape (`byHandle`, `labelledLines`). Replace the 6 inline copies; CSV passes `''` as the default.

**Note (related, NOT a separate issue):** there are now three `valuesByHandle`-style flatteners — `NotificationsService::valuesByHandle()`, `PaymentsService::valuesByHandle()`, `SubmissionValues::byHandle()`. They share the value-extraction line (fixed by this finding) but their surrounding flatten logic legitimately differs (field source: `FormStructureService::getFieldSet` vs `FormModel::getFields`; handle-resolution + fallback differ per caller). Unifying the whole method would over-generalize — **leave the methods separate**; only the value-extraction line is the shared invariant.

**Confidence: MEDIUM** — the pattern is genuinely repeated and structural, but the differing `''`/`null` default and the cross-layer spread (services + helpers + integrations) mean it's a deliberate small helper, not a blind sweep. **Risk: low.** Verify: each call site produces identical values (the `?? ''` vs `?? null` distinction is preserved via the `$default` arg); `composer test`.

---

## Considered & rejected (leave as-is / premature abstraction)

- **`rowToModel` in IntegrationsService vs NotificationsService**, and **`getSettings()` 1-line delegators** in CaptchaService/EmailService, and **JSON-decode-guard pattern** in FieldQueryHelper/NotificationsService — flagged by a sub-agent. Rejected: `rowToModel` hydrates different model types (domain-specific single-source-of-truth, correctly per-service); `getSettings()` is a trivial 1-line accessor where explicit is fine; the JSON-decode guards apply to different domains (field config vs notification conditionals). None is maintenance-burden duplication.
- **Unifying the three `valuesByHandle`/`byHandle` flatteners** — see the note under R3-8: surrounding logic legitimately differs; only the value-extraction line is shared.
- **`actionDelete()` / `actionToggle()` "CRUD pattern" across IntegrationsController + NotificationsController** — a sub-agent flagged these. Rejected: they share only the 2-line `requirePostRequest()/requireAcceptsJson()` preamble; the bodies genuinely differ (Integrations flips `enabled` in-controller and saves; Notifications delegates to a service `toggle()` returning `?bool`; delete paths call different services with different param names). This is the idiomatic Craft controller shape, not maintenance-burden duplication. A shared "JSON CRUD mixin" would add callback indirection for marginal gain.
- **`$siteId = Craft::$app->getSites()->getCurrentSite()->id;` (6× in SubmissionsController)** — a trivial idiomatic one-liner accessor; extracting it into a helper saves nothing and hurts readability. The `Submission::find()->siteId(...)->id(...)->one()` query is not actually repeated enough (≈1 site) to warrant extraction.
- **The 3 registries** (`FieldTypeRegistry`, `IntegrationTypeRegistry`, `CaptchaProviderRegistry`) — short bodies, differing key derivation, two add event-dispatch the third doesn't. A generic `Registry<T>` base adds type-parameter ceremony for ~15 lines. Leave separate. *(agrees with prior passes)*
- **GQL object types** — already share `SimpleFormObjectType` (registration single-sourced); per-type field-definition arrays are intrinsically different, not duplication. Confirmed factored. No change.
- **`McpServer::result()/error()` envelope; tool-vs-resource lookup loops; scope-denied logging** — confirmed single-sourced / divergent-by-design per round 2. No change.
- **`GqlQueries::resolveForm` (`FormQueries.php:65-81`)** — has the same id/handle *branch shape* as R3-2 but a different return contract (GQL array vs element/error payload), a resolved `siteId` (not `'*'`), no `->unique()`, `null` not `isError`. Coincidental cross-layer similarity, NOT a duplicate. Do not merge.
- **`httpClient()` config in `RecaptchaProvider`/captcha** — different needs (no SSRF-hardened client); leave standalone. *(agrees with prior)*
- **Per-integration email-field / message-template settings HTML** — UI boilerplate; instructions are context-specific; rarely changes. Below the abstraction threshold.

---

## Prioritized list

| # | Finding | Confidence | Risk | Mechanical? |
|---|---|---|---|---|
| R3-7 | `SubmissionCsv` field-column loop → private helper | HIGH | low | yes (single file) |
| R3-5 | Integration HTTP plumbing → `ApiConnector::request()` + chat/webhook `use ApiConnector` | HIGH (value) | low-med | partly (~8 files) |
| R3-1 | `getFormOrFail()` → `SimpleFormControllerTrait` | MEDIUM | low | yes |
| R3-6 | URL-validation rule → `SafeUrl::settingUrlRule()` | MEDIUM | low | mostly |
| R3-8 | submission-data value-extraction → `SubmissionValues::value()` (6 sites) | MEDIUM | low | mostly |
| R3-2 | id-or-handle resolver → `FormPresenter::resolveByIdOrHandle()` (4 sites) | MEDIUM | low | mostly |
| R3-3 | submission preamble → `SubmissionQueryBuilder::buildWithForm()/fieldMatch()` (6 sites) | MEDIUM | low | mostly |
| R3-4 | resource providers → `AbstractFormResource` base | MEDIUM | low | mostly |

**Suggested order:** R3-7 + R3-1 first (cheapest, purely mechanical single-target lifts). Then R3-5 (biggest win, consolidates security-relevant code). Then R3-6 + R3-8. Then R3-2/R3-3/R3-4 as a coordinated MCP/resource layer pass. None of these is "purely mechanical + zero-design"; all are safe and behaviour-preserving with `composer test` + per-area smoke as the gate.
