# Round 3 — 04 Circular Dependencies & Tangled Coupling

Scope: full re-audit of `src/` (210 PHP files, namespace `fabianhaef\simpleform\`),
covering the ~39 commits since `c5b8fe7` (payments #116, forms-as-code #218/#225/#226,
dev events #219, JS hooks #220, make/* generators #222, tabbed editor, multi-field
rows, Install-migration collapse, leanify passes). Research-only; no source touched.
JS = built `dist/` artifacts only — `madge` is not meaningful and not used; PHP has
no madge. The runtime graph lives in `Plugin::getInstance()->getXxx()` locator calls
(static `use` imports hide it), so I traced those exhaustively across services,
controllers, console commands, jobs, elements, models, helpers, and events, then ran
an automated DFS cycle check over the resolved service graph.

## (a) Critical assessment

**No harmful cycles. Zero. The service graph is a clean DAG.**

The one true cycle ever found in this codebase — `EmailService ↔ PdfService` (round 1
HIGH) — was fixed by the `SubmissionBodyRenderer` extraction and remains fixed. The
delta verified it; this round re-confirms it against the post-delta code: `PdfService`
now resolves only `getSafeRender`, `getSettings`, `getSubmissionBodyRenderer` — it no
longer references `EmailService` at all. The surviving `EmailService → Pdf` edge
(`EmailService` attaches an optional PDF) is one-directional and correct.

Evidence the rest is acyclic:

- **Service → service edges form a DAG.** I tabulated all 24 services' outbound locator
  calls (Appendix) and ran a DFS three-colour cycle detector over them:
  **0 cycles found.** Every edge points toward a leaf.
- **Ten leaf services have zero outbound service edges** (verified individually):
  `Settings`, `Audit`, `AssetUpload`, `SafeRender`, `FieldTypeRegistry`,
  `IntegrationTypeRegistry`, `CaptchaProviderRegistry`, `SubmissionEditTokens`,
  `SubmissionBodyRenderer`, plus `FormStructure` (which reaches only the `Settings`
  leaf). These are DAG sinks; nothing they're called by is called *by* them.
- **The hot post-submit chain is a straight line.** `SubmissionService → Payments →
  Email → Pdf → SubmissionBodyRenderer` never folds back: none of Payments/Email/Pdf
  call `getSubmissionService()`. `SubmissionService` is invoked only by upstream callers
  (controllers, `FormRenderService`, gql, element actions) it never invokes in return.
- **Models / elements / helpers → services are strictly downward.** `Form →
  FormStructure`, `FieldModel → FieldTypeRegistry`, `NotificationModel → Pdf`,
  `SubmissionCsv → FieldTypeRegistry`. All targets are leaves; none import the
  model/element/helper back. The 8 new/changed helpers (ConditionalEvaluator,
  ConsentText, FormContentHelper, FormRows, FormSteps, Formula, FieldQueryHelper,
  HiddenValueResolver) make no service calls at all.
- **New code introduces no back-edges:**
  - **Console (forms-as-code / make):** `FormsController → Portability`,
    `SubmissionsController → Payments, Retention`, `MakeController → (none)`,
    `CacheController → FormStructure`, etc. — all downward.
  - **Jobs:** `SendIntegrationJob → Integrations`, `SendNotifications → Email` — downward.
  - **Events** (10 new DTO classes in `src/events/`): pure data carriers, **zero**
    `getInstance()->get*` calls — they cannot participate in a cycle.
  - **Payments:** `PaymentsService` → `Email, FormStructure, Integrations` (downward).
    Its other two `getInstance()` calls go to **`\craft\commerce\Plugin`**
    (`getPayments`, `getGateways`, `PaymentsService.php:159,348`) — a *different
    plugin's* services, not a simple-form back-edge.
  - **Forms-as-code:** `FormPortabilityService` constructs a `Form` element
    (`:675`) and calls `Audit, FieldSync, Integrations, Notifications` (downward).
    `FieldSync → FieldTypeRegistry, FormStructure` (leaves). No loop.

Correction to prior appendix: the round-1/delta service map listed a **`Version`** leg
for `FormPortabilityService`. That is `Plugin::getInstance()->getVersion()`
(`FormPortabilityService.php:86`) — Craft core's `\craft\base\Plugin::getVersion()`
returning the version string — **not** a simple-form service. There is no `VersionService`.
It is a property read, not a graph edge. (Does not change the verdict.)

Idiomatic-Craft non-issues (NOT cycles, no action), unchanged from prior rounds:

- **Everything reaching the `Plugin` singleton** — Craft's prescribed service-locator
  hub; normal, not a god object in the coupling sense.
- **`Form ↔ Submission` element cross-references** — standard element-relation querying
  via element queries, not a class-design cycle.
- **Registry fan-out** — `FieldTypeRegistry` importing ~30 concrete field types (and
  the integration/captcha registries doing likewise) is fan-out by design; the field
  types don't import the registry back.

## (b) Findings table

| # | Cycle path | Why harmful | Proposed untangling | Confidence | Risk |
|---|-----------|-------------|---------------------|-----------|------|
| — | *(none found)* | — | — | — | — |

The DFS over the full 24-node service graph returns **0 cycles**. No model, element,
helper, controller, console command, job, or event closes a back-edge into the
services it depends on.

### Non-cycle observations (informational, out of scope to "break")

| Item | Files | Note |
|------|-------|------|
| `SubmissionService` hub | `src/services/SubmissionService.php` | Widest fan-**out** (Akismet, AssetUpload, Audit, Captcha, Denylist, Email, FieldTypeRegistry, Payments, Settings, SubmissionEditTokens) but fully **acyclic** — none call it back. Coupling *weight*, not a cycle. Carried from round 1 as LOW; still not worth splitting. |
| Direct `new FieldSyncService()` | `FormsController.php:177`, `FormCloneService.php:209` | Bypasses the locator but `FieldSyncService` only reaches the `FieldTypeRegistry`/`FormStructure` leaves — no back-edge to its instantiators. Not a cycle; a minor style note (prefer `getFieldSync()`), out of scope for this concern. |

## (c) High-confidence recommendations

**None.** There is no circular dependency to break. The sole historical cycle was
already resolved (`SubmissionBodyRenderer` extraction) and stays resolved through the
payments / forms-as-code / events / make-generator / tabbed-editor additions. Every
new and changed edge points downward in the DAG. No interface extraction, no event
indirection, no dependency inversion is warranted — adding any would be abstraction for
purity, against the brief.

The only nit worth a future touch (not a cycle, hence not actioned here): the two
`new FieldSyncService()` call sites could use the locator (`getFieldSync()`) for
consistency. Defer to a DRY/style pass, not this one.

---

### Appendix — service → service edge map (locator-resolved, post-delta)

```
Akismet                -> Settings
AssetUpload            -> (leaf)
Audit                  -> (leaf)
Captcha                -> Settings
CaptchaProviderRegistry-> (leaf)
Denylist               -> Settings
Drafts                 -> Settings
Email                  -> Notifications, Pdf, SafeRender, Settings, SubmissionBodyRenderer
FieldSync              -> FieldTypeRegistry, FormStructure
FieldTypeRegistry      -> (leaf)
FormClone              -> Audit, FormStructure, Integrations, Notifications
FormPortability        -> Audit, FieldSync, Integrations, Notifications   (+ Craft core getVersion(), not a service)
FormRender             -> Captcha, Drafts, FieldTypeRegistry, FormStructure, Settings, SubmissionService
FormStructure          -> Settings
Integrations           -> Audit, IntegrationTypeRegistry, Settings
IntegrationTypeRegistry-> (leaf)
Notifications          -> Audit, FormStructure
Payments               -> Email, FormStructure, Integrations  (+ Commerce plugin getPayments/getGateways)
Pdf                    -> SafeRender, Settings, SubmissionBodyRenderer
Reports                -> FormStructure
Retention              -> AssetUpload, Audit, Drafts, Settings
SafeRender             -> (leaf)
Settings               -> (leaf)
SubmissionBodyRenderer -> (leaf)
SubmissionEditTokens   -> (leaf)
SubmissionService      -> Akismet, AssetUpload, Audit, Captcha, Denylist, Email,
                          FieldTypeRegistry, Payments, Settings, SubmissionEditTokens
```

DFS three-colour cycle check over this graph: **0 cycles.** No reciprocated pair exists.
