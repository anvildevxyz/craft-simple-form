# 08 — Comments & "AI Slop" Cleanup

Research-only audit of comment quality across `src/` (223 PHP files, ~993 `//`
lines + docblocks). No source was modified.

## 1. Critical assessment of comment quality

The comment quality in this codebase is **high — atypically so for an
AI-loop-built plugin.** The usual slop signatures are essentially absent:

- **Zero** in-motion / process-narration comments (`// now we…`, `// replaced…`,
  `// changed from…`, `// previously…`, `// NEW:`, `// updated to…`). Greps for
  the full smell set returned nothing.
- **Zero** commented-out code blocks. Every `// $foo` / `// return…` hit is an
  explanatory reference to a variable/return value in prose, not dead code.
- **Zero** scaffolding/generator residue (`// your code here`, `@generated`,
  `// example`), zero motivational filler, zero `// obviously/simply/basically`.
- **No TODO / FIXME / HACK / XXX** left in shipped code. The single "not
  implemented yet" string is a deliberate, well-justified architecture note in
  `controllers/McpController.php` (SSE transport is out of scope; the docblock
  explains the seam) — **keep**.
- **No stub/no-op methods**, no empty-body placeholders.
- **No redundant PHPDoc** echoing signatures; `@param`/`@return`/`@throws` are
  real and PHPStan-relevant. Exactly one borderline param-echo (below).

The dominant comment style is **WHY-comments**: security ordering, anti-spoofing
rationale, idempotency guards, race-safety caveats, Craft quirks, and `#NN`
issue cross-references. `services/SubmissionService.php` (141 comment lines) is a
model of this — nearly every comment documents a non-obvious security or
transport-parity constraint. These must be preserved.

Section-header comments (`// Public Methods`, `// Const Properties`, etc.) are
the craft-php-guidelines project convention — **keep all of them**.

**Net recommendation: effectively nothing to remove.** A maximal-effort batch
is 2 optional *replacements* (tightening, not deletion) and 0 mandatory
deletions. This dimension is already clean.

## 2. Findings

### High (clearly removable noise / commented-out code)
**None.** No dead code, no in-motion comments, no slop.

### Medium
**None.**

### Low (optional polish — replace, do not delete; all RISK: Low)

Grouped by file.

#### `src/gql/mutations/FormMutations.php`
- **L138, L246** — `// Build the field-id => value map from the input list.`
  - Classify: mild restatement (the loop directly below does exactly this).
  - It does add slight value (names the *shape* of the output, `field-id => value`).
    The duplicate at L246 is identical to L138.
  - Recommended action (optional): leave as-is, or tighten to
    `// Posted values arrive as a list; index them by field id.` Not worth a
    dedicated edit.

#### `src/services/FormRenderService.php`
- **L182** — `@param \anvildev\simpleform\elements\Submission $submission the submission to edit`
  - Classify: borderline redundant-doc (param-name echo). The phrase "to edit"
    carries a sliver of intent, so it is not pure noise.
  - Recommended action (optional): keep. If trimming, the type + name already
    suffice; the description could be dropped. Low value either way.

#### `src/controllers/SubmissionEditController.php`
- **L72–74** — `// Build the values map, resolving file uploads to asset ids — mirrors SubmissionService::createFromRequest…`
  - Classify: **NOT slop.** Looks like a "Build the … map" restatement but the
    second clause documents a real cross-method parity invariant.
  - Recommended action: **keep.**

### Note on the "Build … map" cluster
Greps flagged three `// Build the … map` comments as restatement candidates. On
inspection only the two `FormMutations` duplicates are mild restatement; the
`SubmissionEditController` one carries WHY-context. None warrants deletion.

## 3. Slop-looking comments that actually carry WHY-context (KEEP)

These match naive slop greps but are load-bearing — do **not** strip:

- `src/controllers/McpController.php` (class docblock) — "SSE … intentionally NOT
  implemented yet", CSRF-disabled rationale, anonymous-session rationale,
  off-by-default 404 posture. High-value security/architecture documentation.
- `src/services/SubmissionService.php` — virtually every `//` comment: honeypot
  silent-drop reasoning, scheduling/quota single-gate rationale, hidden-field
  anti-spoofing (#124), composite-field key-injection defense, server-side
  calculation recompute (#131), denylist/duplicate/Akismet ordering, spam-count
  exclusion, SPAM→non-spam idempotent approve edge. All WHY.
- `src/services/FormRenderService.php:264` — `// $prefillValues … overrides …`
  (#144) — documents precedence, not narration.
- `src/services/FieldSyncService.php:342` — "New block: only gated when it
  actually carries a body." Reads like an in-motion "New" but "New block"
  describes a *case in the sync algorithm*, not a code change. Keep.
- `src/integrations/AbstractGoogleIntegration.php:162` — `// return false;
  suppress the warning…` is prose explaining error handling, not dead code.
- `src/helpers/SafeUrl.php:108` — `// $this is rebound …` documents a Yii
  validator-closure quirk. Keep.
- `src/migrations/m260618_000003…php:50` — `// $this->insert() stamps
  dateCreated/…` explains Craft migration behavior. Keep.
- `src/fields/FileFieldType.php:132`, `controllers/SubmissionsController.php:222`
  — variable-referencing explanatory prose, not commented code. Keep.
- All `// Public Methods` / `// Const Properties` / `// Private Methods` section
  headers (craft-php-guidelines convention). Keep.

## Summary table

| Severity | Count | Action |
|----------|-------|--------|
| High (delete) | 0 | — |
| Medium | 0 | — |
| Low (optional replace) | 2 | tighten `FormMutations` L138/L246 duplicate restatement; optionally trim `FormRenderService:182` param desc |
| Keep (WHY-context, slop-looking) | ~12 spots / whole files | preserve |

Total mandatory cleanup: **0**. Total optional: **2 replacements, 0 deletions.**
