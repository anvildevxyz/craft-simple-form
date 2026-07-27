# Delta 01 — DRY / De-duplication

**Scope:** PHP changed since `c5b8fe7` (delta list), excluding WIP files (FormsController, Form.php, FormQuery.php, FormRenderService.php, templates/, tests/).
**Method:** 3 parallel read-only sweeps (services / controllers / helpers+fields) + first-party verification of each site. Cross-checked against prior report `docs/cleanup/01-dry-dedup.md`.

---

## 1. Critical assessment

The delta has **closed most of the prior report's high-value DRY items** — this pass should NOT re-file them:

- **H3 / H4 / H5 (RESOLVED).** The byte-identical `handleExists()`, `fieldIdsByHandle()`, and the form-content attribute list now live in a new `helpers/FormContentHelper` (`CONTENT_ATTRS` const + the two static queries). `FormCloneService` and `FormPortabilityService` both delegate. Single source of truth — done.
- **H1 FieldsService / FieldOps mirror** — out of scope here (the CP write path lives in FieldsController, which is in the delta, but the MCP `FieldOps` side is unchanged and this is a large structural extraction, not a clean gate-safe dedup). Not re-filed.
- **Registries** (`FieldTypeRegistry`, `IntegrationTypeRegistry`, `CaptchaProviderRegistry`) look parallel and the docblocks even say "Mirrors …", but they diverge in load-bearing ways: FieldTypeRegistry fires **no** registration event, skips the `is_subclass_of` guard, keys by `getType()` not `handle()`, instantiates **with `$config`**, and owns the OPTION/SCALE/RELATION taxonomy consts. A shared base would need callbacks + generics PHP can't express and would read worse. **Keep separate** (matches prior §3 stance).

What genuinely remains:

1. **Transaction boilerplate** — byte-identical `beginTransaction / try / commit / catch(\Throwable){ rollBack; throw }` in **3 services**. A one-method `withTransaction()` helper removes 3 hand-rolled rollback blocks (easy to get subtly wrong). This is the one real, gate-safe consolidation in the delta.
2. **`['file','signature']` asset-type list** duplicated between `SubmissionCsv::ASSET_TYPES` (private const) and an inline literal in `RetentionService`. One shared const closes it.

Everything else flagged by the sweeps is coincidental similarity with divergent, load-bearing bodies (scalar-ization, URL-scheme checks, config-list parsing, numeric-bound parsing, submission-lookup) — consolidating any of them adds indirection without reducing real complexity. Catalogued in §4.

---

## 2. High-confidence patches

### P1 — `withTransaction()` helper for the 3 identical service transaction shells *(High)*

**Sites (byte-identical shell):**
- `services/FormCloneService.php:211-267` (`$db->beginTransaction()` … `catch (\Throwable $e) { $transaction->rollBack(); throw $e; }`)
- `services/FieldSyncService.php:403-504` (same)
- `services/FormPortabilityService.php:134-144` (same, but opens with `Craft::$app->getDb()->beginTransaction()` inline)

**Problem:** The exact wrapper

```php
$transaction = $db->beginTransaction();
try {
    // …work…
    $transaction->commit();
} catch (\Throwable $e) {
    $transaction->rollBack();
    throw $e;
}
```

is hand-rolled in three services with uniform re-throw semantics. Rollback-on-throw is easy to get wrong (forget the rollBack, swallow the exception, catch the wrong type).

**Proposed change:** Add a small shared seam — a `private function withTransaction(callable $work): mixed` (on a `services` trait, or a static `Db`-style helper) that runs `$work`, commits on success, and `rollBack()+rethrow` on `\Throwable`. Replace each of the three blocks with `$result = $this->withTransaction(fn() => …);`. Keep each method's post-commit work (e.g. FormPortability's `Audit::log`, FormClone's return) **outside** the callback so commit ordering is unchanged.

**Justification:** Removes 3 duplicated rollback blocks; uniform re-throw makes the shared helper exact (no behavior change) and gate-safe.

**Do NOT fold in** `FieldsController` (lines 53-94, 125-160, 241-254): its transaction blocks catch `\Exception` (not `\Throwable`), log `Craft::warning`, and `return $this->asJsonError(...)` — a different contract. Forcing it into the same helper would change error handling. Leave it (or give it a separate controller-side variant in a later pass — not this one).

---

### P2 — De-duplicate the `['file','signature']` asset-type list *(High)*

**Sites:**
- `helpers/SubmissionCsv.php:40` — `private const ASSET_TYPES = ['file', 'signature'];` (used at :155/:198/:250)
- `services/RetentionService.php:157` — `!in_array($entry['type'] ?? null, ['file', 'signature'], true)` (inline literal)

**Problem:** Two places independently encode "field types that store asset ids." Adding a third asset-bearing type means editing both, in separate subsystems (CSV export and retention/GC).

**Proposed change (preferred):** Add a `public const ASSET_TYPES = ['file', 'signature'];` to `FieldTypeRegistry` (it already owns the `OPTION_TYPES` / `SCALE_TYPES` / `RELATION_TYPES` taxonomy consts — this is its natural home). Have `SubmissionCsv` and `RetentionService:157` both reference `FieldTypeRegistry::ASSET_TYPES`.

**Simpler variant (if you don't want to touch the registry):** promote `SubmissionCsv::ASSET_TYPES` from `private` to `public` and change `RetentionService:157` to `!in_array($entry['type'] ?? null, SubmissionCsv::ASSET_TYPES, true)`. Note: this is a 2-edit change (visibility + reference), not a one-liner, because the const is currently `private`. It also couples a GC service to a CSV-export helper — the registry home reads cleaner.

**Justification:** Single source of truth for the asset-type set; pure const reference, zero behavior change, gate-safe.

---

## 3. LOW-confidence / skip

- **M3 timestamp idiom (LOW — consistency, not dedup).** `FieldSyncService.php:383` and `FormPortabilityService.php:557` use raw `date('Y-m-d H:i:s')`; the rest of the codebase standardized on `Db::prepareDateForDb(new \DateTime())` (FormCloneService:418/540, IntegrationsService:128/159/364, NotificationsService:56, etc.). `FieldsController.php:45` also uses `date()`. Flipping these is a consistency/correctness nudge (TZ-correctness), **not** duplication removal, and is a possible behavior change if app-TZ ≠ DB-TZ. Out of this concern's scope — defer to the standards/consistency pass.
- **Submission "find by id or 404" (SKIP — leaky-scoping).** `SubmissionsController.php:190/243/284(/332)` (`->siteId($siteId)`), `SubmissionEditController.php:138` (`->siteId('*')`), `IntegrationsController.php:216` (no site). The siteId scoping is genuinely inconsistent across call sites; a shared `getSubmissionOrFail()` helper would silently change cross-site resolution, and the error envelopes split (`throw` vs `asJsonError`). Net saving is one query line. Skip — same caveat as prior M2.
- **`actionDelete` / `actionToggle` near-twins** (NotificationsController vs IntegrationsController): same POST+JSON shell but one delegates to a service `delete()/toggle()`, the other hand-flips the boolean and `save()`s. Different service contracts; merging needs callbacks. Skip.
- **`scalar()` / `scalarString()` / `exportValue()`** (SubmissionCsv:325 / ConditionalEvaluator:285 / FieldType:86): identical only on the `null/false/true` preamble (~3 lines); array handling diverges hard (consent/repeater/formula-neutralize vs `''` vs pipe-join). Load-bearing divergence — prior §3 confirms keep separate. Skip.
- **URL-scheme `['http','https']`** (ConsentText:107 `isSafeUrl` vs SafeUrl:240 `isHttp`): different threat models (lightweight link check vs SSRF guard) and signatures; the duplicated token is a 2-element literal. Coupling them pulls DNS/IP logic where it isn't wanted. Skip (prior §3 stance).
- **Config-list parsing** (FileFieldType `allowedExtensions` :37 vs PhoneFieldType `allowedCountries` :79): same `is_string→preg_split→loop→unique` skeleton but divergent normalization (lowercase + strip leading `.` vs uppercase + `DialCodes::isKnown()` validation). A shared helper needs a normalize+validate callback and reads worse. Skip.
- **Numeric-bound parsing** (PhoneFieldType min/maxDigits vs RepeaterFieldType min/maxRows): surface-similar, but Repeater clamps + enforces `min=1 when required`, Phone validates `is_numeric && >0`. Different business rules. Skip.
- **Registries** (FieldTypeRegistry / IntegrationTypeRegistry / CaptchaProviderRegistry): structural-by-design, diverge on event presence + `is_subclass_of` + instantiation signature. Skip (see §1).
- **New migrations** (`m260622_000001`, `m260622_000002`) and `FormStructureService`: self-contained, no cross-file duplication. Clean.

---

## Verdict

**2 high-confidence patches** (P1 `withTransaction()` across 3 services; P2 shared `['file','signature']` const). Everything else in the delta is either already de-duplicated (FormContentHelper) or coincidental similarity with load-bearing divergence — skip.
