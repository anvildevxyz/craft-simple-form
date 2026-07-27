# Concern 1 — DRY / De-duplication (net-new code focus)

**Plugin:** Simple Form (Craft CMS 5, PHPStan L7, ECS)
**Date:** 2026-06-27
**Scope:** Net-new code — coupons (#246), address autocomplete (#250), workflow (#248),
payments (#116), logic jumps (#245), and the conversational theme — plus a `src/`-wide
re-scan of the hotspots named in the brief. Complements the prior `01-dry-dedup.md`
(2026-06-21), which predates this code and is not re-litigated here.

---

## 1. Critical assessment

The net-new code is, on the whole, **well-factored and not a copy-paste mess**. The team
already built the right seams before this work landed: `SimpleFormControllerTrait`
(`getFormOrFail`, the `asJson{Success,Error,Errors}` envelope, `PERMISSION`-const gating),
a single centralized settings-tab list in `settings/index.twig`, a shared `postJson` +
`sfConfirm` core in `cp.js`, and `SettingsController::saveWorkflow()` as one persistence
seam for all four workflow mutators. Most of what *looks* duplicated is structural-by-design
(per-provider geo mappers, per-tab field arrays, per-element column properties) and should
stay.

That said, the recent features introduced a handful of **genuine, verbatim duplications**
that consolidation would simplify. The clearest is `storeCurrency()`, copy-pasted byte-for-byte
into two controllers when a `PaymentsService` (which already owns `commerceAvailable()`) is the
obvious home. The second is the toggle+delete wiring in `cp.js`, now triplicated across
integrations/notifications/coupons with only the element id and the id-param name differing.
The CP CRUD controllers (Coupons/Notifications/Integrations) rhyme strongly but diverge in
their service method names, so a shared base would add more indirection than it removes — I
recommend *against* that one. Net: ~2 high-value fixes, a couple of medium Twig/JS tidies,
and several explicit "leave it" calls.

Confidence key: **High** = certain, safe under the gate, real improvement. **Medium** = safe
but a judgement call. **Low** = marginal ROI.

---

## 2. Findings

### H1 — `storeCurrency()` duplicated verbatim in two controllers *(High)*

**Sites:**
- `controllers/SubmitController.php:193-203` (`private function storeCurrency(): ?string`)
- `controllers/CouponsController.php:49-59` (identical body)

**What's duplicated:** Byte-for-byte identical method — `class_exists` guard,
`getPaymentCurrencies()->getPrimaryPaymentCurrencyIso()`, `catch (\Throwable) { return null; }`.
Both controllers already reach `PaymentsService` (`CouponsController::actionSettingsIndex`
calls `getPayments()->commerceAvailable()` at line 40; `SubmitController` holds `$plugin`).

**Proposed change:** Add `PaymentsService::primaryCurrencyIso(): ?string` next to the existing
`commerceAvailable()` (`services/PaymentsService.php:38-41`) and delete both private copies,
replacing the two call sites (`SubmitController.php:174`, `CouponsController.php:39`) with
`Plugin::getInstance()->getPayments()->primaryCurrencyIso()`.

**Risk / blast radius:** Very low. Pure move, no behavior change. Covered by the PHP gate
(ECS + PHPStan L7 + PHPUnit). Commerce-not-in-test-app means the null branch is exercised; the
Commerce branch is live-smoke-only, but the code is unchanged by the move.

---

### M1 — Triplicated toggle+delete wiring in `cp.js` *(Medium)*

**Sites:** `web/assets/cp/dist/js/cp.js:158-180` (integrations), `:182-204` (notifications),
`:206-228` (coupons). The source comments already flag the copy: *"mirrors integrations"*.

**What's duplicated:** Three ~22-line blocks identical except for (a) the container id
(`sf-integrations` / `sf-notifications` / `sf-coupons`) and (b) the POST id-param name
(`integrationId` / `notificationId` / `couponId`). Each does the same `getElementById` guard,
`{generic, network}` message object, `.status-toggle` click → `postJson(toggleUrl, …)`, and
`.delete[data-id]` click → `sfConfirm` → `postJson(deleteUrl, …)`.

**Proposed change:** Extract one helper and call it three times, e.g.:

```js
function wireIndexActions(containerId, idParam) {
    var el = document.getElementById(containerId);
    if (!el) { return; }
    var msgs = { generic: el.dataset.error, network: el.dataset.networkError };
    el.querySelectorAll('.status-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            postJson(el.dataset.toggleUrl, idParam + '=' + btn.dataset.id,
                function () { location.reload(); }, msgs);
        });
    });
    el.querySelectorAll('.delete[data-id]').forEach(function (delEl) {
        delEl.addEventListener('click', function () {
            sfConfirm(el.dataset.confirmDelete).then(function (ok) {
                if (!ok) { return; }
                postJson(el.dataset.deleteUrl, idParam + '=' + delEl.dataset.id,
                    function () { location.reload(); }, msgs);
            });
        });
    });
}
wireIndexActions('sf-integrations', 'integrationId');
wireIndexActions('sf-notifications', 'notificationId');
wireIndexActions('sf-coupons', 'couponId');
```

Collapses ~66 lines to ~25. The per-form attach-toggle block (`cp.js:230-245`) is a 4th
near-variant (different param payload + `attached` handling) and can stay as-is, or share the
toggle half only — don't force it.

**Risk / blast radius:** Medium. `cp.js` is a hand-maintained dist file (no build step), so
editing it directly is fine, **but** `composer test:js` covers only logic modules
(formula/jump/steps/conditions) — there is **no test over this DOM wiring**, so a regression
would only surface in a CP smoke run. Recommend a manual or Playwright smoke of toggle+delete
on all three index tabs after the change. Behavior is intended to be identical.

---

### M2 — Repeated status-toggle / delete-icon Twig markup across index tabs *(Medium)*

**Sites:**
- `templates/settings/_tabs/coupons.twig:16-21` (wrapper), `:59-69` (toggle + delete buttons)
- `templates/settings/_tabs/integrations.twig:16-21`, `:41-50`
- `templates/forms/notifications/index.twig:37-39`, `:65-72`

**What's duplicated:** (a) the `<div id="sf-…" data-toggle-url data-delete-url
data-confirm-delete data-error data-network-error>` wrapper, and (b) the `status-toggle`
button + `delete icon` button cell. These are near-identical apart from the section id, URLs,
confirm copy, and aria-label noun.

**Proposed change:** Add a small macro file (e.g. `templates/_macros/_index_actions.twig`) with
`actionsContainer(id, toggleUrl, deleteUrl, confirmDelete)` and
`rowActions(id, enabled, deleteAriaLabel)` macros, imported into the three templates. Keeps the
table columns local (they legitimately differ) while sharing the two repeated fragments.

**Risk / blast radius:** Low-medium. `SettingsTabsRenderTest` gate-renders the settings tabs,
so a render break is caught; the per-form notifications index is **not** in that test, so eyeball
that one. The data-attribute contract is consumed by M1's JS — keep attribute names byte-identical.
Lower ROI than M1/H1; do it only alongside M1 (they touch the same contract) or skip.

---

### M3 — Submission plugin-column list maintained in three places *(Medium → low ROI)*

**Sites:**
- `elements/db/SubmissionQuery.php:103-123` (19-column `select()` list, table-prefixed)
- `elements/Submission.php:176-199` (`afterSave` row — same columns as an assoc value map, plus
  `siteId`/`data`/`dateUpdated`)
- `elements/Submission.php:28-65` (the typed properties themselves)

**What's duplicated:** The set of plugin-owned column names. Adding a column today means editing
all three (plus a migration). This is a real maintenance hazard — but the three uses have
genuinely different *shapes*: a prefixed SELECT string-list, a key⇒value INSERT/UPDATE map, and
typed PHP properties. A shared `private const` of bare column names could feed the
`SubmissionQuery` select (mapped to `'simpleform_submissions.'.$c`) cleanly, but the `afterSave`
map and the typed properties cannot mechanically consume it without reflection/magic that would
*reduce* clarity.

**Proposed change (conservative):** Only if desired, introduce
`SubmissionQuery::PLUGIN_COLUMNS` (bare names) and build the `select()` array from it. Leave
`afterSave` and the property list alone. This dedupes the SELECT side and gives a single
documented list, without forcing the value-map into an awkward abstraction.

**Risk / blast radius:** Low change, but **low ROI** — it only removes one of the three copies
and adds a tiny indirection in a hot query path. Honestly borderline; flag for the maintainer to
decide. Covered by integration tests that query submissions.

---

## 3. Explicitly NOT worth changing (anti-recommendations)

- **Coupon `fetch` vs address-autocomplete `fetch` in `simple-form.js`**
  (`:790-823` apply vs `:962-983` search). They share only the trivial `fetch → .json() →
  branch` skeleton; everything else differs (POST `FormData` + CSRF + form values vs keyless GET
  to an external geocoder with debounce, dropdown rendering, keyboard nav, provider mapping). A
  shared wrapper would be **premature abstraction** that obscures two different protocols. Leave.

- **CP CRUD controllers as a shared base** (Coupons vs Integrations vs Notifications
  `save`/`delete`/`toggle`). The actions rhyme, but each delegates to a service with a
  *different* method name (`delete` vs `deleteIntegration`; `save` vs `saveIntegration`;
  `toggle()` returns a bool in Notifications but is hand-rolled in Coupons/Integrations) and
  different request params/redirect targets. An `AbstractSettingsCrudController` would need so
  many overridable hooks that it adds more surface than it removes. The duplication that *is*
  cheaply shareable (`requirePostRequest` + `requireAcceptsJson` + the JSON envelope) is **already**
  in `SimpleFormControllerTrait`. Leave the controllers concrete.

- **`SettingsController` mcpToken vs workflow actions** (`:125-170` vs `:176-283`). The four
  workflow mutators already funnel through the shared `saveWorkflow()` helper; the two mcpToken
  actions operate on a different subsystem (`McpTokenManager`, one-time-secret flash). No further
  consolidation pays off. Leave.

- **`CouponsController::intOrNull` / `NotificationsController::nullableString`** — trivial
  one-line private coercers, used once each, semantically different. Hoisting to the trait is
  churn for no gain. Leave.

- **Settings tab list** — already a single source of truth in `settings/index.twig:11-20`. No
  action.

---

## 4. Summary

| ID | Confidence | Site | Action |
|----|-----------|------|--------|
| H1 | High | SubmitController:193 / CouponsController:49 | Move `storeCurrency()` → `PaymentsService::primaryCurrencyIso()` |
| M1 | Medium | cp.js:158-228 | Extract `wireIndexActions(containerId, idParam)`, call 3× |
| M2 | Medium | coupons/integrations/notifications index twig | Shared `_index_actions` macros |
| M3 | Medium (low ROI) | SubmissionQuery:103 / Submission:176 | Optional `PLUGIN_COLUMNS` const for the SELECT only |

Net safe reduction: ~90–110 LOC (H1 + M1) with no behavior change; M2/M3 are optional polish.
The recent code is largely clean — H1 is the only unambiguous win.
