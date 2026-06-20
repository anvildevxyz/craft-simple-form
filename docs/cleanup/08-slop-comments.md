# Cleanup Concern 08: AI Slop, Stubs, Larp, Unhelpful Comments

**Date:** 2026-06-20
**Scope:** `/Users/fh/Documents/experiments/craft-plugin-dev/plugins/simple-form/src` (157 PHP files)
**Mode:** Read-only research. No source files edited.

## Critical assessment

This codebase is genuinely clean. Prior cleanup passes show: the comments
that survive overwhelmingly explain **why** (DB-schema relationships, framework
quirks, security rationale, N+1 avoidance, double-encode gotchas), which is
exactly what a new developer needs. There are no commented-out code blocks, no
`return null` fake-implementation stubs, no `throw new Exception('not
implemented')` larp, no decorative ASCII banners as noise (the dash underlines
that appear are section dividers inside substantial, useful docblocks), and no
redundant single-line PHPDoc (`@inheritdoc`/`@return void` counts are zero).

Only **2 high-confidence** items are true slop, plus 1 borderline. That is the
honest tally — quality over quantity.

---

## HIGH-confidence cleanups

### 1. Changelog tell: "Fixed:" inline comment
- **File:** `src/services/NotificationsService.php:167`
- **Text:** `// Fixed: split on comma/semicolon/whitespace, keep valid addresses.`
- **Why noise:** "Fixed:" narrates a past edit (changelog-style), not the code's
  purpose. The git history records the fix; the comment shouldn't.
- **Recommendation:** REPLACE with intent-only:
  `// Recipient field is a free-text list: split on comma/semicolon/whitespace.`
- **Confidence:** High

### 2. In-motion phrasing: "Now that ..."
- **File:** `src/services/PaymentsService.php:139`
- **Text:** `// Now that payment cleared, fire the dispatch + email that were withheld.`
- **Why noise:** "Now that" reads as in-motion narration. The underlying point
  (dispatch + email are *deferred* until payment clears) is a real business rule
  worth keeping — just reword to state it rather than narrate it.
- **Recommendation:** REPLACE with:
  `// Dispatch + email are withheld for paid forms until the payment clears.`
- **Confidence:** High

---

## SPECULATIVE / borderline (lower confidence)

### 3. "foundation's single proof-of-path tool" framing
- **File:** `src/mcp/tools/ListFormsTool.php:11`
- **Text:** `This is the foundation's single proof-of-path tool. It deliberately
  routes through the existing Form element layer ...`
- **Why borderline:** "foundation's single proof-of-path tool" is a time-bound /
  development-phase description (it won't be "single" once more tools exist —
  and several already do, e.g. `DetectSpamPatternsTool`, `CategorizeSubmissionsTool`).
  The rest of the docblock (routes through the Form element layer; exposes only
  structure metadata, never submission data) is valuable and should stay.
- **Recommendation:** Trim the first sentence to drop the phase framing, keep the
  rationale: `// Lists forms via the existing Form element layer (same query as
  CP/GraphQL); exposes only structure metadata, never submission data.`
- **Confidence:** Medium

### 4. "Get all forms for filter dropdown" / "Get submission statistics"
- **File:** `src/controllers/SubmissionsController.php:109` and `:115`
- **Text:** `// Get all forms for filter dropdown` / `// Get submission statistics`
- **Why borderline:** Both lightly restate the code. `:109` adds a sliver of
  intent ("for filter dropdown"); `:115` restates a self-documenting method call
  (`getSubmissionStats(...)`).
- **Recommendation:** REMOVE both (the following lines are self-explanatory). Low
  priority — harmless.
- **Confidence:** Low/Medium

---

## Items explicitly checked and CLEARED (not slop)

- `src/controllers/McpController.php:21-42` — "TRANSPORT NOTES" / "SECURITY
  POSTURE" docblock with dash dividers and "SSE ... intentionally NOT implemented
  yet". This is a documented architectural decision with rationale, not a stub.
  KEEP.
- `src/mcp/TokenManager.php`, `src/mcp/tools/DetectSpamPatternsTool.php` — dash
  "banners" are section headers inside genuinely explanatory security/heuristics
  docblocks. KEEP.
- All ~35 `return null;` occurrences — legitimate lookup/resolver/guard returns,
  not fake implementations.
- `placeholder` hits — form-field placeholders (a real feature), not stub markers.
- "This is ..." docblock openers (RecaptchaProvider, Scopes, Settings,
  FieldQueryHelper, etc.) — all introduce real purpose/rationale. KEEP.
- All `// Set/Get/Let/Delete/...` comments in services — each explains *why*
  (e.g. EmailService from-address fallback, FieldSyncService FK cascade,
  FieldsController double-encode gotcha). KEEP.

---

## Prioritized recommendation summary

| # | File:line | Action | Confidence |
|---|-----------|--------|------------|
| 1 | NotificationsService.php:167 | Replace "Fixed:" comment | High |
| 2 | PaymentsService.php:139 | Reword "Now that" → state the deferral rule | High |
| 3 | ListFormsTool.php:11 | Drop "foundation/proof-of-path" framing, keep rationale | Medium |
| 4 | SubmissionsController.php:109,115 | Remove restating comments | Low |

**High-confidence cleanups: 2.** Total candidate items: 4. The codebase is in
good shape; no structural slop, stubs, or larp found.
