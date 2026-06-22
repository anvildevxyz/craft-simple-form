# Delta 04 — Circular Dependencies & Tangled Coupling

Scope: PHP source changed since `c5b8fe7` (delta list per brief; WIP files
read-only for context, never patched). Builds on `docs/cleanup/04-circular-deps.md`.
Dependency graph mapped statically via `use fabianhaef\...` imports +
`Plugin::getInstance()->getXxx()` locator calls + `new` instantiations. madge
(JS) not applicable to PHP and not used.

## 1. Critical assessment

**The one real cycle from the prior pass is GONE — the delta fixed it.**

The prior HIGH finding was the `EmailService ↔ PdfService` 2-cycle: PdfService
borrowed `renderDefaultBody()` / `renderSandboxedTemplate()` from EmailService,
closing the loop. The delta implemented the prior report's *exact* recommended
break:

- New service `src/services/SubmissionBodyRenderer.php` was extracted. It owns
  the default submission HTML table (`render()`) plus the two private formatters
  (`formatFieldValue` / `formatFileValue`). Its only dependency is `Asset` +
  `FileFieldType::getType()` — zero `getInstance()->get*` calls; it is a pure
  DAG leaf.
- `PdfService.php:186` now calls `getSubmissionBodyRenderer()->render(...)` (was
  `getEmailService()->renderDefaultBody(...)`), and `PdfService.php:178` calls
  `getSafeRender()->renderTemplate(...)` directly (was the EmailService wrapper).
  **PdfService no longer references EmailService at all** — verified:
  `getInstance()->get` in PdfService = `getSettings`, `getSafeRender`,
  `getSubmissionBodyRenderer` only. All downward.
- `EmailService.php:261` likewise calls `getSubmissionBodyRenderer()->render(...)`
  for its default-body fallback.

Remaining `EmailService → PdfService` edge (`EmailService.php:70-71`,
`getPdf()->render()/filename()` for the optional PDF attachment) is now
**one-directional and correct** — email owns "should I attach a PDF?". No
reciprocal edge exists. Cycle broken.

The rest of the delta dependency graph is a clean DAG:

- **Service → service edges all point downward** toward leaf services
  (`Settings`, `Audit`, `AssetUpload`, `SafeRender`, `FieldTypeRegistry`,
  `IntegrationTypeRegistry`, `CaptchaProviderRegistry`, `SubmissionEditTokens`,
  `SubmissionBodyRenderer`), none of which make any outbound service call.
  Verified each: those eight have zero `getInstance()->get*` edges → DAG sinks.
- The hot post-submit chain `SubmissionService → Payments → EmailService → Pdf →
  SubmissionBodyRenderer` is a straight line; EmailService/Pdf never call
  Payments or SubmissionService back. `SubmissionService` is called *only* by
  controllers, gql mutations, element actions, and `FormRenderService` — all
  upstream callers it never invokes in return (no `getFormRenderService()`
  anywhere in SubmissionService).
- **Models → services are strictly downward.** `FieldModel` → `FieldTypeRegistry`
  (`getFieldType`, lines 68/122/148/160); `NotificationModel` →
  `getPdf()->isAvailable()` (line 70). FieldTypeRegistry imports concrete field
  types but **never** imports `FieldModel` (zero `use ...models`, zero
  `getInstance()->get`); PdfService never imports either model. No loop.
- **Helpers → services are strictly downward.** Only `SubmissionCsv`
  (`SubmissionCsv.php:268` → `getFieldTypeRegistry()`) reaches a service; the
  registry is acyclic. The other delta helpers (ConditionalEvaluator, ConsentText,
  FieldQueryHelper, FormContentHelper, FormRows, FormSteps, Formula,
  HiddenValueResolver) make no service calls at all.
- `FileFieldType` (imported by both EmailService and SubmissionBodyRenderer for
  `getType()`) makes no service calls — does not close any back-edge.

Idiomatic-Craft non-issues unchanged from the prior pass (Plugin singleton
service-locator, `Form ↔ Submission` element-relation querying, registry
fan-out) — not cycles; no action.

## 2. High-confidence patch list

**None.** The only genuine cycle the prior audit identified was already resolved
by the delta (SubmissionBodyRenderer extraction). No new cycle was introduced by
any changed service, helper, model, element, or field. Every new/changed edge is
downward in the DAG.

The prior pass's LOW notes (`SubmissionService` hub size; `FieldModel →
FieldTypeRegistry`) remain LOW and acyclic — out of scope for a delta cycle-break
and not worth touching.

## 3. Verdict

**0 — no genuine cycles.** The pre-existing EmailService↔PdfService cycle is
fixed in the delta; nothing new to patch.
