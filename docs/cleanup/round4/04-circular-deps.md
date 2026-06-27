# 04 — Circular Dependencies & Tangled Coupling

Scope: all of `src/` (222 internal classes, PSR-4 `anvildev\simpleform\`) plus the
hand-written JS under `src/web/assets/*/dist/js/`. Research-only; no source modified.
Re-run focused on the recently-shipped code (coupons, address autocomplete, workflow,
conversational theme, payments #116, logic jumps #245, review-fix commit) but the whole
tree was analysed.

## 1. Critical assessment

### JS — clean
`npx madge --circular --extensions js src/web/assets` processes the 4 hand-written
bundles (`form/dist/js/simple-form.js`, `form/dist/js/embed.js`, `cp/dist/js/cp.js`,
`cp/dist/js/form-builder.js`) and reports **"No circular dependency found!"**. These are
standalone IIFE-style bundles with no cross-imports, so there is nothing to untangle. The
net-new address-autocomplete functions and the conversational step/progress logic live
inside `simple-form.js` and introduce no new module edges.

### PHP service graph — a clean DAG (no cycles)
I traced the *real* runtime coupling: every `Plugin::getInstance()->getXxx()` lazy
service-locator lookup, mapped to the 31 plugin service getters registered in
`Plugin::setComponents()` (src/Plugin.php:183-213). Building the service→service graph and
running cycle detection yields **zero cycles**. The graph is well-layered, e.g.:

- `SubmissionService` is the orchestration hub → Akismet, AssetUpload, Audit, Captcha,
  Denylist, Draft, Email, FieldTypeRegistry, **Payments**, QuizScoring,
  SubmissionEditToken, **Workflow** — all leaf-ward, none point back.
- `PaymentsService` → CouponsService, EmailService, FormStructureService,
  IntegrationsService. Crucially **`CouponsService` has no outbound plugin-service calls
  at all** (only `getDb`/`getLastInsertID`), so the feared
  Payments↔Coupons↔Submission↔Plugin cycle does **not** exist — it is a one-way fan-out.
- `WorkflowService` depends only on `AuditService` + `getSettings()` (src/services/
  WorkflowService.php:30,40,84,185). No service depends back into it except the
  Submission hub, so Workflow↔Plugin is purely the locator hub edge (below), not a real
  cycle.

The brief's specific suspects (PaymentsService↔CouponsService↔SubmissionService↔Plugin,
WorkflowService↔Plugin) were checked directly and are **not** harmful cycles.

### The static `use`-import "cycles" are all framework-idiomatic, not harmful
A class-level `use`-import cycle scan (222 classes) reports ~45 cycles, but every single
one is one of two conventional Craft patterns and **none** is a real compile/load-order
hazard in PHP (PHP resolves `use` lazily at call time — there is no ES-module-style
top-of-file evaluation order to break):

1. **Service-locator hub** — `Plugin -> <AnyService> -> Plugin`. Each service imports
   `Plugin` to call `Plugin::getInstance()->getX()`, and `Plugin` imports every service
   class in `setComponents()`. This bidirectional hub is the textbook Craft/Yii2 pattern
   and accounts for the vast majority of detected cycles. Not refactorable without
   abandoning the framework convention.
2. **Element ↔ Query / Action / Exporter** — `Submission -> SubmissionQuery -> Submission`,
   `Form -> FormQuery -> Form`, `Submission -> SubmissionExporter -> SubmissionCsv ->
   Submission`, `Form -> DuplicateForm -> Form`, `Submission -> SetSubmissionStatus -> …`.
   These are the standard Craft element ↔ element-query / element-action / exporter
   back-references and are universal across every Craft plugin.

I also confirmed there are **no service constructors and no eager `getInstance()->getX()`
calls inside any `__construct`/`init`** — every collaborator is fetched lazily inside
method bodies, so even the locator hub has no instantiation-order risk.

## 2. Recommendations

**None actionable.** For the circular-dependency / tangled-coupling concern this codebase
is already clean:

- JS: madge-clean (high confidence — tool output).
- PHP runtime service graph: acyclic DAG (high confidence — exhaustive locator-call trace).
- PHP static import cycles: 100% accounted for by the Craft service-locator hub and the
  Element↔Query/Action/Exporter convention — all legitimate, none harmful, none worth
  "fixing" (doing so would fight the framework and add indirection for no benefit).

### Non-actionable observations (informational only, confidence: high)
- `Plugin::setComponents()` (src/Plugin.php:183-213) ⇄ each service: this is the
  intentional service-locator hub. Leave as-is. Lazy `getInstance()->getX()` lookups are
  **not** true compile-time cycles.
- `Submission`/`Form` element ↔ their `*Query`, `*Exporter`, and element-action classes:
  standard Craft pattern. Leave as-is.

If a future maintainer wants to *reduce surface coupling* (not a cycle fix), the only
lever would be constructor-injecting a service's two or three collaborators instead of
locator lookups — but that is premature here: the graph is already acyclic and shallow,
and it would break the consistent project-wide convention. Not recommended.
