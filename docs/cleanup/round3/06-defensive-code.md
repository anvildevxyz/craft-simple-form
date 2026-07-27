# 06 — Defensive Code / Exception Handling (ROUND 3, full re-audit)

Plugin: Simple Form (Craft CMS 5)
Scope: full `src/` (210 PHP files); 52 `catch`, 81 `try` keywords.
Baseline: HEAD `1c48368`. Covers the ~41-commit delta since the prior delta pass
(`c5b8fe7`) — the large feature-PR merge (#123–#196, payments, code-defined
forms) plus the migration collapse.
Date: 2026-06-24
Mode: research-only (no source modified).

Builds on:
- `docs/cleanup/06-defensive-code.md` (full pass, 2026-06-21: 0 High / 0 Med / 6 Low — all KEEP)
- `docs/cleanup/delta/06-defensive-code.md` (delta, 2026-06-22: 0 patches)

Soundly-rejected items from those passes are **not** re-flagged.

---

## (a) Critical assessment of error-handling discipline

The error-handling posture remains **unusually disciplined**, and the large merge
since the last pass did not erode it. Concretely, across all 52 catch sites:

- **Exactly one empty-body catch in the whole tree** (`SubmissionCsv.php:371`),
  and it is a *narrow* `catch (\Exception)` over `new \DateTimeImmutable($stored)`
  with the fallback variable pre-initialised — i.e. a deliberate, correct
  degradation, not an error swallow (detail in the table).
- **Every other swallow logs** via `Craft::warning` / `Craft::error` under the
  `simple-form` category, and almost all carry a "why this is non-fatal" comment.
- **The four `throw $e;` sites** (`FieldSyncService:503`, `FormCloneService`,
  `FormPortabilityService`, `FieldsController`) are all the correct
  **transaction rollback → rethrow** pattern. The error still propagates.
- **`catch (\Throwable)` is confined** to genuine IO (HTTP, mail, PDF, filesystem,
  DB transactions), third-party plugin surfaces (Commerce, Google), sandboxed
  author-Twig, or explicitly best-effort side-effects (audit row, MCP token
  touch, post-`up` form apply). The narrow/typed catches are used where the
  throw surface is bounded (`FormulaException`, `PaymentException`,
  `GuzzleException`, `GoogleAuthException`, `\Exception` for date parsing).
- **Secret hygiene is built into the handlers** — `IntegrationsService::scrubSecrets`,
  the encrypt/decrypt fail-soft (`:520`), and credential-free Guzzle messages.
- **The new payments + code-defined-forms code follows the same rules.** The new
  `catch` sites (`PaymentsService:148/160/306`, `Plugin.php:345`) are payment-gateway
  IO and a best-effort post-`up` apply: fail-closed/fail-soft, logged, narrow where
  the surface allows.

**Defensive guards:** two independent read-only sweeps over the changed services,
helpers, controllers, fields, and GraphQL found **zero** guards that are redundant
against a statically-proven non-null/typed value. Every `isset` / `is_array` /
`is_string` / `?? default` / `=== false` guard sits over genuinely untyped or
external data: `json_decode` results (field `config`, `optionLabels`,
`conditional`), `mixed` GraphQL args, request/body params, `parse_url` output,
`Cache::get` results, `Query::scalar` (false-or-null), `dns_get_record`,
`base64_decode`, submission `$data`, and nullable DB columns. Many are also
load-bearing for the PHPStan L7 gate (e.g. `FieldQueryHelper:78` guards the
`json_decode` branch before `$config['required'] = …`).

**One candidate REMOVE surfaced by a sub-agent (`FieldQueryHelper.php:78`) does
not hold up on inspection** — see the table. It guards a real `mixed` from
`json_decode`, so it is a KEEP.

Net: **0 High, 0 Medium, 0 Low fixes.** No error-hiding rot. This dimension is
risk-prone and the apparent "swallows" (captcha fail-closed, Akismet fail-open,
PDF/mail/attachment degradation, notification-body fallback, inline-asset escape
hatch, payment fail-closed) are deliberate availability decisions. **Recommend no
changes.**

---

## (b) Findings table

### New / changed catch sites since `c5b8fe7` (the focus of this pass)

| File:line | Construct | Class | Why | Recommended change | Conf | Risk |
|---|---|---|---|---|---|---|
| `services/PaymentsService.php:148` | `catch (\Throwable)` around Commerce gateway/donation/order build → fail-closed result + `Craft::error` | KEEP | Unbounded third-party Commerce surface (missing gateway, misconfig, DB). Fails closed, generic credential-free message, logged. | none | — |
| `services/PaymentsService.php:160` | `catch (\craft\commerce\errors\PaymentException)` around `processPayment` → fail-closed + `Craft::warning` | KEEP | Narrow, *typed* catch on the actual charge; declines fail closed. Textbook payment handling. | none | — |
| `services/PaymentsService.php:306` | `catch (\Throwable)` around `gateway()->getPaymentFormHtml()` → `null` + warn | KEEP | Renders 3rd-party gateway HTML; field degrades gracefully to no payment UI when gateway misbehaves. Logged. | none | — |
| `Plugin.php:345` | `catch (\Throwable)` around post-`up` `forms/apply` → `Craft::error` | KEEP | Best-effort code-defined-form deploy after `craft up`. A form-apply failure must not break `craft up`; logged at error. Off by default. | none | Low |
| `services/EmailService.php:146` | `catch (\Throwable)` around `Asset::getContents()` (per-attachment) → warn + `continue` | KEEP | Per-attachment filesystem read of an uploaded file; right granularity — one unreadable upload skips itself, mail still sends. Logged. | none | — |
| `helpers/SubmissionCsv.php:371` | `catch (\Exception) {}` (empty body) around `new \DateTimeImmutable($record['consentedAt'])` | KEEP | Only empty-body catch in tree. Narrow catch over parsing a *stored* datetime string; `$at` pre-initialised to `''`, so cell degrades to `Yes` (no timestamp). Correct, intentional. | Cosmetic only: an inline comment would make the no-op intent explicit. No behavioral change. | Low |

### Previously-reviewed catch sites re-confirmed unchanged (KEEP)

| File:line | Construct | Why still KEEP |
|---|---|---|
| `services/IntegrationsService.php:267` | `catch (\Throwable)` on `$type->send()` → logged dispatch row + `IntegrationResult::failure` with `scrubSecrets` | Third-party connector IO; secrets scrubbed; failure surfaced as a result, not hidden. |
| `services/IntegrationsService.php:520` | `catch (\Throwable)` on `decryptByKey` → blank secret + warn | Corrupt/rotated key must not crash the dispatch loop; logged. |
| `integrations/support/ApiConnector.php:113` | `catch (\Throwable)` around Guzzle request (after SSRF guard + DNS-pin) → `IntegrationResult::failure` | Network IO; SSRF-guarded before dispatch; failure returned as a result. |
| `integrations/AbstractGoogleIntegration.php:244`, `GoogleSheetsIntegration.php:106/124/168/197/415` | `GuzzleException` / `GoogleAuthException` (typed) → token refresh-and-retry, generic messages | Third-party HTTP with credential-free messages and 401 retry. Exemplary. |
| `captcha/RecaptchaProvider.php:63`, `captcha/AbstractSiteverifyProvider.php:63` | `GuzzleException` → `return false` (fail-closed) | A captcha outage must not silently pass spam. Logged. |
| `services/AkismetService.php:71` | `catch (\Throwable)` → `false` (fail-open) | An Akismet outage must not block legit submissions. Logged. |
| `services/PdfService.php:70` | `catch (\Throwable)` → `null` | 3rd-party PDF engine; notification falls back to no attachment. Logged. |
| `services/EmailService.php:263` | `catch (\Exception)` on `Mailer::send()` → `false` + warn | Mail IO genuinely fails; surfaced to caller. |
| `services/EmailService.php:299` | `catch (\Throwable)` on sandboxed body render → fall back to default template | Author Twig in forced sandbox; degrade to default body rather than drop mail. Logged. |
| `services/FormRenderService.php:426` | `catch (\Throwable)` on sandboxed template render | Author-supplied template; safe empty/null on sandbox rejection. Logged. |
| `services/FormRenderService.php:682` | `catch (\Throwable)` on `registerAssetBundle` → inline-assets fallback | Documented escape hatch when no publishable web View exists. Logged. |
| `integrations/CraftElementIntegration.php:285` | `catch (\Throwable)` on `renderString` of author title template → `null` | Author Twig; must not 500 / leak raw Twig. Logged. |
| `fields/HtmlFieldType.php:72` | `catch (\Throwable)` on sandboxed render | Author Twig sandbox; safe empty fallback. Logged. |
| `fields/CalculationFieldType.php:77/138`, `services/FieldSyncService.php:153` | `catch (FormulaException)` (typed) → soft 0.0 / `[]` | User-authored formula; save-time validation reports the real error to the editor. Narrow. |
| `fields/FileFieldType.php:134` | `catch (\Throwable)` on `FileHelper::getMimeType` (uploaded file) → treat non-executable | Documented (F10): transient finfo error must not block upload; extension allowlist + Craft validation still enforce. |
| `models/FieldModel.php:162` | `catch (\Throwable)` in `normalizeValue` → return input + warn | Runs over submitted/derived field values; a failed coercion must not 500 a submission. Logged. |
| `services/AuditService.php:56`, `mcp/TokenManager.php:165` | `catch (\Throwable)` around best-effort write → warn + continue | Audit row / MCP `lastUsed` stamp must not block the real op. Logged. |
| `mcp/McpServer.php:263/363` | `catch (\Throwable)` → in-band JSON-RPC error | MCP protocol boundary requires in-band error reporting; internals not leaked, logged server-side. |
| `services/FieldSyncService.php:501`, `services/FormCloneService.php:264`, `services/FormPortabilityService.php:165`, `controllers/FieldsController.php:251` | `catch (\Throwable)` → `rollBack()` + `throw $e` | DB transaction rollback → rethrow. Error propagates; partial writes prevented. |
| `controllers/FieldsController.php:90/156/182/261` | `catch (\Exception)` → rollback + `asJsonError` | Craft DB writes documented to throw; user gets a clean JSON error. |
| `controllers/FormsController.php:211/245/276/331` | `catch (\Throwable)` → warn + session error + redirect | Element save / clone / stencil / import of uploaded file. External input + Craft APIs that throw. |
| `console/controllers/FormsController.php:129/203` | `catch (\Throwable)` → stderr + non-zero exit | Correct console UX for import/apply failures. |

### Defensive guards (sub-agent sweeps) — all KEEP

| File:line | Guard | Source of value | Class | Why |
|---|---|---|---|---|
| `helpers/FieldQueryHelper.php:78` | `is_array($config) ? $config : []` | `json_decode($row['config'])` (line 76) → `mixed` | KEEP | Guards the decode branch (malformed/legacy JSON → string/int/null) before `$config['required'] = …`. Sub-agent's "redundant" claim is wrong: line 76's truthy branch is `json_decode`, not an array literal. Also gate-load-bearing. |
| `helpers/ConditionalEvaluator.php:62/67/90/95/100/144` | `is_array` / `empty` on `$config['conditional']`, `rules`, `rule` | decoded JSON field config | KEEP | Untyped serialized config; malformed structure must be tolerated. |
| `gql/mutations/FormMutations.php` (is_array on `$inputValues`, `$entry`) | `is_array(...) ? … : []` | `mixed` GraphQL arg | KEEP | Parameter is `mixed` by contract; client-supplied. |
| `services/NotificationsService.php:148/163/217-223` | `is_array` / `is_string` / `reset()` polymorphic | decoded JSON `conditional` column; submission values | KEEP | DB-serialized + scalar-or-array submission data. |
| `services/DenylistService.php:50/240/264` | `$ip !== null/''`, `!is_array($entry)`, value guards | `getUserIP()`, submission `$data` | KEEP | Console-context nullable IP; untrusted submission data. |
| `services/DraftService.php:58` | `$existingId !== false && !== null` | `Query::scalar()` | KEEP | `scalar()` returns false on no row, null on NULL column — both real. |
| `services/AssetUploadService.php:116` | `is_string($volumeHandle) && !== ''` | `mixed $volumeHandle` | KEEP | Untyped input before `getVolumeByHandle()`. |
| `services/FormStructureService.php:54/120` | `is_array($cached)` | `Cache::get()` | KEEP | Cache miss/corruption returns non-array. |
| `helpers/FormRows.php:82-85`, `helpers/FormSteps.php:40-42` | `is_array($config)`, `is_numeric($row/$page)` | `$field['config']` from row | KEEP | Untyped config; numeric validation before coercion. |
| `helpers/SafeUrl.php:45/74/144/168/192/202/229` | `parse_url === false`/missing keys, `is_string` on `App::parseEnv`, scheme `??`, `dns_get_record` shape | `parse_url`, env strings, DNS records | KEEP | Security-critical SSRF/open-redirect guard over genuinely untyped external data. |
| `helpers/SignaturePng.php:51/62/65/69` | `is_string`, `preg_replace ?? ''`, `base64_decode(…,true) ?: ''`, PNG magic-byte check | client-supplied data URL | KEEP | Untrusted base64 client input; security validation. |
| `helpers/ConsentText.php:85`, `helpers/HiddenValueResolver.php:96/116/130` | `?? $text`, `is_scalar`, null/empty distinctions | `preg_replace_callback`, `mixed` resolved values, nullable user attrs | KEEP | Nullable-mixed sources; scalar coercion. |
| `helpers/RateLimiter.php:27` | `$max <= 0` → never throttle | config int | KEEP | Normalises invalid limit; defensive, not error-hiding. |
| `services/CaptchaProviderRegistry.php:33/38` | `class_exists(Craft)` / `Craft::$app === null`, `$plugin !== null` | partial-bootstrap / test context | KEEP | Guards unit-test isolation where Craft isn't fully booted. |
| `controllers/*` (FieldsController:202/221, FormsController:124/462/481/539, FieldType:185-195, RepeaterFieldType:193-195) | `is_array` / `isset` on decoded JSON, body params, `getSupportedSites()` | request/body params, decoded JSON, Craft API returns | KEEP | Untrusted/untyped/polymorphic sources; several gate-load-bearing. |

---

## (c) HIGH-CONFIDENCE RECOMMENDATIONS

**None.**

Every catch in `src/` either propagates the error (rollback→rethrow, console
non-zero exit, asJsonError, in-band JSON-RPC), or makes a deliberate, *logged*
availability decision over genuine IO / third-party / sandboxed-author-Twig /
best-effort side-effects. The single empty-body catch (`SubmissionCsv.php:371`)
is a narrow, correct date-parse degradation with a pre-initialised fallback — not
an error swallow. Every defensive guard operates on genuinely untyped or external
data (decoded JSON, `mixed` args, request params, `parse_url`/`base64_decode`/DNS
output, cache/scalar returns, nullable columns); none guards a statically-proven
non-null typed value, and several are required by the PHPStan L7 gate.

The one REMOVE candidate raised during the sweep (`FieldQueryHelper.php:78`) was
checked at source and **rejected** — it guards a real `json_decode` `mixed`, so
removing it would reintroduce a `TypeError`/PHPStan failure.

No removal or simplification here can be made without risking the swallowing of a
real error path or breaking the gate. **Recommend no changes for this dimension.**
