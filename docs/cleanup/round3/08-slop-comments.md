# Round 3 — Concern 08: AI Slop, Stubs, Larp, Unhelpful Comments

**Date:** 2026-06-20
**Scope:** `src/**` PHP (162-ish files), `src/templates/**` Twig, `src/web/assets/**` JS
**Mode:** Read-only research. Independent re-pass; confirms/refutes/extends `docs/cleanup/08-slop-comments.md` (round 1, landed in PR #146).

## Critical assessment

The codebase remains genuinely clean. The overwhelming majority of surviving
comments explain **why** (DB/FK relationships, framework quirks, security
posture, N+1 avoidance, a11y, escaping gotchas) — exactly what a new reader
needs. No commented-out code blocks, no fake `return null` stubs, no
`throw 'not implemented'` larp, no redundant single-line PHPDoc.

**What round 1 already fixed (verified now):**
- `NotificationsService.php:167` — the "Fixed:" changelog tell is gone; comment
  now reads `// Static recipient list: split on comma/semicolon/whitespace…`. ✅
- `PaymentsService.php:139` — the "Now that …" in-motion phrasing is gone;
  comment now states the deferral rule directly. ✅

**Still open from round 1 (re-confirmed):**
- `ListFormsTool.php:11` — "foundation's single proof-of-path tool" framing.
- `SubmissionsController.php:109,115` — two lightly-restating comments.

**New findings this pass (round-1 missed these):**
- A recurring **"foundation"** phase-framing term across the MCP layer (3 sites),
  now partly stale ("single proof-of-path tool" — several MCP tools exist).
- Two **changelog tells in Twig** referencing files that no longer exist
  (`old list.html`, `_sidebar.html`).

The honest tally is small. No structural slop. Comment-only edits, low risk.

---

## Findings

### A. "foundation" phase-framing (MCP layer) — 3 sites
Time-bound development-phase language. "Foundation" describes a project phase,
not the code's behaviour, and `ListFormsTool`'s "single proof-of-path tool" is
now factually stale (DetectSpamPatternsTool, CategorizeSubmissionsTool, etc.
exist). The surrounding rationale in each docblock is valuable — keep it, drop
only the phase framing.

| File:line | Current | Action | Replacement |
|---|---|---|---|
| `src/mcp/tools/ListFormsTool.php:11` | `This is the foundation's single proof-of-path tool. It deliberately routes through the existing {@see Form} element layer …` | REPLACE | `Routes through the existing {@see Form} element layer (the same query the CP and GraphQL use) rather than introducing new business logic, and exposes only form *structure* metadata — never any submission data.` |
| `src/controllers/McpController.php:26` | `…sufficient for the synchronous request/response tools in this foundation.` | REPLACE | `…sufficient for the synchronous request/response tools the plugin exposes.` |
| `src/mcp/TokenManager.php:128` | `It is deliberately deferred for this foundation; secrets are 256-bit random…` | REPLACE | `It is deliberately deferred; secrets are 256-bit random…` |

Confidence: **High** (these are textbook in-motion / phase framing).

### B. Twig changelog tells referencing deleted files — 2 sites
Both reference templates that no longer exist (`list.html` and `_sidebar.html`
are confirmed removed). The "ported from / replaces the old …" clauses are
git-history narration; the useful intent is the rest of each comment.

| File:line | Current | Action | Replacement |
|---|---|---|---|
| `src/templates/submissions/index.html:16` | `{# Stats summary, ported from the old list.html. Each card is a status filter link; the card matching the active status is highlighted. #}` | REPLACE | `{# Stats summary. Each card is a status filter link; the card matching the active status is highlighted. #}` |
| `src/templates/settings/index.html:9` | `{# Native Craft header tabs for the settings sub-sections (replaces the hand-rolled _sidebar.html). Each tab is a real URL; selectedTab highlights the active one. #}` | REPLACE | `{# Native Craft header tabs for the settings sub-sections. Each tab is a real URL; selectedTab highlights the active one. #}` |

Confidence: **High**.

### C. Restating comments in SubmissionsController — 2 sites (round-1 carryover)
`:109` adds a sliver of intent ("for filter dropdown"); `:115` restates a
self-documenting method call (`getSubmissionStats(...)`).

| File:line | Current | Action |
|---|---|---|
| `src/controllers/SubmissionsController.php:109` | `// Get all forms for filter dropdown` | REMOVE (or keep — harmless; the `$allForms` var + `orderBy(title)` is self-evident) |
| `src/controllers/SubmissionsController.php:115` | `// Get submission statistics` | REMOVE |

Confidence: **Low/Medium** (harmless either way).

---

## Items explicitly checked and CLEARED (not slop)

- **JS dividers in `cp.js`** (`// --- Submission detail: toggle read status ---`,
  etc.) — these label functional sections of a long IIFE with *meaningful*
  descriptions; genuine navigation aid. KEEP.
- **JS dividers in `form-builder.js` / `simple-form.js`** (`// ---- mutation ----`,
  `// ---- inspector ----`) — ASCII-padded single-word section labels. Borderline
  decorative, but they aid navigation in 700-line files and are internally
  consistent. KEEP (not worth churn; low value to remove).
- `McpController.php` "TRANSPORT NOTES / SECURITY POSTURE" docblock with dash
  dividers and "SSE … intentionally NOT implemented yet" — documented
  architectural decision with rationale (the "foundation" word inside it is
  handled in finding A). KEEP the structure.
- `TokenManager.php` rate-limiting `NOTE:` — a real, useful "natural seam"
  rationale (the "foundation" word is finding A). KEEP the rest.
- `SubmissionWidgetTrait.php:10` "Shared scaffolding…" — "scaffolding" here means
  shared boilerplate (the trait's actual role), not phase framing. KEEP.
- `EmailService.php:86`, `FormMutations.php:104`, `SimpleFormControllerTrait.php:12`,
  `FormSyncService.php:177`, `FormMutations.php:97` — all carry real intent
  (fallback rationale, FK pruning, audit follow-up, contract docs). KEEP.
- All `placeholder` hits — form-field placeholders (a feature). KEEP.
- All `return null;` — legitimate lookups/guards. KEEP.

---

## Prioritized summary

| # | File:line | Action | Confidence |
|---|-----------|--------|------------|
| A1 | ListFormsTool.php:11 | Replace — drop "foundation/proof-of-path" framing | High |
| A2 | McpController.php:26 | Replace — drop "in this foundation" | High |
| A3 | TokenManager.php:128 | Replace — drop "for this foundation" | High |
| B1 | submissions/index.html:16 | Replace — drop "ported from old list.html" | High |
| B2 | settings/index.html:9 | Replace — drop "replaces the hand-rolled _sidebar.html" | High |
| C1 | SubmissionsController.php:109 | Remove restating comment | Low/Med |
| C2 | SubmissionsController.php:115 | Remove restating comment | Low/Med |

**High-confidence cleanups: 5** (3 PHP "foundation", 2 Twig changelog tells).
All comment-only; safe to auto-implement. Round-1 findings #1/#2 already shipped.
