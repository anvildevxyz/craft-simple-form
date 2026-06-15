# Concern #4 — Circular Dependencies (Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Scope:** `src/` — 72 PHP files
**Mode:** Phase 1, assessment only. No source files were edited.
**Date:** 2026-06-14

---

## Summary

**No harmful circular dependencies exist.** HIGH items: **0**.

I built the class-level dependency graph from `use` statements across all 72
`src/` files (146 import edges) and ran Tarjan's strongly-connected-components
(SCC) algorithm. The tool reports a single large SCC of 15 classes — but this is
an **artifact of the `Plugin` class acting as a service-locator hub**, not a real
import cycle. Once `Plugin` is removed from the graph, the 15-node SCC dissolves
completely, leaving only two two-node pairs:

- `Form` ↔ `FormQuery`
- `Submission` ↔ `SubmissionQuery`

Both of these are the **canonical Craft/Yii element pattern** and one direction of
each pair is a **PHPDoc-only reference** (generics in `@extends`/`@method`), not a
runtime edge. They are benign and idiomatic; breaking them would fight the
framework and reduce clarity.

There is therefore nothing to fix. This report documents the method, the graph,
and *why* each apparent cycle is safe, so the conclusion can be re-derived without
re-running the analysis.

---

## Method

No cycle-detection tool (madge is JS; no `deptrac`/`phpat`/`composer-require-checker`)
is present in `composer.json`/`composer.lock`, and `composer require --dev` is
disallowed. I derived the graph manually:

1. Parsed every `src/**/*.php` for its declared `namespace` + type name to map
   FQCN → file.
2. Extracted edges from `use fabianhaef\simpleform\…;` statements (intra-plugin
   imports only; framework/vendor edges ignored).
3. Ran Tarjan's SCC to find cycles, plus an explicit mutual-pair (`A→B ∧ B→A`)
   scan and a self-loop scan.
4. Re-ran SCC with the `Plugin` node deleted to isolate hub-induced "cycles" from
   genuine structural ones.
5. For every remaining edge in a cycle, inspected the actual source to classify it
   as **service-locator call**, **PHPDoc generic**, or **real construction-time
   coupling**.

`use`-statement parsing slightly *over*-counts edges (it cannot tell a docblock
generic from a constructor type-hint), which is conservative for cycle hunting:
if even the over-counted graph has no harmful cycle, the real one certainly does
not.

---

## Findings

### Finding 1 — The 15-node SCC is a `Plugin` service-locator hub (LOW / benign)

Tarjan reports one SCC of 15 classes:

```
FormModel, SubmissionEvent, SubmissionService, FormStructureService,
SubmissionQuery, Submission, EmailService, CaptchaService, TokenManager,
FormGqlResolver, FormQueries, FormQuery, Form, FormMutations, Plugin
```

The cycle exists only because many leaf classes reach back to `Plugin` via Craft's
service locator, while `Plugin` imports all of them to register/wire them. The
back-edges into `Plugin` are **not** construction dependencies — every one is a
runtime `Plugin::getInstance()->getX()` lookup:

| Edge (`A → Plugin`)                          | Reference (`file:line`)                  | Kind |
|----------------------------------------------|------------------------------------------|------|
| `services\CaptchaService → Plugin`           | `src/services/CaptchaService.php:7`      | use → `Plugin::getInstance()->getSettings()` (`:83`) |
| `services\EmailService → Plugin`             | `src/services/EmailService.php:10`       | use → `Plugin::getInstance()->getSettings()` (`:59`) |
| `services\FormStructureService → Plugin`     | `src/services/FormStructureService.php:8`| use → `Plugin::getInstance()->getSettings()` (`:178`) |
| `services\SubmissionService → Plugin`        | `src/services/SubmissionService.php:11`  | use → `Plugin::getInstance()->getSettings()/getCaptchaService()/getEmailService()/trigger()` (`:86,98,140,148,152`) |
| `mcp\TokenManager → Plugin`                  | `src/mcp/TokenManager.php:8`             | use → `Plugin::getInstance()->getSettings()` (`:51,200`) |
| `gql\mutations\FormMutations → Plugin`       | `src/gql/mutations/FormMutations.php:11` | use → `Plugin::getInstance()->getSubmissionService()->submit()` (`:111`) |

`Plugin`'s outgoing side is the registration hub: it imports its services, GQL
types, MCP token manager, settings model, and permission helper purely to wire
them up (component config, GQL/permission registration). This is the **intended
shape of a Yii/Craft module** and the documented MEMORY convention
(`Plugin::getInstance()->service`).

**Proof it is hub-induced, not structural:** deleting the `Plugin` node from the
graph and re-running SCC yields **no** 15-node cycle — it collapses entirely to the
two element/query pairs below.

**Harm:** none in practice. Service-locator access is lazy and runtime; there is no
class-initialization ordering problem (Craft instantiates `Plugin` first, then
resolves services on demand), and the services are independently testable by
stubbing the locator. **Classification: LOW / benign — no action.**

> Optional, *not recommended* hardening: constructor-inject `Settings` into the
> services instead of `Plugin::getInstance()->getSettings()`. This would shrink the
> hub but diverges from Craft idiom, complicates Craft's component instantiation,
> and buys little. Leave as-is.

### Finding 2 — `Form` ↔ `FormQuery` (LOW / idiomatic Craft, half PHPDoc)

| Edge | `file:line` | Nature |
|------|-------------|--------|
| `Form → FormQuery`   | `src/elements/Form.php:9` (use) | Real runtime: `find(): FormQuery { return new FormQuery(static::class); }` (`:53–55`) |
| `FormQuery → Form`   | `src/elements/db/FormQuery.php:8` (use) | **PHPDoc only**: `@extends ElementQuery<int, Form>`, `@method Form[] all()`, etc. (`:11–15`) |

This is the mandatory Craft element ⇄ element-query relationship: every custom
element returns its query from `static::find()`, and the query is generically typed
on its element for IDE/Psalm support. The `FormQuery → Form` direction carries **no
executable coupling** — strip the docblock and the edge vanishes. **Classification:
LOW / benign — no action.**

### Finding 3 — `Submission` ↔ `SubmissionQuery` (LOW / idiomatic Craft, half PHPDoc)

| Edge | `file:line` | Nature |
|------|-------------|--------|
| `Submission → SubmissionQuery` | `src/elements/Submission.php:10` (use) | Real runtime: `find()` returns the query |
| `SubmissionQuery → Submission` | `src/elements/db/SubmissionQuery.php:8` (use) | **PHPDoc only**: `@extends ElementQuery<int, Submission>`, `@method Submission[] all()` (`:11–15`) |

Identical pattern to Finding 2. **Classification: LOW / benign — no action.**

### Other structural checks (no cycle)

- **Service → service construction:** the only `new …Service()` in non-test code is
  `new FieldSyncService()` in `src/controllers/FormsController.php:113` — a
  one-directional instantiation of a stateless helper. No back-edge.
- **`SubmissionEvent`:** flows one way only —
  `SubmissionService → SubmissionEvent → {Form, Submission}`. Its only importer is
  `SubmissionService`; it imports no service. Not part of any real cycle.
- **Self-loops:** none.

---

## Proposed breaks + confidence

| # | Cycle | Verdict | Break | Confidence |
|---|-------|---------|-------|------------|
| 1 | 15-node `Plugin` hub | Benign (service-locator) | **None.** Idiomatic Craft; optional DI hardening is not worth it. | High |
| 2 | `Form` ↔ `FormQuery` | Benign (Craft element pattern; back-edge is PHPDoc) | **None.** | High |
| 3 | `Submission` ↔ `SubmissionQuery` | Benign (same) | **None.** | High |

---

## High-confidence implementation checklist

**Empty — no harmful cycles found, no changes recommended.**

All detected cycles are either (a) the Craft/Yii service-locator hub centered on
`Plugin`, or (b) the mandatory element ⇄ element-query pairing whose reverse edge
is a PHPDoc generic. None cause initialization-ordering fragility, none impede
testing (services stub the locator), and none harm comprehension beyond what the
framework already imposes. Breaking any of them would add code, diverge from Craft
convention, and provide no benefit.

If a guardrail is ever desired, the cheap option is a dev-only `deptrac` ruleset
that *allows* `elements → elements\db`, `elements\db → elements`, and `* → Plugin`,
and flags anything new — but that is a tooling addition, out of scope for this
assessment.
