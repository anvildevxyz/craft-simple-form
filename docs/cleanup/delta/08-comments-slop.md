# Delta 08 — Comments & "AI Slop"

Delta scope: PHP source changed since `c5b8fe7` (WIP files `FormsController`,
`elements/Form`, `db/FormQuery`, `FormRenderService` excluded per brief; read for
context only). ~70 files, ~60 newly-added inline comments + several docblocks.

## 1. Critical assessment

The delta upholds the standard the full audit found: comment quality is high and
slop is effectively absent.

- **Zero** commented-out code (grep for `//`-prefixed code tokens returned nothing).
- **Zero** real TODO/FIXME/HACK/XXX, zero stubs/placeholders/no-ops. The
  `placeholder` / `not yet warmed` / `not implemented` grep hits are all domain
  terms (placeholder HTML attrs, URL placeholders, lazy-init "warmed" caches),
  not abandoned work.
- **Zero** motivational filler / "simply/obviously/basically" / restated-signature
  PHPDoc.
- The dominant style is **WHY-comments**: payment-flow invariants and #116 cross-refs
  in `SubmissionService`/`PaymentsService`/`SubmitController`/`PaymentFieldType`,
  N+1 batch-load rationale, Craft quirks (`parseEnv` bool return in `Settings.php:357`,
  `Migration::insert()` auto-stamps in the new migration), security rationale
  (`ConsentText` javascript:/data: neutralisation, `SignaturePng` magic-byte check,
  `NotificationsController` F19/CWE-20 operator allowlist, `RateLimiter`
  not-atomic-by-design). All load-bearing — **keep**.
- The two new migrations' class docblocks use "now lives", "is now opt-in",
  "Before this change…" phrasing. In a **migration** this is not in-motion slop —
  documenting the prior state and the upgrade-preservation logic is precisely the
  migration's job, and the text explains *why nothing visually changes on upgrade*.
  **Keep both.**

Net: nothing mandatory. Two optional micro-tightenings of parenthetical
"was X" asides that lean toward narrating an invisible prior implementation.

## 2. Patch list

All items below are **LOW confidence / optional** — replacements, not deletions.
There are **no high-confidence patches**.

### LOW — optional, skip unless polishing

**`src/elements/Submission.php:220–221`**
- Exact comment:
  ```
  // match evaluates only the hit arm, so a single Craft::t runs per row
  // (the array form translated all three labels to use just one).
  ```
- Problem: leading clause is a fine WHY; the parenthetical narrates an unseen
  prior `array` implementation ("the array form translated all three") — mild
  in-motion narration the reader can't verify.
- Proposed replacement (drop the parenthetical):
  ```
  // match evaluates only the hit arm, so a single Craft::t runs per row.
  ```
- Justification: keeps the actual reason (one translation call per row), removes
  the look-back at code that no longer exists.

**`src/services/IntegrationsService.php:623–624`**
- Exact comment:
  ```
  // Batch-load the referenced submissions once (was an N+1 of up to $limit
  // per-row queries); same default query semantics as the prior ->one().
  ```
- Problem: both parentheticals reference the prior implementation. The WHY
  ("batch-load once") and the load-bearing caveat ("same default query semantics")
  survive without the "was an N+1 … prior ->one()" look-back.
- Proposed replacement:
  ```
  // Batch-load the referenced submissions once instead of one query per row;
  // keeps default query semantics (no eager-loading / status filters).
  ```
- Justification: preserves the perf intent and the semantics caveat; drops the
  in-motion "was …/prior" framing. LOW confidence because the original is already
  defensible (the contrast aids reviewers of the perf change).

### KEEP — slop-looking but load-bearing (do NOT strip)

- `src/helpers/SubmissionCsv.php:385–386` — `// … (was array_keys() !== range())`
  documents the exact equivalence of `array_is_list()` to the replaced idiom; the
  parenthetical is a *correctness* note, not narration. Keep.
- Both new migrations `m260622_000001` / `m260622_000002` class docblocks and inline
  comments — upgrade-preservation WHY. Keep.
- `src/fields/PaymentFieldType.php` "gateway used to collect payment" — "used to"
  means "employed to", not a past-tense change. Keep.
- All `// Payments (#116)` translation-section markers and issue cross-refs. Keep.
- `src/models/Settings.php:357` parseEnv-returns-bool Craft quirk. Keep.

## 3. Verdict

**0 high-confidence patches.** (2 optional LOW-confidence tightenings of "was …"
parentheticals in `Submission.php:220` and `IntegrationsService.php:623`; safe to
skip — the delta comment quality is clean.)
