# 08 — AI Slop, Stubs, LARP & Unhelpful Comments — ROUND 2 (Assessment)

**Scope (round 2):** the new MCP "insight tools" + "resources" feature only:
- `src/mcp/tools/{DetectSpamPatternsTool,CategorizeSubmissionsTool,SummarizeSubmissionsTool}.php`
- `src/mcp/tools/support/InsightCorpus.php` (+ read its peer `SubmissionQueryBuilder.php` for caller-verification)
- `src/mcp/resources/{ResourceProviderInterface,FormSchemaResource,SubmissionsDatasetResource}.php`
- `src/mcp/McpServer.php` additions (tools()/resourceProviders() registration + `resources/*` dispatch)

**Phase:** 1 — assessment only. No source files were edited.
**Method:** read all eight files in full; grepped callers of every insight symbol (`InsightCorpus`, `freeTextHandles`, each tool, both resource providers, `ResourceProviderInterface`) to confirm wiring and rule out inert stubs; traced each heuristic by hand to confirm it actually computes a signal (not LARP).

## Summary

This freshly-written feature is **clean**. Per the round-1 warning that new MCP code is the likeliest slop source, I scrutinised every heuristic and every docblock — and the heuristics are **real, not LARP**:

- `DetectSpamPatternsTool` computes three genuine, independent signals: a duplicate-content index (normalise → group by normalised body → flag bodies appearing >1×), a link count via `preg_match_all('#https?://|www\.#i')` compared to a configurable threshold, and an all-caps "shouting" ratio (`upper letters / all letters > 0.8` gated by a 20-char minimum). No constant return, no placeholder buckets.
- `CategorizeSubmissionsTool` really groups by a closed-option field value with frequency counts, handles multi-value checkboxes (lands in every bucket) and empty → `(none)`, and auto-detects the first `select/radio/checkbox` field. Real work.
- `SummarizeSubmissionsTool` and both resource providers are honest thin adapters over the shared `SubmissionQueryBuilder` / `FormPresenter`; their docblocks correctly state they do NOT call an LLM and shape text for the client.
- All three tools + both resource providers are **registered and dispatched** in `McpServer::tools()` / `resourceProviders()` and reached via `tools/call` / `resources/read`. Nothing is dead-wired.

The comments are overwhelmingly substantive *why*-comments (signal provenance, scope/privacy boundaries, the DB-agnostic-after-fetch filtering rationale, MCP in-band-error semantics). **No** `not implemented` stubs, **no** TODO/FIXME, **no** decorative banners, **no** hardcoded sample output, **no** in-motion narration ("now we…", "refactored to…").

The only substantive finding is **one stale docblock** in `InsightCorpus::freeTextHandles` that describes a `null` return that the method (typed `list<string>`) cannot produce. It is a doc bug, not a code bug — the empty-array convention it should describe is what callers actually rely on.

## Findings

### MEDIUM — REPLACE (stale docblock: describes a `null` return the method can't make)

| File:line | Exact text | Class | Confidence |
|---|---|---|---|
| `src/mcp/tools/support/InsightCorpus.php:46–47` | `* The free-text field handles for a form. When the form schema can't be` / `* resolved (no form filter), returns null so callers fall back to treating` / `* every string value as text.` | REPLACE | High (it's wrong); Medium (low stakes) |

Why: the method is typed `@return list<string>` and ends with `return $handles;` — it returns an **empty list**, never `null`. Schema-resolution failure happens at the *caller* (no `Form` resolved), and the callers (`SummarizeSubmissionsTool::resolveHandles`, `DetectSpamPatternsTool::call`, `CategorizeSubmissionsTool::call`) pass an empty `$handles` to `textValues()`, which then treats every string value as text (`InsightCorpus.php:76`). So the *behaviour* the comment promises is real — only the "returns null" mechanism is fiction.

Proposed replacement:
```
* The free-text field handles in a form's resolved field set. Returns an empty
* list when none are free-text; callers pass that straight to textValues(),
* whose empty-handles path then treats every string value as text.
```

### LOW — optional tightening (not slop; noted for completeness)

| File:line | Exact text | Class | Notes |
|---|---|---|---|
| `src/mcp/tools/DetectSpamPatternsTool.php:106` | `// First pass: build each submission's combined text + duplicate index.` | KEEP | Genuinely orients the two-pass structure (pass 1 indexes, pass 2 scores); keep. |
| `src/mcp/tools/DetectSpamPatternsTool.php:118` | `// Second pass: evaluate signals per submission.` | KEEP | Pairs with the above; the two-pass split exists *because* duplicate detection needs the full index first. Keep. |
| `src/mcp/tools/DetectSpamPatternsTool.php:167` | `// Count http(s):// occurrences and bare www. domains.` | KEEP | Explains *what counts as a link* for the regex — a why, not a restatement. Keep. |
| `src/mcp/tools/CategorizeSubmissionsTool.php:84–85` | `// Server-side grouping signal: group submission ids by the value of the chosen option field, with frequency counts.` | KEEP | States the design intent (server does cheap grouping, client clusters free text). Keep. |
| `src/mcp/tools/CategorizeSubmissionsTool.php:159` | `// Auto-detect: first closed-option field in the schema.` | KEEP | Explains the default-selection rule. Keep. |

No DELETE-class noise was found in the scoped files.

## Stubs / LARP / no-op check

**None found.** Explicitly verified:

- **No fake heuristics.** Each spam signal is computed from the actual body text; `flagged` is populated only when `$signals !== []`. The "shouting" and "excessiveLinks" thresholds are real constants (`SHOUTING_MIN_LENGTH=20`, `DEFAULT_LINK_THRESHOLD=3`, `>0.8` ratio), not magic always-true/always-false gates.
- **No placeholder buckets.** `CategorizeSubmissionsTool` groups by live submission data; `(none)` is a legitimate empty-value bucket, not a stub.
- **No hardcoded sample output.** Every returned payload is derived from the query result set.
- **No `not implemented` / TODO / FIXME / "for now" / "coming soon"** anywhere in the scoped files.
- **All wired & shipped (not inert):** `McpServer::tools()` registers the three insight tools (lines 78–80); `resourceProviders()` registers both resource providers (94–96); `tools/call` (line 137) and `resources/read` (143) reach them through the dispatch switch with scope enforcement. Resource list/read scope-gating is real and independent (`resourceDescriptors` filters by scope; `handleResourceRead` re-checks). Confirmed via grep — no orphaned stub got shipped.
- **`SubmissionQueryBuilder` (shared dependency)** is fully implemented (build/applyFieldMatch/present); not a stub.

## High-confidence implementation checklist (Phase 2)

Only one change, and it is documentation-only (no behaviour change):

1. **REPLACE** `src/mcp/tools/support/InsightCorpus.php:46–47` docblock — drop the false "returns null" claim; describe the empty-list → "treat every string as text" convention that callers actually rely on (replacement text above).

**Do NOT touch** the `// First pass` / `// Second pass`, link-regex, and grouping comments — they encode real two-pass / design rationale (KEEP). Also continue to leave the round-1 false positives `McpServer.php:95/130` alone.

## Counts

- **HIGH DELETE:** 0
- **HIGH REPLACE:** 0
- **MEDIUM REPLACE:** 1 (`InsightCorpus.php:46–47` stale `null`-return docblock)
- LOW: 0 actionable (5 comments reviewed and confirmed KEEP)
- **Stubs / LARP / TODO / fake heuristics:** 0
