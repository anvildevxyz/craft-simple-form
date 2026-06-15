# Concern #7 — Deprecated, Legacy & Fallback Code (Round 2, Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2)
**Version floor:** `craftcms/cms: ^5.0`, `php: ^8.2`
**Phase:** 1 — ASSESSMENT ONLY (no source edits made)
**Scope (this round):** the NEW MCP "insight tools" + "resources" feature only:
- `src/mcp/tools/DetectSpamPatternsTool.php`
- `src/mcp/tools/CategorizeSubmissionsTool.php`
- `src/mcp/tools/SummarizeSubmissionsTool.php`
- `src/mcp/tools/support/InsightCorpus.php`
- `src/mcp/resources/ResourceProviderInterface.php`
- `src/mcp/resources/FormSchemaResource.php`
- `src/mcp/resources/SubmissionsDatasetResource.php`
- `src/mcp/McpServer.php` (resources + insight-tool additions)
- (boundary check) `src/controllers/McpController.php` SSE comment
**Date:** 2026-06-15
**Sanity check:** `composer test` → 55/55 green, 659 assertions.

---

## Summary

Round 1 (`docs/cleanup/07-deprecated-legacy.md`) established that this is a
brand-new pre-1.0 plugin with **no** released version to be backward-compatible
with, found **0** deprecated / version-gated / BC items, and itemised seven
legitimate "fallback/legacy/old" string hits (N1–N7) plus the intentional SSE
scope boundary. That baseline holds for the new feature code too.

A targeted sweep of the round-2 scope for `@deprecated`, `legacy`, `fallback`,
`back-compat`/`BC`, `version_compare`, `getVersion`/`::VERSION`,
`method_exists` / `class_exists` / `function_exists` polyfills, `class_alias`,
`TODO`/`FIXME`, "for now", "just in case", and "new-format vs old-format"
branches returned **NO MATCHES** in any of the eight target files:

```
grep -rniE '@deprecated|legacy|fallback|back.?compat|\bBC\b|\bold\b|version_compare|
  getVersion|::VERSION|method_exists|class_exists|function_exists|class_alias|TODO|
  FIXME|for now|just in case|new.format|old.format'
  <round-2 scope>  →  NO MATCHES
```

Concretely, for the new feature:

- **0** `@deprecated` markers.
- **0** `version_compare` / `getVersion` / `::VERSION` gates — no Craft-version
  or PHP-version branches that could be dead against the `^5.0` / `^8.2` floor.
- **0** `method_exists` / `class_exists` / `function_exists` polyfills,
  **0** `class_alias` BC shims, **0** dead feature flags, **0** "TODO remove".
- **0** dual "if new-format else old-format" parsing branches.

The insight tools and resources are cleanly designed: all three insight tools
and both resource providers route their data access through the **single shared**
`SubmissionQueryBuilder` / `InsightCorpus` helpers, so there is no "two ways to
load the corpus" duplication at the data-access layer — exactly the redundancy
this concern warns about, and it's absent here.

There is, however, **one genuine redundant-duplicate path** inside the scope that
falls squarely under this concern's mandate (collapse N implementations of one
job to the single correct one): the private `resolveForm()` method is **copied
across all three insight tools**, two of the three copies byte-for-byte
identical. See **H1**.

Two pre-existing round-1 KEEP verdicts were re-checked against the new resources
work and remain KEEP (see Boundary checks): the MCP "backwards compatibility"
text block (round-1 N3) and the SSE "NOT implemented yet" boundary.

**HIGH-confidence redundant-duplicate items: 1 (H1).**
**HIGH-confidence dead / version-gated / deprecated items: 0.**

---

## Findings

### HIGH — redundant duplicate path (collapse to one)

#### H1 — `resolveForm()` copied across all three insight tools
**Files / lines (same job, three copies):**
- `src/mcp/tools/DetectSpamPatternsTool.php:186-198`
- `src/mcp/tools/CategorizeSubmissionsTool.php:173-185` — **byte-identical** to the above
- `src/mcp/tools/SummarizeSubmissionsTool.php:133-146` — same logic; only adds one
  descriptive comment line (`// Fall back to the form of the first submission…`)

All three resolve the form for an insight run with the identical three-tier
precedence:

```php
private function resolveForm(array $arguments, array $submissions): ?Form
{
    if (isset($arguments['formId'])) {
        $f = Form::find()->siteId('*')->status(null)->id((int)$arguments['formId'])->one();
        return $f instanceof Form ? $f : null;
    }
    if (isset($arguments['form']) && is_string($arguments['form']) && $arguments['form'] !== '') {
        $f = Form::find()->siteId('*')->status(null)->handle($arguments['form'])->one();
        return $f instanceof Form ? $f : null;
    }
    $first = $submissions[0] ?? null;
    return $first?->getForm();
}
```

`diff` of the Detect and Categorize bodies is empty (exit 0). The Summarize copy
differs only by a comment.

**Why it's redundant (and in scope):** This is one job — "given the tool
arguments and the fetched submission set, decide which `Form` defines the schema"
— implemented three times. It is *not* polymorphic: the three copies behave
identically and each tool calls `$this->resolveForm($arguments, $submissions)`
with the same intent (resolve the form so it can ask `InsightCorpus` for the
field types). This is precisely the "copy-pasted-then-tweaked variant" the
round-2 brief calls out, and the shared-helper pattern for collapsing it already
exists in this very feature (`InsightCorpus`, `SubmissionQueryBuilder`).

**Single clean path to keep:** Hoist exactly one copy (the comment-free Detect/
Categorize body) into the existing shared support helper as a static method —
the natural home is `InsightCorpus` (it already owns `fieldTypes()` /
`freeTextHandles()` and is imported by all three tools), e.g.:

```php
// InsightCorpus.php
/** @param array<string,mixed> $arguments @param list<Submission> $submissions */
public static function resolveForm(array $arguments, array $submissions): ?Form
{
    if (isset($arguments['formId'])) {
        $f = Form::find()->siteId('*')->status(null)->id((int)$arguments['formId'])->one();
        return $f instanceof Form ? $f : null;
    }
    if (isset($arguments['form']) && is_string($arguments['form']) && $arguments['form'] !== '') {
        $f = Form::find()->siteId('*')->status(null)->handle($arguments['form'])->one();
        return $f instanceof Form ? $f : null;
    }
    return ($submissions[0] ?? null)?->getForm();
}
```

Then replace the three private methods with calls to
`InsightCorpus::resolveForm($arguments, $submissions)` and delete the three
private copies. Behaviour-preserving (the Summarize comment can move into the
helper's docblock).

> Note on overlap, not duplication: `SubmissionQueryBuilder::build()` also resolves
> a form *by handle* (lines 32-38) but for a different job — turning the `form`
> filter into a `formId` query constraint, returning an error payload on miss. The
> insight tools' `resolveForm()` instead returns the `Form` element (or null) to
> read its schema, and additionally handles `formId` and the first-submission
> fallback. These are distinct responsibilities; H1 is only about the three
> *identical insight-tool* copies, not about merging with the query builder.

**Confidence: HIGH.** Two copies are provably byte-identical (empty diff), the
third differs only by a comment; all three callers use it identically; a shared
helper home already exists; collapsing is behaviour-preserving and test-covered.

---

### Boundary checks (round-1 KEEP verdicts re-verified against the new work)

#### B1 — SSE "NOT implemented yet" boundary — UNCHANGED, KEEP
`src/controllers/McpController.php:25-28`:
> "SSE streaming (server-initiated messages over a GET stream) is intentionally
> NOT implemented yet; the dispatch is isolated in `McpServer` so an SSE action
> can be added later…"

The new resources work added `resources/list` and `resources/read` handlers
**inside the same synchronous POST → JSON-RPC → `application/json` dispatch**
(`McpServer::handle()` cases at lines 140-144); the `initialize` capabilities
even advertise `resources.subscribe = false` and `listChanged = false`
(`McpServer.php:166`), i.e. no server-initiated streaming was introduced. The
resources feature therefore did **not** change the SSE status. Per the brief,
flag-only-if-changed: it is unchanged, so the round-1 KEEP stands. **Not legacy;
an intentional scope boundary. Confidence: HIGH.**

#### B2 — MCP "backwards compatibility" text block — UNCHANGED, KEEP (round-1 N3)
`src/mcp/McpServer.php:272-281` still serialises `structuredContent` into a
`text` content block "for backwards compatibility." As round-1 N3 established,
this refers to the **MCP wire-protocol spec** (clients that don't read
`structuredContent` fall back to the text block) — not this plugin's own history.
Both keys are one response, not two selectable paths. The insight tools rely on
this single shared response shaper; nothing was forked. **Required by spec, not
legacy. Confidence: HIGH.**

---

### Non-issues in the new code (matched a search term but legitimate — do NOT flag)

#### NN1 — `SummarizeSubmissionsTool.php:143` — "Fall back to the form of the first submission"
A descriptive comment on the third precedence tier of `resolveForm()` (use the
first submission's form when no explicit form filter was given). This is ordinary
precedence layering over **different inputs** (explicit `formId` → explicit
`form` handle → inferred from the result set), exactly like round-1 N5/N6. It is
*not* a back-compat branch. (The method it sits in is, separately, the H1
duplicate — but the "fall back" wording itself is not a legacy smell.)
**Confidence: HIGH (NOT legacy).**

#### NN2 — `SummarizeSubmissionsTool.php:125` / `InsightCorpus::textValues()` — "no resolved schema → treat every string as text"
When the form schema can't be resolved, `resolveHandles()` returns `[]` and
`InsightCorpus::textValues()` treats every scalar string value as text. This is a
single, intentional graceful-degradation default for the schemaless `data` blob
(the same spirit as round-1 N1), **not** a second "old-format" parsing path —
there is only one code path; the empty-handle list just widens it.
**Confidence: HIGH (NOT legacy).**

#### NN3 — `McpServer.php:171` — `?? '1.0.0'` serverInfo version default
`(string)(Plugin::getInstance()->version ?? '1.0.0')` is a null-coalesce default
for the advertised server version, not a version *gate* and not BC code. Out of
scope for concern #7 (and pre-existing, not part of the resources/insight diff).
**Confidence: HIGH (NOT a version gate).**

---

## High-confidence implementation checklist

1. **[H1] Collapse the triplicated `resolveForm()` into the shared helper.**
   - Add `public static function resolveForm(array $arguments, array $submissions): ?Form`
     to `src/mcp/tools/support/InsightCorpus.php` (the comment-free Detect/
     Categorize body; fold the Summarize comment into the helper docblock).
   - Replace the private method + its call sites with
     `InsightCorpus::resolveForm($arguments, $submissions)` in:
     - `src/mcp/tools/DetectSpamPatternsTool.php` (delete lines 186-198)
     - `src/mcp/tools/CategorizeSubmissionsTool.php` (delete lines 173-185)
     - `src/mcp/tools/SummarizeSubmissionsTool.php` (delete lines 133-146)
   - Behaviour-preserving; re-run `composer test` (currently 55/55) + `composer check`.

There are **no** provably-dead version gates, BC aliases, `@deprecated` markers,
polyfills, dead feature flags, `class_alias` shims, or "old-format vs new-format"
branches in the round-2 scope to remove — H1 is the only structural cleanup, and
it is a duplicate-path collapse. The SSE boundary (B1) and the MCP text-block
"backwards compatibility" (B2) are intentional/spec-required and must be KEPT.
