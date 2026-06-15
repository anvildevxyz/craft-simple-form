# Concern #4 — Circular Dependencies (Round 2 Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Scope (this round):** the NEW MCP "insight tools" + "resources" feature —
`src/mcp/tools/{DetectSpamPatternsTool,CategorizeSubmissionsTool,SummarizeSubmissionsTool}.php`,
`src/mcp/tools/support/InsightCorpus.php`,
`src/mcp/resources/{ResourceProviderInterface,FormSchemaResource,SubmissionsDatasetResource}.php`,
and `src/mcp/McpServer.php` — plus their immediate neighbours.
**Mode:** Phase 1, assessment only. No source files were edited.
**Date:** 2026-06-15

---

## Summary

**No harmful circular dependencies exist in the new MCP feature. HIGH items: 0.**

I rebuilt the class-level dependency graph for the new files (and every node they
touch) from `use` statements, static `::class`/`::method()` references, and
`Plugin::getInstance()->getX()` lookups, then checked for cycles. The new
subgraph is a strict **DAG**: every edge points "downstream"
(`McpServer → tools/resources → support helpers → elements`), and **no node in
the new feature is imported back by anything it depends on.** Elements
(`Form`/`Submission`) — the sinks — import **zero** `mcp` classes, so the dispatch
layer cannot close a loop through them.

The only cycles anywhere in the codebase remain the two benign classes already
documented in round 1 (commit a56e6ed, `docs/cleanup/04-circular-deps.md`): the
`Plugin` service-locator hub and the Craft element ⇄ element-query PHPDoc pair.
The new MCP code participates in the `Plugin` hub only via the same benign
runtime `Plugin::getInstance()->version` lookup pattern (one read, in
`McpServer::initializeResult()`), introducing **no new structural edge**.

There is nothing to fix. The checklist is intentionally empty.

---

## Method

Same method as round 1 (no `deptrac`/`madge`/`phpat` available, `composer
require --dev` disallowed), applied to the new files:

1. For each new file, extracted every intra-plugin edge from:
   - `use fabianhaef\simpleform\…;` imports,
   - static references (`QuerySubmissionsTool::filterProperties()`,
     `InsightCorpus::fieldTypes()`, `SubmissionQueryBuilder::build()`, etc.),
   - `::class` / `new …()` instantiations,
   - `Plugin::getInstance()->…` service-locator reads.
2. Grepped for **back-edges**: does anything the new files depend on (support
   helpers, elements, `Scopes`, `ToolInterface`) `use`/reference a tool, a
   resource, or `McpServer`? (A back-edge is the only way to form a cycle here.)
3. Classified each edge as **import/construction-time**, **static-call**,
   **PHPDoc-only**, or **runtime service-locator**.
4. Reused round 1's benign-edge classification for `Plugin` hub and element ⇄
   query; did not re-litigate those.

`use`-parsing over-counts (it can't tell a docblock generic from a constructor
hint), which is conservative for cycle hunting: if the over-counted graph is
acyclic, the real one is too.

---

## Dependency graph (new feature + neighbours)

Edges (intra-plugin only; `→` = "depends on"). All are construction-time imports
or static calls unless noted.

```
McpServer
  → tools\{ListForms,GetForm,CreateForm,UpdateForm,DeleteForm,AddField,
           UpdateField,ReorderFields,DeleteField,QuerySubmissions,GetSubmission,
           ExportSubmissions,SubmissionStats,
           SummarizeSubmissions,Categorize,DetectSpamPatterns}Tool   (new: last 3)
  → tools\ToolInterface
  → resources\{ResourceProviderInterface,FormSchemaResource,SubmissionsDatasetResource}
  → McpToken            (type-hint)
  → Plugin              (runtime: Plugin::getInstance()->version — service-locator, benign)

SummarizeSubmissionsTool / CategorizeSubmissionsTool / DetectSpamPatternsTool
  → tools\ToolInterface                          (implements)
  → tools\QuerySubmissionsTool                   (static ::filterProperties())
  → tools\support\SubmissionQueryBuilder         (static ::build/::applyFieldMatch)
  → tools\support\InsightCorpus                  (static ::fieldTypes/::freeTextHandles/::textValues)
  → mcp\Scopes                                   (const)
  → elements\Form, elements\Submission, elements\db\SubmissionQuery

QuerySubmissionsTool
  → elements\db\SubmissionQuery, mcp\Scopes, tools\support\SubmissionQueryBuilder

tools\support\InsightCorpus        → elements\Form, elements\Submission        [SINK-ward]
tools\support\SubmissionQueryBuilder → elements\Form, elements\Submission, elements\db\SubmissionQuery
tools\support\FormPresenter        → elements\Form

resources\FormSchemaResource       → ResourceProviderInterface, mcp\Scopes,
                                     tools\support\FormPresenter, elements\Form
resources\SubmissionsDatasetResource → ResourceProviderInterface, mcp\Scopes,
                                     tools\support\SubmissionQueryBuilder,
                                     elements\Form, elements\Submission

mcp\Scopes                         → (no intra-plugin imports)
tools\ToolInterface                → (no intra-plugin imports)
resources\ResourceProviderInterface → (no intra-plugin imports)
elements\Form / Submission          → (NO mcp imports — verified)
```

**Topological order exists** (a valid linearisation):

```
Scopes, ToolInterface, ResourceProviderInterface, McpToken,
elements\{Form, Submission, db\SubmissionQuery},
support\{InsightCorpus, SubmissionQueryBuilder, FormPresenter},
QuerySubmissionsTool, {Summarize,Categorize,DetectSpamPatterns}Tool,
{FormSchema,SubmissionsDataset}Resource,
McpServer
```

Because a topological order exists, the new subgraph has **no cycle**.

---

## Findings

### Finding 1 — Insight tools ↔ InsightCorpus: one-directional, no cycle (LOW / clean)

`InsightCorpus` is a **stateless static utility** (`final class`, all `public
static` methods) imported by all three new tools. It imports only the two
elements and **no tool, no `McpServer`, no resource**
(`src/mcp/tools/support/InsightCorpus.php:5-6`). It is a leaf/sink. The three
tools each call `InsightCorpus::fieldTypes()` / `::freeTextHandles()` /
`::textValues()` and `OPTION_TYPES`:

- `DetectSpamPatternsTool.php:9` (`use`), :103,:110 (calls)
- `CategorizeSubmissionsTool.php:9` (`use`), :79,:80,:92,:161 (calls)
- `SummarizeSubmissionsTool.php:8` (`use`), :86,:122 (calls)

No return path from `InsightCorpus` back to any tool. **No cycle.**

### Finding 2 — Insight tools ↔ McpServer: one-directional, no cycle (LOW / clean)

`McpServer::tools()` instantiates the three new tools
(`McpServer.php:78-80`; `use` :22,:10,:14). The tools do **not** import or
reference `McpServer` — the only `McpServer` mentions in the tool/support/
resource tree are `{@see …McpServer}` **docblock** references in `ToolInterface`,
`ResourceProviderInterface`, `Scopes`, `McpToken` (PHPDoc-only, no executable
edge). The only `new McpServer()` is in `controllers/McpController.php:104`,
outside this subgraph. **No back-edge → no cycle.**

### Finding 3 — Resource providers ↔ McpServer ↔ support: one-directional, no cycle (LOW / clean)

`McpServer::resourceProviders()` builds `FormSchemaResource` +
`SubmissionsDatasetResource` (`McpServer.php:94-96`; `use` :6-8). The resources
import `ResourceProviderInterface`, `Scopes`, and the support presenters
(`FormSchemaResource.php:5-7`, `SubmissionsDatasetResource.php:5-8`) — the same
`FormPresenter` / `SubmissionQueryBuilder` the tool layer uses, **by design** (so
a resource and the equivalent tool agree on the schema). Those support classes
import only elements and never a resource or `McpServer`. Flow is strictly
`McpServer → Resource → support → elements`. **No cycle.**

### Finding 4 — Insight tool ↔ tool coupling (`QuerySubmissionsTool::filterProperties()`): one-directional, no cycle (LOW / clean)

All three new tools statically call `QuerySubmissionsTool::filterProperties()` to
reuse the shared filter input-schema:

- `DetectSpamPatternsTool.php:64`, `CategorizeSubmissionsTool.php:44`,
  `SummarizeSubmissionsTool.php:44`.

`QuerySubmissionsTool` imports `SubmissionQuery`, `Scopes`, and
`SubmissionQueryBuilder` only (verified) — it does **not** import any of the
three insight tools. This is a one-way "reuse the sibling's static schema" edge,
not a mutual dependency. **No tool ↔ tool cycle.**

### Finding 5 — Tool/resource ↔ element coupling: one-directional, no cycle (LOW / idiomatic)

Tools and resources call `Form::find()` / `Submission::find()` and type-hint the
elements / `SubmissionQuery`. **Elements import no `mcp` class** (verified:
`grep -rn mcp src/elements` → none), so this edge cannot close into a loop. The
element ⇄ element-query PHPDoc pair noted in round 1 is internal to `elements/`
and unchanged by the new code. **No new element cycle.**

### Finding 6 — `Plugin` hub: new code uses it benignly, adds no structural edge (LOW / benign)

The only new contact with the `Plugin` service-locator hub is a single runtime
read of `Plugin::getInstance()->version` in `McpServer::initializeResult()`
(`McpServer.php:171`), fully-qualified, no `use`. This is the established,
accepted Craft/Yii service-locator pattern (round-1 Finding 1) — lazy, runtime,
not construction-time. It introduces **no new import edge** into the `Plugin`
SCC and creates no initialization-ordering coupling. **Benign — no action.**

### Other structural checks

- **Self-loops:** none.
- **Resource ↔ resource:** the two resources do not reference each other; both
  depend only on the shared `ResourceProviderInterface` + support helpers.
- **Support ↔ support:** `InsightCorpus`, `SubmissionQueryBuilder`,
  `FormPresenter` are mutually independent (each imports only elements).

---

## Proposed breaks + confidence

| # | Candidate | Verdict | Break | Confidence |
|---|-----------|---------|-------|------------|
| 1 | insight tools ↔ InsightCorpus | One-way (sink utility) | **None.** | High |
| 2 | insight tools ↔ McpServer | One-way (server → tool) | **None.** | High |
| 3 | resources ↔ McpServer ↔ support | One-way (server → resource → support → elements) | **None.** | High |
| 4 | new tool ↔ QuerySubmissionsTool | One-way static schema reuse | **None.** | High |
| 5 | tool/resource ↔ elements | One-way (elements import no mcp) | **None.** | High |
| 6 | new code ↔ `Plugin` hub | Benign runtime service-locator | **None.** | High |

---

## High-confidence implementation checklist

**Empty — no harmful cycles introduced by the new MCP insight-tools/resources
feature, no changes recommended.**

The new feature is a clean DAG layered on top of the existing element/support
classes: `McpServer` (dispatcher) → tools & resources → shared static helpers
(`InsightCorpus`, `SubmissionQueryBuilder`, `FormPresenter`) → elements. None of
the depended-on classes import back into the dispatch layer, elements import no
`mcp` code, and the only `Plugin` contact is the accepted runtime
service-locator read. An empty checklist is the correct outcome here.

If a guardrail is ever wanted, the cheap dev-only option (out of scope for this
assessment) is a `deptrac` layer ruleset asserting the one-way flow:
`mcp\McpServer → {mcp\tools, mcp\resources} → {mcp\tools\support} → elements`,
allowing the round-1 benign edges (`* → Plugin`, `elements ⇄ elements\db`) and
flagging any future back-edge.
