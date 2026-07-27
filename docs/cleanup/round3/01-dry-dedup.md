# Round 3 — 01 — DRY / De-duplication Audit (full re-audit)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.3, PHPStan L7, ECS)
**Scope:** all 210 PHP files under `src/` + `examples/` + new console/gql/events subsystems
**Date:** 2026-06-24
**Branch:** `chore/code-quality-round3`
**Method:** Read the two prior reports (`docs/cleanup/01-dry-dedup.md`, `docs/cleanup/delta/01-dry-dedup.md`) as baseline, then full re-audit with emphasis on the ~39 commits added since 2026-06-21 (forms-as-code apply/export/status, `make/*` generators, GraphQL SDL + 13 ObjectTypes, developer event classes, JS hook API, `examples/`). Two parallel read-only Explore sweeps (GraphQL; examples + console) plus first-party verification of every site below against real source.

---

## 1. Critical assessment of DRY health

**The codebase remains genuinely well-factored — this is not a copy-paste mess, and the new code did not regress that.** The new subsystems are mostly clean:

- **Developer event classes** (`src/events/Register*Event`, `Before*Event`, `Modify*Event`, `DefineFieldSetEvent`) are thin data-carrier `yii\base\Event` subclasses. The four `Register*Event` classes look parallel (each a single `public array $types/$providers/$stencils`) but that is the *required Yii event idiom* — a shared base buys nothing and would obscure the per-event property name/type. **Keep separate.**
- **GraphQL** (13 ObjectTypes + resolver + mutations): the per-type `getName()/getDescription()/getFieldDefinitions()` + `GqlEntityRegistry::getOrCreate()` skeleton is Craft's framework contract, already centralized in the `SimpleFormObjectType` base. `FormMutations::formatErrors()/errorPayload()` are already consolidated. `FormGqlResolver::mapField()` is a *published-schema* mapper with rich computed keys (validation/conditional/relation/page) and `name` (not `handle`) keys — fundamentally different contract from the MCP `FormPresenter`. Forcing them together is leaky. **Keep separate** (confirms prior M1).
- **`examples/`** (MathCaptchaProvider, ColorField, JsonWebhookIntegration) are *intentionally self-contained* standalone reference implementations. Duplication with `src/` providers there is the point — not a finding.
- **`make/*` generators** (`MakeController`): `handleFromClass()/labelFromClass()` regex helpers are private and used only locally; stub heredocs are unique. Clean.
- **Prior delta items P2 (asset-type const) and H3/H4/H5 (FormContentHelper) stayed fixed** — `FieldTypeRegistry::ASSET_TYPES` and `FormContentHelper::{CONTENT_ATTRS,handleExists,fieldIdsByHandle}` are still the single sources of truth and are referenced everywhere they should be.

**What genuinely remains** is a small set of items, most of which are *carry-overs the prior reports recommended but which were never implemented*, plus two new small regressions introduced by the #226 (full-fidelity export) work:

1. **Prior H1 — Field-write CRUD still duplicated** between `FieldsController` (CP) and `mcp/tools/support/FieldOps` (MCP). FieldOps' docblocks still literally say *"This deliberately mirrors FieldsController"* (lines 16, 173, 225, 262, 274, 315). Highest-value consolidation in the plugin and a documented drift hazard. **Never implemented — still open.**
2. **Prior H2 / delta P1 — `withTransaction()` boilerplate** still hand-rolled byte-identically in 3 services (`FormCloneService`, `FieldSyncService`, `FormPortabilityService`). **Never implemented — still open.**
3. **NEW — `FormPortabilityService` hand-spells the 6-attribute form-content list twice** (`exportFormContent()`, `applyFormContent()`) instead of using `FormContentHelper::CONTENT_ATTRS`, even though its sibling `FormCloneService` already iterates the const. The #226 export work re-introduced exactly the divergence the prior delta closed for clone. Real single-source-of-truth regression.
4. **Prior M4 — Console form-by-handle resolution** duplicated, now across 3 sites (the new `forms/apply` added a third).

Everything else flagged by the sweeps is coincidental similarity with load-bearing divergence (scalar coercers, registries, GQL/MCP mappers, sibling-site loops with different bodies) and should stay. Catalogued in §3.

**Estimated safe reduction from HIGH items:** ~120–180 LOC plus removal of one documented "keep in lockstep" drift hazard (FieldOps↔FieldsController) and one re-introduced content-schema duplication.

---

## 2. Findings

Confidence: **HIGH** = clearly safe, net-simplifying, no behavior change. **MEDIUM** = safe but a judgement call (divergent shape / scoping). **LOW** = marginal ROI or stylistic.

| # | Site(s) | What's duplicated | Why it matters | Recommended change | Conf. | Risk / blast radius |
|---|---------|-------------------|----------------|--------------------|-------|---------------------|
| **R1** | `controllers/FieldsController.php:53-95` (add), `:125-161` (edit), `:163-186` (delete), `:205-` (reorder); `mcp/tools/support/FieldOps.php:179-` (add), `:230-` (update), `:262-` (delete), `:273-` (reorder), `:315-` (supportedSiteIds) | Two hand-written two-table writes against `{{%simpleform_fields}}` + `{{%simpleform_fields_sites}}`: identical `maxSort+1`, `helpText ?: null` coercion, `getFormStructure()->invalidate($formId)`, per-site row seeding, and a near-identical `supportedSiteIds()`. FieldOps' own docblocks (16/173/225/262/274/315) say it "deliberately mirrors FieldsController". | A documented "keep in lockstep" hazard: any field-schema change must be edited in two subsystems, and they already drift (controller wraps each action in a transaction + has a posted-site fallback; FieldOps does neither). | Extract a `services/FieldsService` owning `add()/update()/delete()/reorder()` (own the transaction + cache invalidation). Controller keeps request parsing / `validateFieldInput` / JSON envelope; FieldOps keeps its MCP arg schema. Both delegate the DB writes. | **HIGH** (value); **MED** (effort) | Medium. Two real deltas to fold: (a) controller's `supportedSiteIds($formId, $postedSiteId)` fallback vs FieldOps' `supportedSiteIds($formId)`; (b) controller catches `\Exception`+`asJsonError`, FieldOps re-throws. Service takes `?int $fallbackSiteId`, owns the transaction, re-throws; each caller maps errors. Verify with `SettingsTabsRenderTest` + MCP smoke Cests. |
| **R2** | `services/FormCloneService.php:211-267`; `services/FieldSyncService.php:403-504`; `services/FormPortabilityService.php:158-168` | Byte-identical `$transaction = $db->beginTransaction(); try { …; $transaction->commit(); } catch (\Throwable $e) { $transaction->rollBack(); throw $e; }` with uniform re-throw semantics. | 3 hand-rolled rollback blocks; rollback-on-throw is easy to get subtly wrong (miss the rollBack, swallow the wrong type). | `private function withTransaction(callable $work): mixed` on a small `services` trait (commit on success, `rollBack()+rethrow` on `\Throwable`). Replace each block; keep post-commit work (audit log, return) outside the callback so commit ordering is unchanged. | **HIGH** | Low. Re-throw is uniform across all three → the helper is exact, no behavior change. **Do NOT fold in `FieldsController`** (it catches `\Exception`, logs, and `return $this->asJsonError(...)` — a different contract; absorb it via R1 instead). |
| **R3** | `services/FormPortabilityService.php:424-431` (`exportFormContent`), `:738-746` (`applyFormContent`) vs the single-source `helpers/FormContentHelper::CONTENT_ATTRS` (already iterated by `FormCloneService::contentFrom():473-481` / `applyContent():488-495`) | The canonical 6-attr content list `['title','description','emailTo','emailSubject','emailReplyTo','emailBody']` is hand-spelled twice in FormPortabilityService instead of looping `FormContentHelper::CONTENT_ATTRS`. The #226 export work re-introduced the exact divergence the prior delta closed for clone. | Adding a 7th content column now requires editing the helper const **and** two hand-spelled blocks in portability — and `exportFormContent` would silently omit it. Defeats the single-source-of-truth the helper exists to provide. | Reuse `FormContentHelper::CONTENT_ATTRS` in both: in `exportFormContent` loop the const onto the array; in `applyFormContent` loop with the existing `title ?? name` fallback preserved as a special case (it is genuine portability-only behavior). | **HIGH** | Low. The one real difference is `applyFormContent`'s `title = content['title'] ?? form->name` fallback and per-key casts — keep that fallback; loop the *other 5*, or add a tiny `extract/apply` helper to FormContentHelper that takes a title-fallback. Pure schema-list reuse; no behavior change. |
| **R4** | `console/controllers/FormsController.php:84-88` (export), `:182` (apply); `console/controllers/SubmissionsController.php:111-118` (`resolveFormId`) | `Form::find()->handle($h)->siteId('*')[->status(null)]->one()` + identical `stderr("No form found with handle \"…\".")` envelope. Now 3 sites (the new `forms/apply` added one). | Same form-resolution + error string maintained in two console controllers; `forms/apply` re-spells the query a third time. | A `console/BaseFormCommand` or trait with `resolveFormByHandle(string): ?Form` (always `->status(null)` as the safe default). Each caller maps the `null` to its own exit code (`DATAERR` vs `false`). | **MED** | Low. The two callers return different sentinels (`ExitCode::DATAERR` vs `int|null|false`) — the helper returns `?Form` only; callers keep their own error mapping. Confirm `->status(null)` is acceptable for both (export already uses it; SubmissionsController currently omits it — adding it only *widens* matches to disabled forms, which is the intended console behavior). |
| **R5** | `services/FormPortabilityService.php:280-290` (applyToExistingForm), `:879-889` (importFields); cf. similar shapes at `:693-707` (createForm), `:315-329` (updateFormLevel) | "For each supported site that isn't the canonical one: `getSiteById`, null-skip, do per-site work" — the `array_filter(supportedSiteIds, fn != canonical)` + loop scaffold appears twice near-identically (and twice more with different bodies). | Mild repetition of the sibling-site iteration scaffold within one service. | Optionally a `private function eachSiblingSite(Form $form, callable $fn): void` that yields `(int $siteId, Site $site)` for non-canonical supported sites. | **LOW** | Low-medium. Only the 280/879 pair is truly identical (both call `overlaySiteContent`); 693 and 315 differ (one seeds content on createForm, one saves sibling Form rows). A callback helper would cover only 2 of 4 and adds an indirection for a 4-line loop. Marginal — do only if touching this file for R2/R3. |
| **R6** | `console/controllers/FormsController.php:170-180` (apply), `:231-239` (status) | `$decoded = json_decode(file_get_contents($file)); $handle = is_array($decoded) && is_array($decoded['form'] ?? null) ? trim((string)($decoded['form']['handle'] ?? '')) : '';` repeated verbatim. | "Decode a forms-as-code file and pull its form handle" is spelled twice in the same controller. | A `private function readFormHandle(string $file): array{0:?array,1:string}` (or return `[$decoded, $handle]`) reused by `actionApply` and `actionStatus`. | **LOW** | Trivial / none. Same-file private extraction, ~6 LOC saved. Do only while in this file. |

---

## 3. Things that LOOK duplicated but should STAY

- **`src/events/Register*Event` (4 classes)** — single-`public array` Yii data-carrier events. Required idiom; a base class obscures the property name/type and saves nothing. (Same stance the prior delta took on the registries.)
- **`src/events/Before*Event` / `Modify*Event` / `DefineFieldSetEvent`** — each carries a *different* mutable payload (recipients+send / settings+send / valuesByHandle / fieldSet). No shared shape to extract.
- **The 13 GraphQL ObjectTypes + `GqlEntityRegistry::getOrCreate` skeleton** — Craft framework contract, already based on `SimpleFormObjectType`. Verified by the GraphQL sweep: keep per-type.
- **`FormGqlResolver::mapField()` vs `FormPresenter::fields()`** — published schema (rich computed keys, `name` key, config curated out) vs MCP tool output (`handle` key, raw config). Different consumers/contracts; merging is leaky. Confirms prior M1 — extract nothing.
- **`FormGqlResolver::stringOrNull/intOrNull/floatOrNull`** (`:258-271`) vs `NotificationsController::nullableString` (`:186`) — small, private, divergent (the GQL set is numeric-aware). Not worth a cross-subsystem helper.
- **`examples/*`** — standalone teaching scaffolds; self-containment is the design.
- **The three Registries** (`FieldTypeRegistry`, `IntegrationTypeRegistry`, `CaptchaProviderRegistry`) — even after the new event epilogue was added, they still diverge on event presence/name, instantiation signature (`new $class($config)` vs `new $class()`), and the OPTION/SCALE/RELATION/ASSET taxonomy consts FieldTypeRegistry uniquely owns. A shared base needs generics PHP can't express. Keep separate (prior §3/§1 stance holds).
- **`MakeController::handleFromClass()/labelFromClass()`** — private, local, used only by the two `make/*` actions.
- **Timestamp idiom** (`date('Y-m-d H:i:s')` at `FieldsController`, `FieldOps`, `FieldSyncService:383`, `FormPortabilityService:901` vs `Db::prepareDateForDb()` elsewhere) — a *consistency/TZ-correctness* nudge, **not** dedup; defer to the standards pass. R1/R2 will absorb most of these sites incidentally.
- **Submission "find by id or 404"** in controllers — siteId scoping is genuinely inconsistent across call sites (`->siteId($siteId)` vs `'*'` vs none); a shared helper would silently change cross-site resolution. Skip (prior M2/delta stance).

---

## 4. HIGH-CONFIDENCE RECOMMENDATIONS

Three items are safe and net-simplifying. Implement in this order; each is independently shippable with its named tests.

### HC-1 — Reuse `FormContentHelper::CONTENT_ATTRS` in `FormPortabilityService` (was R3)

**Target files/methods:**
- `src/services/FormPortabilityService.php` → `exportFormContent()` (lines 408-435, content block 424-431)
- `src/services/FormPortabilityService.php` → `applyFormContent()` (lines 738-746)

**Exact change:**
1. In `exportFormContent()`, replace the hand-spelled associative array (lines 424-431):
   ```php
   $content[$site->handle] = [
       'title' => $form->title, 'description' => $form->description,
       'emailTo' => $form->emailTo, 'emailSubject' => $form->emailSubject,
       'emailReplyTo' => $form->emailReplyTo, 'emailBody' => $form->emailBody,
   ];
   ```
   with a loop over `FormContentHelper::CONTENT_ATTRS`:
   ```php
   $row = [];
   foreach (FormContentHelper::CONTENT_ATTRS as $attr) {
       $row[$attr] = $form->$attr;
   }
   $content[$site->handle] = $row;
   ```
2. In `applyFormContent()` (lines 738-746), keep the `title ?? name` fallback as a special case, then loop the **remaining** attrs:
   ```php
   $form->title = isset($content['title']) ? (string)$content['title'] : $form->name;
   foreach (FormContentHelper::CONTENT_ATTRS as $attr) {
       if ($attr === 'title') { continue; }
       $form->$attr = $content[$attr] ?? null;
   }
   ```
   (`FormContentHelper` is already imported at line 12.)

**Why safe:** Pure schema-list reuse; the only behavioral nuance — portability's `title ?? $form->name` fallback — is explicitly preserved. Brings portability in line with `FormCloneService::contentFrom()/applyContent()`, which already loop the const. Restores the single source of truth that #226 broke.

**Verify:** `FullFidelityExportTest`, `IdStableApplyTest`, `ConfigApplyTest`, `ApplyEdgeCasesTest` (all integration). PHPStan/ECS unaffected.

---

### HC-2 — `withTransaction()` trait for the 3 identical service transaction shells (was R2 / prior H2 / delta P1)

**Target files:**
- `src/services/FormCloneService.php:211-267`
- `src/services/FieldSyncService.php:403-504`
- `src/services/FormPortabilityService.php:158-168`

**Exact change:** Add a `services` trait (e.g. `src/services/concerns/TransactionTrait.php`) with:
```php
protected function withTransaction(callable $work): mixed
{
    $transaction = Craft::$app->getDb()->beginTransaction();
    try {
        $result = $work();
        $transaction->commit();
        return $result;
    } catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
    }
}
```
Replace each of the three `beginTransaction/try/commit/catch{rollBack;throw}` blocks with `$result = $this->withTransaction(fn() => …);`, moving any post-commit work (FormPortability's `Audit::log` at :171, FormClone's return value) **outside** the callback so commit ordering is unchanged.

**Why safe:** All three shells re-throw uniformly on `\Throwable` → the helper is byte-exact, zero behavior change. Removes 3 error-prone rollback blocks.

**Do NOT touch** `FieldsController` here — its blocks catch `\Exception`, log a warning, and `return $this->asJsonError(...)` (a different contract). It is absorbed by HC-3 instead.

**Verify:** `IdStableApplyTest`, `ConfigApplyTest`, plus the existing clone/sync unit + integration tests. PHPStan L7 stays green (callable return is `mixed`).

---

### HC-3 — Extract `services/FieldsService` to end the FieldsController↔FieldOps mirror (was R1 / prior H1)

> Highest value, biggest effort of the three. Ship on its own PR with the MCP smoke Cests. If time-boxed, do HC-1/HC-2 first (small, independent) and schedule HC-3.

**Target files:**
- `src/controllers/FieldsController.php` → `actionAdd` (53-95), `actionEdit` (125-161), `actionDelete` (163-186), `actionReorder` (205-…), `supportedSiteIds` (private helper)
- `src/mcp/tools/support/FieldOps.php` → `add` (179-), `update` (230-), `delete` (262-), `reorder` (273-), `supportedSiteIds` (315-)

**Exact consolidation:** Create `src/services/FieldsService.php` owning the DB writes:
- `add(int $formId, string $type, string $handle, bool $required, array $config, string $label, ?string $helpText, ?int $fallbackSiteId): int` — `maxSort+1`, structural insert + per-site seed rows, `getFormStructure()->invalidate($formId)`; runs inside `withTransaction()` (HC-2).
- `update(int $fieldId, …): void`, `delete(int $fieldId): void`, `reorder(int $formId, array $order): void` — same bodies the two paths share today.
- `supportedSiteIds(int $formId, ?int $fallbackSiteId = null): array` — the union of both callers (the `?int` fallback is the controller's posted-site behavior; null = FieldOps' behavior).

Then:
- `FieldsController` actions keep request parsing + `validateFieldInput` + the `asJson*` envelope, and call `$this->fieldsService()->add(...)` etc. The controller's `try/catch(\Exception) → asJsonError` wrapper stays at the controller boundary.
- `FieldOps` keeps its MCP arg schema/authz and delegates to the same service; remove the "deliberately mirrors" docblocks.

**Why safe (with care):** The two write paths are already nearly byte-identical; the service makes the shared logic literally one implementation, killing the drift hazard. Two deltas must be handled explicitly: (a) the `?int $fallbackSiteId` parameter, (b) transaction ownership moves into the service (controller's error envelope wraps the call). 

**Verify:** `SettingsTabsRenderTest`, the MCP smoke Cests (`tests/smoke/*` field paths), and the field add/edit/delete/reorder integration coverage. Confirm PHPStan L7 + ECS stay green.

---

## Counts

- **HIGH:** 3 (HC-1 content-attr reuse, HC-2 `withTransaction` trait, HC-3 FieldsService) — table rows R1–R3.
- **MEDIUM:** 1 (R4 console form-by-handle resolver).
- **LOW:** 2 (R5 sibling-site loop, R6 console decode+handle).

The new code (forms-as-code, make/*, GraphQL, events, examples) is DRY-clean on its own; the only new regression is HC-1 (#226 re-spelled the content list). The two remaining structural items (HC-2, HC-3) are prior-report HIGH recommendations that were never implemented and still stand.
