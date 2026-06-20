# Concern #4 — Circular Dependencies & Tangled Coupling (Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Scope:** `src/` — ~150 PHP files (grown from 72 at the prior pass)
**Mode:** Read-only research. No source files edited.
**Date:** 2026-06-20 (re-run; supersedes the 2026-06-14 assessment)

---

## Summary

The codebase is **mostly well-layered**, but the service layer has grown
substantially since the last pass (PaymentsService, IntegrationsService,
NotificationsService, AuditService, RetentionService, ReportsService, jobs, MCP
tools), and that growth introduced **one genuine, non-idiomatic service↔service
cycle** that the old report predates:

- **`PaymentsService` ↔ `IntegrationsService`** — a true bidirectional runtime
  dependency. **HIGH confidence, 1 finding.**

The previously documented "cycles" (the `Plugin` service-locator hub and the
`Form`↔`FormQuery` / `Submission`↔`SubmissionQuery` element/query pairs) remain
benign and are not repeated in detail here — they are idiomatic Craft/Yii and
require no action.

**HIGH-confidence recommendations: 1.** Everything else is benign or a minor
layering note.

---

## Method

Manual graph build (madge is JS; no deptrac/phpat in `composer.json`):

1. For every file under `src/services`, `src/jobs`, `src/integrations`,
   `src/elements`, `src/models`, `src/controllers`, extracted intra-plugin
   `use fabianhaef\simpleform\…` edges.
2. **Critically**, extracted the *runtime* service-call edges, not just `use`
   statements: every `Plugin::getInstance()->getX()` call inside each service,
   since service↔service coupling in this codebase flows through the locator, not
   constructor injection. (`use`-only analysis misses these because services
   import `Plugin`, not each other.)
3. Walked the resulting directed graph for mutual pairs (A→B ∧ B→A) and larger
   SCCs after deleting the `Plugin` hub node.

---

## Service-layer dependency map (runtime call edges)

`A → B` means "A calls `getInstance()->getB()...` at runtime".

```
SubmissionService → Akismet, AssetUpload, Audit, Captcha, Email, Payments, Settings
PaymentsService   → Email, FormStructure, Integrations          ← back-edge ↓
IntegrationsService → Audit, IntegrationTypeRegistry, Payments, Settings  ← back-edge ↑
EmailService      → Notifications, Settings
NotificationsService → FormStructure, Audit
RetentionService  → Audit, Settings
FieldSyncService  → FieldTypeRegistry, FormStructure
AkismetService    → Settings
CaptchaService    → Settings
FormStructureService → Settings
SendIntegrationJob → Integrations
```

Leaf services (no outgoing service edges): `AuditService`, `AssetUploadService`,
`FieldTypeRegistry`, `IntegrationTypeRegistry`, `CaptchaProviderRegistry`,
`ReportsService` (reads elements only), `FormStructureService` (→ Settings only).

**The only mutual pair in the entire service graph is `Payments` ↔ `Integrations`.**
`SubmissionService` is the widest fan-out (an orchestrator), but every one of its
edges is one-directional — nothing calls back into it. `EmailService →
NotificationsService → {FormStructure, Audit}` is a clean chain, not a cycle.

### Non-service layering edges (for completeness)

| Edge | `file:line` | Verdict |
|------|-------------|---------|
| `elements\Form → FormStructureService` | `src/elements/Form.php` (`getInstance()->getFormStructure()->getFieldSet()`) | Element computed-property reads a service. Idiomatic Craft; one-way; benign. |
| `models\FieldModel → FieldTypeRegistry` | `src/models/FieldModel.php:111` | Model validation delegates to the field-type registry. One-way; benign. |
| `controllers\FormsController → new FieldSyncService()` | `src/controllers/FormsController.php:115` | Direct instantiation of a stateless helper service. One-way; benign. |
| integrations (`*Integration`, `support/*`) → `elements\Submission`, `models\FormModel` | various | Data-only imports; no service back-edges. Clean. |

No model/element/integration calls back into `Payments` or `Integrations`, so the
cycle is confined to those two classes.

---

## Finding 1 (HIGH) — `PaymentsService` ↔ `IntegrationsService` mutual dependency

**The two back-edges:**

| Edge | `file:line` | Call |
|------|-------------|------|
| `Payments → Integrations` | `src/services/PaymentsService.php:140` | `Plugin::getInstance()->getIntegrations()->dispatchForSubmission($submission)` inside `markPaid()` |
| `Integrations → Payments` | `src/services/IntegrationsService.php:193` | `Plugin::getInstance()->getPayments()->isAwaitingPayment($submission)` inside `dispatchForSubmission()` |

**Why it exists (the domain reason):**
- `IntegrationsService::dispatchForSubmission()` must *withhold* dispatch while a
  submission is still awaiting payment, so it asks Payments `isAwaitingPayment()`
  (`IntegrationsService.php:189-195`).
- `PaymentsService::markPaid()` must *re-fire* the withheld dispatch (and email)
  once the Commerce order completes, so it calls back into
  `Integrations::dispatchForSubmission()` (`PaymentsService.php:125-142`).

**Is it dangerous?** It does **not** infinitely recurse: `isAwaitingPayment()` is
a pure property read (`PaymentsService.php:82-84`, `paymentStatus === 'pending'`),
and by the time `markPaid()` calls back into dispatch, it has already set
`paymentStatus = 'paid'` (`:131`), so the guard at `IntegrationsService.php:193`
returns false and there is no re-entry. So this is a **structural** cycle, not a
runtime hang.

**Why it's still worth fixing (HIGH confidence it's a real smell):**
- It is the *only* service↔service cycle in the plugin — an outlier against an
  otherwise clean one-directional service graph.
- It couples a payment concern and an integration-dispatch concern bidirectionally,
  making either one impossible to reason about or unit-test in isolation without
  stubbing the other.
- The correctness ("no infinite loop") depends on a subtle ordering invariant
  (status is flipped to `paid` *before* the callback) that is easy to break in a
  future edit — a latent footgun.

### Proposed decoupling (pick one)

**Option A — Invert via the existing event (recommended).**
The plugin already dispatches integrations from an event listener
(`Plugin.php:202-207`, `EVENT_AFTER_SUBMISSION_SAVE →
getIntegrations()->dispatchForSubmission()`). Introduce a parallel
`EVENT_SUBMISSION_PAID` (or reuse a payment-state event). `PaymentsService::markPaid()`
fires it instead of directly calling `Integrations` + `Email`; the `Plugin::init()`
wiring subscribes Integrations and Email dispatch to it. This removes the
`Payments → Integrations` edge entirely and matches the pattern already in the
codebase.
- *Confidence:* High. *Complexity reduction:* High (breaks the only cycle; aligns
  payment-release with the existing event-driven dispatch path).

**Option B — Extract the "should I dispatch yet?" predicate out of Integrations.**
Move the awaiting-payment gate out of `IntegrationsService::dispatchForSubmission()`
so Integrations no longer needs to know about Payments. The gate could live on the
`Submission` element (`$submission->isAwaitingPayment()` reading its own
`paymentStatus`) or in the dispatch caller. This removes the `Integrations →
Payments` edge.
- *Confidence:* High. *Complexity reduction:* Medium (kills one edge; combine with
  A to fully decouple, or use alone to make the cycle one-directional Payments →
  Integrations, which is acceptable).

**Minimal viable fix:** Option B alone is the smallest change — push the
`isAwaitingPayment` check down onto the `Submission` element (it already owns
`paymentStatus`), so `IntegrationsService` stops importing/calling `Payments`. The
graph then becomes `Payments → Integrations` (one-way), no cycle, and the
domain logic is unchanged.

---

## Other structural checks (no cycle)

- **`SubmissionService` god-service?** It is the widest fan-out (7 service edges:
  Akismet, AssetUpload, Audit, Captcha, Email, Payments, Settings — see
  `SubmissionService.php:246` etc.). This is an **orchestrator**, which is the
  correct place to concentrate fan-out; crucially **nothing calls back into it**,
  so it is not part of any cycle and not a tangling hazard. No action.
- **`Plugin` service-locator hub.** Unchanged from the prior assessment — back-edges
  into `Plugin` are runtime `getInstance()->getX()` lookups, not construction
  dependencies. Benign/idiomatic. No action.
- **`Form`↔`FormQuery`, `Submission`↔`SubmissionQuery`.** Unchanged — mandatory
  Craft element⇄query pairing; reverse edge is a PHPDoc generic. Benign. No action.
- **MCP tools / GQL / fields / jobs.** Spot-checked: tools and GQL resolvers depend
  *downward* on services/elements only; `SendIntegrationJob → IntegrationsService`
  is one-way. No cycles.
- **Self-loops:** none.

---

## Prioritized recommendations

| # | Item | Verdict | Action | Confidence | Complexity ↓ |
|---|------|---------|--------|------------|--------------|
| **1** | **`Payments` ↔ `Integrations` cycle** (`PaymentsService.php:140`, `IntegrationsService.php:193`) | **Real cycle — fix** | **Option B (minimal): move `isAwaitingPayment` gate onto the `Submission` element so Integrations no longer calls Payments**; or Option A: fire an `EVENT_SUBMISSION_PAID` from `markPaid()` and subscribe dispatch in `Plugin::init()`. | **High** | High |
| 2 | `Plugin` locator hub | Benign | None | High | — |
| 3 | `Form`↔`FormQuery`, `Submission`↔`SubmissionQuery` | Benign (Craft idiom, PHPDoc back-edge) | None | High | — |
| 4 | `elements\Form → FormStructureService`, `FieldModel → FieldTypeRegistry`, `FormsController → new FieldSyncService()` | Benign layering notes | None | High | — |

---

## High-confidence implementation checklist

1. **Break the `Payments` ↔ `Integrations` cycle.** Smallest safe change: relocate
   the awaiting-payment predicate so `IntegrationsService::dispatchForSubmission()`
   (`src/services/IntegrationsService.php:189-195`) no longer calls
   `Plugin::getInstance()->getPayments()->isAwaitingPayment()`. Read
   `paymentStatus` from the `Submission` element directly (it already owns the
   column). Optionally also invert `PaymentsService::markPaid()`
   (`src/services/PaymentsService.php:125-142`) onto a new payment-completed event
   for full symmetry with the existing `EVENT_AFTER_SUBMISSION_SAVE` dispatch
   wiring. Add/adjust a unit test that markPaid() releases dispatch exactly once.

No other changes recommended. The rest of the service graph is acyclic and
one-directional; the orchestrator (`SubmissionService`) and the `Plugin` hub are
deliberate, non-tangling shapes.
