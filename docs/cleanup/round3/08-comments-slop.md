# Round 3 — 08: Comments, AI Slop, Stubs & Larp

Research-only re-audit of comment quality. Covers ~40 commits of new code since
`c5b8fe7` (2026-06-21): DX initiative (#218–#225 — events, JS hooks, GraphQL SDL,
`make/*` generators, forms-as-code), payments (#116), the "leanify" refactor
sweep, the Install-migration collapse, and the tabbed form editor. Scope: 210 PHP
files (~33k LOC), all `src/templates` Twig, and `tests/js` + dist JS. **No source
was modified.**

## (a) Critical assessment

The codebase **remains atypically clean for an AI-loop-built plugin** — the
round-2 verdict holds across the new code. The whole slop smell-set returns
essentially nothing:

- **Zero** in-motion / process narration of the deletable kind (`// now we…`,
  `// changed from…`, `// previously…`, `// NEW:`, `// updated to…`). The
  "leanify"/refactor commits left **no** `// behaviour-preserving` /
  `// drop-in` / `// same as before` residue in source.
- **Zero** TODO / FIXME / HACK / XXX / WIP. The lone "NOT implemented yet" string
  is the deliberate SSE-transport architecture note in `McpController.php` — keep.
- **Zero** commented-out code (no `//`-prefixed code tokens, no block comments
  wrapping code, no stray `// }` closers).
- **Zero** stubs / no-op method bodies / placeholder returns / fake/dummy data /
  larp ("in a real implementation…", "for demonstration", "hardcoded for now").
  The `make/*` generator stub strings (`// Return the CP settings-form HTML…`,
  `// Transform $submission->data…`) are *intended scaffold guidance* the user
  fills in — correct, keep.
- **Zero** motivational filler / `// simply|obviously|basically` (the few
  `simply` hits are domain prose, e.g. "a bad line would simply never match").

The dominant style is **WHY-comments**: security ordering, anti-spoofing,
idempotency guards, Craft quirks, perf rationale, and `#NN` issue cross-refs. The
**eight new event classes** (`events/*Event.php`) are exemplary — each carries a
docblock with a runnable usage example and a non-obvious caveat (e.g.
`BeforeSendNotificationEvent` documents *why* it exposes `$submissionData` rather
than `$data`: Yii's base `Event` reserves `$data`). The new console controllers
(`MakeController`, `forms/*`) and the `Install` migration are equally clean.

**Templates & JS (Explore sub-agent sweep): clean — nothing to flag.** Twig
`{# #}` headers, closing-div markers, and the "server is authoritative" notes are
all legitimate; the three dist JS bundles use only structural markers, JSDoc, and
"keep in sync with PHP" notes.

**Net recommendation: 0 mandatory removals.** The maximal-effort batch is **2
mild restatement comments worth removing** and **2 optional LOW-confidence
"was an N+1" tightenings** carried over from the round-2 delta. This dimension is
already at ship quality.

## (b) Findings

| File:line | Quoted comment | Class | Recommended action | Conf | Risk |
|---|---|---|---|---|---|
| `src/gql/mutations/FormMutations.php:138` | `// Build the field-id => value map from the input list.` | REMOVE | Delete. The next line is `$values = self::buildValueMap(...)` — a self-documenting call whose method already has a full docblock (L259–263) saying exactly this. Pure restatement. | MED | Low |
| `src/gql/mutations/FormMutations.php:231` | `// Build the field-id => value map from the input list.` | REMOVE | Delete — identical duplicate of L138, same `buildValueMap()` call below. | MED | Low |
| `src/services/IntegrationsService.php:642` | `// Batch-load the referenced submissions once (was an N+1 of up to $limit per-row queries); same default query semantics as the prior ->one().` | REPLACE | Optional. Drop the look-back parentheticals; keep the intent + caveat. Replace with: `// Batch-load the referenced submissions once instead of one query per row; keeps default query semantics (no eager-loading / status filters).` | LOW | Low |
| `src/elements/Submission.php:220–221` | `// match evaluates only the hit arm, so a single Craft::t runs per row` `// (the array form translated all three labels to use just one).` | REPLACE | Optional. First line is a fine WHY; the parenthetical narrates an unseen prior `array` form. Drop line 221: `// match evaluates only the hit arm, so a single Craft::t runs per row.` | LOW | Low |

### Slop-looking but load-bearing — do NOT touch (KEEP)

- `src/controllers/McpController.php` class docblock — "SSE … intentionally NOT
  implemented yet", CSRF-disabled / anon-session / 404-cloaking ("it pretends not
  to exist") rationale. High-value security/architecture doc.
- `src/services/SubmissionService.php` — virtually every `//` is WHY (honeypot
  silent-drop, single-gate scheduling/quota, hidden-field anti-spoof #124,
  SPAM→non-spam idempotent approve, prior-status capture for transitions).
- `src/helpers/SubmissionCsv.php:213,388` & `IntegrationsService.php:642` —
  `array_is_list()` correctness note and the N+1 perf notes (the two REPLACE rows
  above are *optional* trims, not slop).
- `src/Plugin.php:405` — `// Create a submission via … submitForm; edit via …
  updateSubmission (#144)` carries the real WHY: the two are *scoped separately
  so submit can be granted without edit*. Keep.
- `src/controllers/FormsController.php:112-113,189` — "email moved to
  Notifications screen; emailTo/… no longer edited here" and the
  "unchanged body not gated" sync note. Both document live behaviour, keep.
- `src/fields/FieldType.php:210` — `isset() equivalent to membership` micro-WHY.
- New migrations `Install.php` inline schema comments (SHARED vs per-site rows) —
  keep; they document the element/content split.
- All `events/*Event.php` docblocks + their in-example inline comments
  (`// never forward spam downstream`, etc. — these are *inside* usage examples).
- All `// Public Methods` / `// Private Methods` / `=====` section headers
  (craft-php-guidelines convention).
- `make/*` generator stub guidance strings, all `// Payments (#116)` / issue
  markers, `models/Settings.php:357` parseEnv-returns-bool Craft quirk,
  `helpers/SafeUrl.php` `$this`-rebound validator-closure note.

## (c) HIGH-CONFIDENCE RECOMMENDATIONS

There are **no HIGH-confidence findings** — nothing rises above MEDIUM.

The only changes worth making, in priority order:

1. **`FormMutations.php:138` and `:231`** (MED) — delete the two identical
   `// Build the field-id => value map from the input list.` comments. They
   restate a self-documenting `buildValueMap()` call that already has a docblock.
   Safe, mechanical, 2 deletions.

2. *(Optional, LOW)* tighten the two "was an N+1 / array form" parentheticals in
   `IntegrationsService.php:642` and `Submission.php:221` per the table — safe to
   skip; the originals are defensible.

## Counts

| Category | Count |
|---|---|
| REMOVE (mandatory) | 0 |
| REMOVE (MED — recommended) | 2 |
| REPLACE (LOW — optional) | 2 |
| STUB / larp / dead code | 0 |
| KEEP (slop-looking, load-bearing) | ~18 spots / whole files |

Templates: clean. JS (tests + 3 dist bundles): clean.
