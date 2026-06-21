# Concern #4 — Circular Dependencies (Round 3, independent pass)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2, `fabianhaef\simpleform`)
**Scope:** `src/` — 162 PHP files, 154 classes/interfaces/traits/enums.
**Mode:** Read-only on source. Only this report written.
**Date:** 2026-06-20
**Baseline:** `docs/cleanup/04-circular-deps.md` (PR #146) — re-derived independently.

---

## TL;DR

The service-layer dependency graph is **acyclic**. The one genuine cycle the
prior pass flagged — `PaymentsService` ↔ `IntegrationsService` — **has been
fixed** (PR #146, commit `7e31e17`): `IntegrationsService` no longer calls
`getPayments()->isAwaitingPayment()`; it now reads the predicate off the
`Submission` element (`$submission->isAwaitingPayment()`,
`IntegrationsService.php:193`). The remaining edge `Payments → Integrations`
(`PaymentsService.php:141`) is one-directional. **No genuine cycle remains.**

All other mutual A↔B pairs are benign Craft/Yii idioms (the `Plugin`
service-locator hub, element⇄query pairs, element⇄exporter, the queue-job
re-resolution pattern). **No GitHub issues filed.**

**Genuine cycles: 0. Tight couplings worth fixing now: 0.** One forward-looking
note (a latent-cycle watch on the queue job) is recorded but is not actionable.

---

## Method

madge does not apply (PHP, not JS). No deptrac/phpat in `composer.json`; none
installed. I built the graph with a throwaway PHP/Tarjan script in `/tmp`
(removed; not added to the repo):

1. Parsed every `src/**/*.php`: extracted the declared FQCN, then collected two
   edge classes per file:
   - **`use` edges** — `use fabianhaef\simpleform\…;` imports.
   - **locator/runtime edges** — `Plugin::getInstance()->getX()(…)` calls,
     resolving each getter name to its service class via the getter map in
     `Plugin.php:293–405` (`getDrafts→DraftService`, `getIntegrations→…`, etc.).
     `getSettings()` is excluded — it returns the Craft base settings **model**,
     not a service.
2. Ran Tarjan SCC + mutual-pair detection on the full graph, then **again with
   the `Plugin` hub node deleted** (every class `use`s `Plugin` and `Plugin`
   `use`s every service, so the hub manufactures one giant artificial SCC that
   hides the real structure).
3. `cat`+`grep`-verified every candidate back-edge against the actual source
   before accepting or rejecting it (incl. the dynamic locator calls).

---

## Graph summary

### Full graph (with `Plugin`)
One 44-node SCC — entirely an artifact of the `Plugin` locator hub (mutual `use`
between `Plugin` and every service). Not meaningful; see below.

### Graph with the `Plugin` hub removed
Two SCCs remain, **both composed only of benign `use`-direction idioms**:

- `Form` ↔ `FormQuery` — Craft element/query pairing.
- A 22-node component (Submission/Integration/Email/Payments/Notifications/
  jobs/exporters/events). Inspected edge-by-edge: every internal back-edge is
  either a **data-only `use`** (e.g. each `*Integration` imports the
  `Submission` element type and `SubmissionValues` model — no service call back),
  a **Craft element idiom** (element⇄query, element⇄exporter), or the
  **queue-job re-resolution** pattern. There is **no service→service runtime
  cycle** inside it.

### Service-layer runtime edges (locator calls, `Plugin` hub elided)

`A → B` = "A calls `getInstance()->getB()…` at runtime".

```
SubmissionService    → Akismet, AssetUpload, Audit, Captcha, Email, Payments   (orchestrator; nothing calls back)
PaymentsService      → FormStructure, Integrations, Email                       (one-way — cycle broken)
IntegrationsService  → Audit, IntegrationTypeRegistry, SendIntegrationJob       (NO Payments edge anymore)
EmailService         → Notifications                                            (clean chain)
NotificationsService → FormStructure, Audit
RetentionService     → Audit, Drafts
DraftService         → (leaf; Settings model only)
FieldSyncService     → FieldTypeRegistry, FormStructure
ReportsService       → (reads elements only)
FormStructureService → (Settings model only)
Akismet/Captcha      → (Settings model only)
SendIntegrationJob   → Integrations
```

Leaf services (no outgoing service edge): `AuditService`, `AssetUploadService`,
`DraftService`, `FieldTypeRegistry`, `IntegrationTypeRegistry`,
`CaptchaProviderRegistry`, `ReportsService`, `FormStructureService`.

**Only orchestrator with wide fan-out:** `SubmissionService` — by design, and
nothing calls back into it, so it is not part of any cycle.

---

## Cycle verification

### Confirmed FIXED — `PaymentsService` ↔ `IntegrationsService` (was Finding 1)

| Edge | `file:line` | Status |
|------|-------------|--------|
| `Integrations → Payments` | (was `IntegrationsService.php:193`, `getPayments()->isAwaitingPayment()`) | **REMOVED.** Now `$submission->isAwaitingPayment()` — a method on the `Submission` element (`src/elements/Submission.php:40`). `grep "getPayments()" src/` confirms `IntegrationsService` no longer references it. |
| `Payments → Integrations` | `src/services/PaymentsService.php:141` (`getIntegrations()->dispatchForSubmission()` inside `markPaid()`) | Remains — **one-directional**, no back-edge. Not a cycle. |

The prior report's recommended "Option B (minimal)" — push the awaiting-payment
predicate onto the `Submission` element — was implemented in PR #146. The graph
is now acyclic at the service level. **No issue filed.**

---

## Benign couplings (not cycles)

| Pair / edge | `file:line` | Why benign |
|-------------|-------------|------------|
| `Plugin` ↔ every service | `Plugin.php:293–405` getters; services `use Plugin` | Service-locator hub. Back-edges are **runtime `getInstance()` lookups**, not construction dependencies — no init-order cycle. Idiomatic Craft/Yii. |
| `Submission` ↔ `SubmissionQuery` | `Submission.php:65` (`find(): SubmissionQuery`), query `use`s element | Mandatory Craft element⇄query pairing. Reverse edge is a type reference. |
| `Form` ↔ `FormQuery` | same pattern | Same Craft idiom. |
| `Submission` ↔ `SubmissionExporter` | `Submission.php:208` (`defineExporters()` registers the exporter); exporter `use`s element | Standard Craft exporter registration; one logical direction. |
| `IntegrationsService` ↔ `SendIntegrationJob` | `IntegrationsService.php:212` pushes the job; `SendIntegrationJob.php:30` re-resolves the service | **Idiomatic Craft queue pattern.** The job stores only scalar IDs (`integrationId`, `submissionId`) and re-resolves the service via the locator at execution time, in a separate worker request. No retained service reference; decoupled in time. See note below. |
| `PaymentsService → IntegrationsService → SendIntegrationJob → IntegrationsService` | n/a | Not a cycle: the job edge is a re-resolution of the same service, not a return path to `Payments`. |
| `elements\Form → FormStructureService` | computed property reads a service | One-way; idiomatic. |
| `models\FieldModel → FieldTypeRegistry` | validation delegates to the registry | One-way. |
| `controllers\FormsController → new FieldSyncService()` | direct instantiation of a stateless helper | One-way. |
| integrations / GQL / MCP tools / widgets / TwigExtension → services & elements | various | All depend **downward** only; no back-edges into them. |

---

## Forward-looking note (no action)

**`SendIntegrationJob` ↔ `IntegrationsService` is the only bidirectional
*runtime* edge in the plugin** (service pushes the job; job calls
`getIntegrations()->runOnce()` / `getIntegrationById()`). Today this is the
correct, idiomatic queue shape and is safe because the job holds only primitive
payload and is decoupled across requests. It is recorded here only so a future
edit is aware: if the job ever starts holding a service instance or the service
starts depending on job *state*, it would tighten into a real cycle. No change
warranted now; not filed as an issue.

---

## Prioritized recommendations

| # | Item | Verdict | Action |
|---|------|---------|--------|
| 1 | `Payments` ↔ `Integrations` | **Already fixed (PR #146)** | None |
| 2 | `Plugin` locator hub | Benign idiom | None |
| 3 | Element⇄query / element⇄exporter pairs | Benign Craft idiom | None |
| 4 | `SendIntegrationJob` ↔ `IntegrationsService` | Benign queue idiom; latent-cycle watch only | None (note above) |
| 5 | `elements\Form → FormStructureService`, `FieldModel → FieldTypeRegistry`, `FormsController → new FieldSyncService()` | Benign one-way layering | None |

**Genuine cycles: 0. Issues filed: 0.** The service graph is acyclic and almost
entirely one-directional; the `SubmissionService` orchestrator and the `Plugin`
hub are deliberate, non-tangling shapes. Nothing to auto-implement.
