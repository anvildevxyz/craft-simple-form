# 06 — Exception Handling / Defensive Code Audit

Plugin: Simple Form (Craft CMS 5)
Scope: `src/` (223 PHP files), 47 `try` / 44 `catch` blocks reviewed.
Date: 2026-06-21
Mode: research-only (no source modified).

> **Risk note up front:** This dimension is the most dangerous to "clean up."
> A swallowed exception that *looks* pointless is often the one keeping a form
> render, a mail send, or an integration dispatch from taking down the whole
> request. Every recommendation below is conservative; the High bucket is
> intentionally tiny and limited to cases that are both clearly low-value and
> clearly safe.

---

## 1. Critical assessment of error-handling posture

This codebase has an **unusually disciplined** error-handling posture. Across all
44 catch blocks:

- **No empty catch bodies.** A regex scan for `catch (...) {}` found zero hits.
- **No catch-and-rethrow-the-same-thing noise.** The only `throw $e;` blocks
  (`FieldSyncService:493`, `FormCloneService:265`, `FormPortabilityService:142`,
  `FieldsController:255`) are all the correct **transaction rollback → rethrow**
  pattern: they roll back the DB transaction and re-propagate so the caller
  still sees the failure. That is exactly right, not noise.
- **Every swallow is logged** with `Craft::warning` / `Craft::error` and a
  category of `simple-form`, and almost every one carries a comment explaining
  *why* the failure is non-fatal.
- **Secret hygiene is built into the handlers** — `AbstractGoogleIntegration`,
  `IntegrationsService::scrubSecrets`, and the Guzzle catches deliberately log a
  generic message and throw a credential-free exception rather than leaking the
  request body. This is defensive code doing real work.
- **`catch (\Throwable)` is used judiciously**, almost always around genuine IO
  (HTTP, mail, PDF, DB, filesystem, Twig sandbox render) or Craft/Yii APIs that
  are documented to throw. The few broad catches over non-IO code are best-effort
  side-effects (audit log, token `lastUsed` touch) where a failure must not
  break the primary operation.

Net: there is **no error-hiding rot** here worth an aggressive sweep. The
findings below are minor polish, not bug-fixes. I found **0 High**, **0 Medium**,
and a handful of **Low** observations.

---

## 2. Findings

### High (clearly pointless or error-hiding AND safe to change)

**None.** No catch block in `src/` silently swallows an error that should
propagate, and none masks a programming error in a way that is safe to remove.

### Medium

**None.**

### Low

#### L1 — `FieldModel::normalizeValue()` swallows `\Throwable` and passes value through
`src/models/FieldModel.php:175`

```php
} catch (\Throwable $e) {
    Craft::warning(sprintf('Field normalize error: %s', $e->getMessage()), 'simple-form');
    return $value;
}
```

- **Pattern:** broad catch + return-the-input fallback.
- **Assessment:** *Keep.* It runs over submitted form values both at write time
  (`SubmissionService:562`) and on read-back, i.e. external/derived input, and a
  failed coercion (e.g. a non-numeric value reaching a rating field) genuinely
  shouldn't 500 a submission. Logged. The one nit: `catch (\Throwable)` is
  broader than the realistic failure surface (a type error in a field-type
  `normalizeValue`), but narrowing it risks letting an unanticipated throw
  escape into the submission path. **Recommendation: leave as-is.**
- **Risk if changed:** Medium (touches the submission write/read path).

#### L2 — `EmailService::renderBodyFor()` falls back to the default template on render failure
`src/services/EmailService.php:253`

```php
} catch (\Throwable $e) {
    Craft::warning('Failed to render notification body, using default: ' . $e->getMessage(), 'simple-form');
}
// ...falls through to renderDefaultBody()
```

- **Pattern:** catch-and-fall-back-to-default (silent substitution).
- **Assessment:** *Keep.* The body is admin-authored Twig rendered in a forced
  sandbox; a sandbox rejection or template typo should still get the
  notification out with a sane default rather than dropping the mail. This is a
  deliberate, logged degradation, not a hidden bug. **Recommendation: leave
  as-is.** (Optional: the warning could include the form handle/id for easier
  triage — copy-only, not behavioural.)
- **Risk if changed:** Medium (notification delivery path).

#### L3 — `FormRenderService::_assets()` falls back to inline assets on bundle-register failure
`src/services/FormRenderService.php:661`

```php
} catch (\Throwable $e) {
    Craft::warning('Falling back to inline form assets: ' . $e->getMessage(), 'simple-form');
}
```

- **Pattern:** catch-and-fall-back.
- **Assessment:** *Keep.* Registering an asset bundle requires a publishable web
  View; when there isn't one (e.g. rendering a form outside a normal web request)
  the inline path is the correct escape hatch. Logged and documented.
- **Risk if changed:** Low-Medium.

#### L4 — Best-effort side-effect catches: `AuditService::log()` and `TokenManager::touch()`
`src/services/AuditService.php:37`, `src/mcp/TokenManager.php:165`

- **Pattern:** broad `catch (\Throwable)` around a non-critical write, warn,
  continue.
- **Assessment:** *Keep.* Both are explicitly "best-effort" bookkeeping (audit
  row insert; MCP token `lastUsed` stamp). A failure here must not block the real
  operation. Logged. These are textbook-correct swallows.
- **Risk if changed:** Low, but no upside to changing.

#### L5 — `McpServer` in-band tool/resource error reporting
`src/mcp/McpServer.php:263`, `:363`

- **Pattern:** broad `catch (\Throwable)` converting an exception into an
  `isError:true` / JSON-RPC error response.
- **Assessment:** *Keep.* This is a protocol boundary (MCP/JSON-RPC) where the
  spec requires errors be reported in-band and internals must not leak to the
  client. The message is logged server-side. Correct by design.
- **Risk if changed:** Low, but changing would be a protocol regression.

#### L6 — Note (not a defect): `decodeConfigParam` bound + json-decode fallback
`src/controllers/FieldsController.php` (`decodeConfigParam`, ~line 195)

- A posted `config` JSON over 64 KB, or invalid JSON, decodes to `[]`.
- **Assessment:** *Keep.* This is **legitimate validation of unsanitized CP
  input** (CWE-20), exactly the kind the brief says to preserve. Listed only so
  a future reader doesn't mistake the `?? '{}'` / `is_array` guard for a
  bug-papering fallback.

---

## 3. Handlers reviewed that are CORRECT and must stay

The following are explicitly endorsed — do **not** remove or narrow them:

| Location | Why it's correct |
|---|---|
| `FieldSyncService:492`, `FormCloneService:264`, `FormPortabilityService:141`, `FieldsController:252` | DB transaction **rollback → rethrow**. The error propagates; the rollback prevents partial writes. |
| `FieldsController:90/156/182/262` | Transaction rollback → `asJsonError`. Craft DB writes documented to throw; user gets a clean error. |
| `FormsController:213/247/278/333` | Wrap form save / clone / stencil / import (element save, project-config, JSON import of uploaded file). Logged + user-facing session error + redirect. External input + Craft APIs that throw. |
| `RecaptchaProvider:63`, `AbstractSiteverifyProvider:63` | `GuzzleException` on captcha verification → fail-closed (`return false`). Correct: a captcha service outage must not silently pass spam. Logged. |
| `AkismetService:69` | HTTP failure → `false` (treat as not-spam), logged. Fail-open is the right call for a spam check so an Akismet outage doesn't block legit submissions. |
| `AbstractGoogleIntegration:244`, `GoogleSheetsIntegration` (all catches), `ApiConnector:111` | Third-party HTTP. Credential-free generic messages, SSRF guard before dispatch, token-refresh-and-retry on 401. Exemplary integration error handling. |
| `IntegrationsService:253` | `send()` failure → logged dispatch row + `IntegrationResult::failure`, with secret scrubbing. Connectors are third-party IO. |
| `IntegrationsService:511` | Secret decryption failure → blank the secret + warn. Correct: a corrupt/rotated key shouldn't crash the dispatch loop. |
| `EmailService:121` | `Asset::getContents()` (filesystem read) failure → skip that attachment, continue. Right granularity (per-attachment). |
| `EmailService:217` | `Mailer::send()` failure → `false` + warn. Mail IO genuinely fails. |
| `PdfService:69` | PDF render (third-party engine) failure → `null` so notification falls back to no attachment. Documented, logged. |
| `HtmlFieldType:72`, `CraftElementIntegration:285`, `FormRenderService:413` | Twig sandbox / template render over **author-supplied** templates. A sandbox rejection must not leak raw Twig or 500 the form. Logged, returns safe empty/null. |
| `CalculationFieldType:77/138`, `FieldSyncService:133` | `FormulaException` on user-authored formula. Compute/references fail soft (0.0 / `[]`); save-time validation reports the real error to the editor. Narrow, typed catch. |
| `FileFieldType:134` | `finfo`/MIME detection failure on an **uploaded file** → treat as non-executable, allowlist + Craft asset validation still enforce. Documented security rationale. |
| `SubmissionCsv:294` | Parsing a stored `consentedAt` datetime string → blank on `\Exception`. Narrow catch over data parsing. |
| `console/FormsController:104` | CLI import of a file → stderr + non-zero exit code. Correct console UX. |

---

## Summary

Reviewed all 47 try / 44 catch blocks plus the `?? default` / null-guard
fallbacks. **0 High, 0 Medium, 6 Low** — and every Low is a *keep*, not a fix.
There are no empty catches, no pure rethrows-of-the-same-exception (the four
`throw $e;` sites are correct rollback-then-rethrow), and every swallow is
logged with a rationale. Broad `catch (\Throwable)` is confined to genuine IO,
third-party APIs, sandboxed user-Twig, or explicitly best-effort side-effects
(audit log, MCP token touch). The error-handling posture is already clean;
nothing here should be removed. This dimension is risk-prone — the apparent
"swallows" (captcha fail-closed, Akismet fail-open, PDF/mail/attachment
degradation, notification-body fallback) are deliberate availability decisions,
not error-hiding. Recommend **no changes**.
