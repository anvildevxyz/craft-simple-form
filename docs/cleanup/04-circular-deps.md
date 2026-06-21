# 04 — Circular Dependencies & Tangled Coupling

Scope: `src/` (223 PHP files), PSR-4 namespace `fabianhaef\simpleform\`. Research-only;
no source modified. JS (built `dist/` artifacts) ignored per brief.

## 1. Critical assessment of the dependency structure

The plugin uses Craft's standard **service-locator** architecture: a single `Plugin`
singleton registers ~27 components in `setComponents()`, and every layer reaches its
collaborators through `Plugin::getInstance()->getXxx()`. This means the *static* `use`
import graph hides almost all runtime coupling — the real dependency graph lives in the
`getInstance()->...` calls, which I traced exhaustively.

Overall the structure is **healthy and well-layered**:

- **Services → services** edges form a near-perfect DAG. I mapped all 17 service-to-service
  caller/callee relationships (Section 3) and found exactly **one true 2-cycle**.
- **Models / elements / helpers / fields → services** are strictly **downward** (a model
  reaching a service is the expected Craft pattern; no service reaches back into a specific
  model to create a cycle). FieldModel → FieldTypeRegistry → FieldType is one-directional;
  no field type imports FieldModel back.
- **Element ↔ element** cross-references (`Form` ↔ `Submission`) exist but are idiomatic
  Craft element-relation querying, not a coupling smell.
- No model instantiates a controller; no helper reaches "up" into a high-level orchestration
  service in a way that closes a loop. The one helper that touches a service
  (`SubmissionCsv` → `FieldTypeRegistry`) is downward and acyclic.

So: **one real cycle, low-risk to break, plus one god-object to flag.** The rest is clean.

## 2. Findings

### HIGH — `EmailService` ↔ `PdfService` mutual dependency (real cycle, safe break)

**Participating classes**
- `src/services/EmailService.php`
- `src/services/PdfService.php`

**Edges**
- `EmailService` → `PdfService`:
  - `EmailService.php:69` — `Plugin::getInstance()->getPdf()->render(...)`
  - `EmailService.php:73` — `Plugin::getInstance()->getPdf()->filename(...)`
  (EmailService attaches a generated PDF to the notification email.)
- `PdfService` → `EmailService`:
  - `PdfService.php:178` — `Plugin::getInstance()->getEmailService()->renderSandboxedTemplate(self::TEMPLATE, $variables)`
  - `PdfService.php:195` — `Plugin::getInstance()->getEmailService()->renderDefaultBody($form, $submission, $data)`
  (PdfService renders its HTML body using two helpers that physically live in EmailService.)

**Why it's a cycle.** EmailService needs PdfService to build attachments; PdfService needs
EmailService only to borrow two **rendering** helpers. Because both directions are exercised
in normal flows, this is a genuine A→B→A runtime cycle, not a lazy-init artifact.

**Why it's low-risk to break.** The two methods PdfService borrows are *not* email-specific:

- `EmailService::renderSandboxedTemplate()` (`EmailService.php:295`) is a 4-line pass-through
  to `Plugin::getInstance()->getSafeRender()->renderTemplate(...)` — the `SafeRenderService`
  seam that *both* services already depend on.
- `EmailService::renderDefaultBody()` (`EmailService.php:310`) is a generic
  "submission data → titled HTML table" builder (see lines 310–349); nothing about it is
  email-bound — its own docblock notes it's "reused by ... the PDF default layout (#143)".

These helpers were simply *parked* in EmailService. The dependency only points the wrong way.

**Minimal break (preferred).** Move `renderDefaultBody()` (plus its private
`formatFieldValue` / `formatFileValue` siblings, used at `EmailService.php:336–338`) into a
shared, low-level renderer — either `SafeRenderService` or a small new
`SubmissionBodyRenderer`. Then:
- PdfService calls `getSafeRender()->renderTemplate(...)` directly instead of the EmailService
  wrapper at line 178 (drops that edge entirely).
- PdfService calls the new shared body renderer at line 195 instead of
  `getEmailService()->renderDefaultBody(...)`.
- EmailService keeps calling the relocated helpers; its `renderSandboxedTemplate` wrapper can
  stay or be inlined.

Result: both services depend *downward* on `SafeRenderService` / the new renderer, and the
EmailService ← PdfService edge disappears. EmailService → PdfService stays (correct: email
owns "should I attach a PDF?"). **Cycle broken with pure code-motion — no interface
extraction, no event indirection, no behavior change.**

**Alternative (even cheaper, if motion is undesirable).** Make `renderDefaultBody` /
`renderSandboxedTemplate` `static` (they don't touch `$this` beyond the two private
formatters) and call them statically from PdfService. Removes the *service-locator* edge but
keeps a static class-level dependency, so it's a weaker fix — prefer the move.

**RISK: Low.** Pure relocation of two stateless helpers; both call sites are known and
narrow. Re-run the EmailService / PdfService unit + smoke coverage after.

### LOW — `SubmissionService` is a hub / near-god-object

`src/services/SubmissionService.php` is **1,120 lines** and fans out to the widest set of
collaborators of any class: `getAkismetService`, `getAssetUploadService`, `getAudit`,
`getCaptchaService`, `getDenylistService`, `getEmailService`, `getFieldTypeRegistry`,
`getPayments`, `getSettings`, `getSubmissionEditTokens`, plus `trigger(...)` events
(`SubmissionService.php` locator calls). This is the central "process a submission"
orchestrator, so high fan-**out** is somewhat expected — and crucially it is **acyclic**:
none of those 10 services call `getSubmissionService()` back (verified against EmailService,
PaymentsService, AkismetService, CaptchaService, DenylistService). So it's a coupling *weight*
concern, not a cycle.

**Suggested (optional) break.** If it grows further, peel the spam-gate chain
(Akismet + Captcha + Denylist) into a `SpamCheckService` and the post-submit side-effects
(email + payments + audit) into a `SubmissionPipeline`, leaving `SubmissionService` as a thin
coordinator. **RISK: Medium** (touches the hottest path) — not worth doing unless the file
keeps accreting.

### LOW — `FieldModel` → `FieldTypeRegistry` (downward, acyclic — noted only for completeness)

`FieldModel.php:75,124,157,173` call `getFieldTypeRegistry()->getFieldType(...)`. The registry
imports the concrete field types but **never** imports or constructs `FieldModel`, and no
field type imports `FieldModel`. So `FieldModel → FieldTypeRegistry → FieldType` is a clean
chain, not a loop. No action.

## 3. Coupling that looks bad but is idiomatic Craft (no action)

- **Everything depending on the `Plugin` singleton** (`Plugin::getInstance()->...`). This is
  Craft's prescribed service-locator pattern, not a circular dependency. Treating the Plugin
  as a hub is normal; it is not a god object in the coupling sense (it just registers
  components and wires events).
- **`Form` ↔ `Submission` element cross-references** — `Form` counts/queries `Submission`
  (`Form.php:259–263`), `Submission` resolves its owning `Form` (`Submission.php:101`,
  `:123`, `:235`). This is standard Craft element-relation querying via element queries, not
  a class-design cycle.
- **`Form` element → `FormStructureService`** (`Form.php:329,342,592,604`) and
  **model/helper/field → service** edges generally. These are downward calls into the service
  layer; `FormStructureService` does **not** import the `Form` element back (verified), so no
  cycle is closed.
- **`FieldTypeRegistry` importing 30+ concrete field types** via `use`. High fan-out by
  design (a registry *is* a fan-out point); the field types don't import the registry back.
- **`FormCloneService` / `FormPortabilityService`** each fan out to audit/integrations/
  notifications/fieldSync/version — all downward, none reciprocated. Clean.

---

### Appendix — full service→service edge map (locator-resolved)

```
AkismetService        -> Settings
CaptchaService        -> Settings
DenylistService       -> Settings
DraftService          -> Settings
EmailService          -> Notifications, Pdf, SafeRender, Settings        (Pdf = cycle leg)
FieldSyncService      -> FieldTypeRegistry, FormStructure
FormCloneService      -> Audit, FormStructure, Integrations, Notifications
FormPortabilityService-> Audit, FieldSync, Integrations, Notifications, Version
FormRenderService     -> CaptchaService, Drafts, FieldTypeRegistry, FormStructure, Settings, SubmissionService
FormStructureService  -> Settings
IntegrationsService   -> Audit, IntegrationTypeRegistry, Settings
NotificationsService  -> Audit, FormStructure
PaymentsService       -> EmailService, FormStructure, Integrations
PdfService            -> EmailService, Settings                          (EmailService = cycle leg)
ReportsService        -> FormStructure
RetentionService      -> AssetUploadService, Audit, Drafts, Settings
SubmissionService     -> Akismet, AssetUpload, Audit, Captcha, Denylist, Email,
                         FieldTypeRegistry, Payments, Settings, SubmissionEditTokens
```

Only reciprocated pair: **EmailService ↔ PdfService** (the HIGH finding).
