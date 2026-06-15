# Concern #5 — Remove Weak Types (ROUND 2, Assessment)

**Plugin:** Simple Form (Craft CMS 5, PHP 8.2) · **PHPStan:** level 7, re-verified `[OK] No errors` during this assessment.
**Scope (ROUND 2):** the NEW MCP "insight tools" + "resources" feature only —
`mcp/tools/{DetectSpamPatternsTool,CategorizeSubmissionsTool,SummarizeSubmissionsTool}.php`,
`mcp/tools/support/InsightCorpus.php`,
`mcp/resources/{ResourceProviderInterface,FormSchemaResource,SubmissionsDatasetResource}.php`,
and the `McpServer.php` additions (#66/#67 registration).
Assessment only — **no source files were modified** (a covariance probe was applied then reverted; baseline unchanged).

## Summary

Round 1's verdict holds for the new code: it is **already strongly typed and disciplined at its dynamic
boundaries.** The insight tools deliberately carry `array<string, mixed>` only where a value is a genuine
dynamic boundary, and every helper already documents a precise `list<...>` / `array<string, string>` /
`array{...}` shape:

- `InsightCorpus` is exemplary — `fieldTypes(): array<string, string>`, `freeTextHandles(): list<string>`,
  `textValues(): array<string, string>`. Nothing to strengthen there.
- `Submission::$data` already carries `@var array<string, mixed>|null` (the honest decoded-JSON boundary) —
  **not** a bare `?array`. The insight tools read it through that boundary and immediately type-guard
  (`is_array($value)`, `(string)$value`, `array_map('strval', …)`), which is the correct pattern. Stays `mixed`.
- The `ToolInterface::call()` / `ResourceProviderInterface::read()` / `::list()` signatures are inherited and
  fixed at `array<string, mixed>` / `array{contents:…}|array{isError:…}` / `list<array<string, mixed>>`. The
  resource result/error shapes are **already documented to the exact inherited shape** — no weakness.
- MCP `$arguments`, JSON-RPC `$id` (`mixed`), and `$params` (`array<string, mixed>`) in `McpServer` are the
  same legitimate boundaries Round 1 confirmed; they stay.

The one real, non-cosmetic opportunity is that the **three insight tools' `call()` methods build a fully-known
internal result shape** but document it only as the inherited `array<string, mixed>`. Their output objects
(`flagged` spam signals, category `corpus`/`groups`, summary `corpus`/`entries`) are precise internal DTOs, not
open blobs. A narrowing `@return array{...}` on the override is **covariant-safe** — I probed it on
`DetectSpamPatternsTool` and PHPStan stayed `[OK] No errors`. Secondarily, a couple of **local accumulator
arrays** (`$byNormalized`, `$bodies`, `$groups`) carry no inline `@var` and could be annotated, but PHPStan
already infers them correctly, so those are LOW (documentary only).

**Net genuine findings: 5 — all PHPDoc-only, all covariant-safe.** HIGH = 3 (the three `call()` return shapes,
each fully determined by a literal return array). No `any`/`unknown`-equivalent rot; no missing native
declarations; no bare `array` PHPDoc.

---

## Findings

### HIGH confidence (PHPDoc-only; shape fully determined by the literal return; covariance probed green)

The three insight tools each end `call()` with a single literal `return [ ... ];` whose keys and value types are
fully visible in the method body. Documenting that exact shape (unioned with the early `array{isError:true,error:string}`
return that `SubmissionQueryBuilder::build()` can produce) replaces the inherited `array<string, mixed>` with the
real internal DTO. The override narrows the interface return — **covariant, and PHPStan-verified safe** (probe on H1
applied + reverted: `[OK] No errors`).

#### H1 — `src/mcp/tools/DetectSpamPatternsTool.php:83` `call(): array` (`@return array<string, mixed>`)
- **Actual return (lines 150–156):**
  `array{scanned:int, flaggedCount:int, linkThreshold:int, signals:list<string>, flagged:list<array{id:int, dateCreated:?string, signals:list<string>, linkCount:int, text:string}>}`.
  The early-exit (line 88–89) returns the builder's error payload, so the full type is that unioned with
  `array{isError:true, error:string}`.
- **Evidence:** `flagged[]` entries are built at lines 140–146 with exactly those keys; `dateCreated` is
  `$submission->dateCreated?->format('c')` → `?string`; `signals` is a `list<string>` of the literal signal
  names; `linkCount`/`scanned`/`flaggedCount`/`linkThreshold` are `int`. `SubmissionQueryBuilder::build()` is
  typed `SubmissionQuery|array{isError:true,error:string}` (builder line 22–23), so the `is_array($built)` branch
  returns that error shape.
- **Native vs PHPDoc:** PHPDoc-only. Native stays `: array` (interface-mandated). Covariant narrowing of the
  inherited `@return array<string, mixed>`.
- **Confidence:** HIGH — probed: narrowing this exact shape kept PHPStan `[OK] No errors`.
- **Change:**
  `@return array{scanned:int,flaggedCount:int,linkThreshold:int,signals:list<string>,flagged:list<array{id:int,dateCreated:?string,signals:list<string>,linkCount:int,text:string}>}|array{isError:true,error:string}`

#### H2 — `src/mcp/tools/CategorizeSubmissionsTool.php:62` `call(): array` (`@return array<string, mixed>`)
- **Actual return (lines 106–112):**
  `array{count:int, groupBy:?string, textFields:list<string>, groups:list<array{value:string,count:int,submissionIds:list<int>}>, corpus:list<array{id:int,dateCreated:?string,fields:array<string,string>}>}`.
  Unioned with `array{isError:true,error:string}` from the early exit (line 67–68).
- **Evidence:** `groups` is the output of `shapeGroups()` whose `@return` is already
  `list<array{value:string,count:int,submissionIds:list<int>}>` (line 134–135) — so that sub-shape is already
  proven. `corpus[]` entries are built at lines 89–93: `id` (int), `dateCreated` (`?string`), `fields`
  (`InsightCorpus::textValues()` → `array<string,string>`). `groupBy` is the resolver result, `?string`.
  `textFields` is `freeTextHandles()` → `list<string>`.
- **Native vs PHPDoc:** PHPDoc-only, covariant.
- **Confidence:** HIGH — every leaf type is independently typed by an existing annotation or a literal cast.
- **Change:**
  `@return array{count:int,groupBy:?string,textFields:list<string>,groups:list<array{value:string,count:int,submissionIds:list<int>}>,corpus:list<array{id:int,dateCreated:?string,fields:array<string,string>}>}|array{isError:true,error:string}`

#### H3 — `src/mcp/tools/SummarizeSubmissionsTool.php:64` `call(): array` (`@return array<string, mixed>`)
- **Actual return (lines 100–106):**
  `array{count:int, totalMatched:int, fields:list<string>, wordCount:int, corpus:list<array{id:int,dateCreated:?string,fields:array<string,string>,text:string}>}`.
  Unioned with `array{isError:true,error:string}` (early exit line 68–69).
- **Evidence:** `corpus[]` entries built at lines 92–97 with those exact keys; `fields` is
  `$values = InsightCorpus::textValues()` → `array<string,string>`; `text` is `implode("\n", $values)` →
  `string`. Outer `fields` is `resolveHandles()` → `list<string>` (line 113–114). `count`/`totalMatched`/
  `wordCount` are `int`.
- **Native vs PHPDoc:** PHPDoc-only, covariant.
- **Confidence:** HIGH.
- **Change:**
  `@return array{count:int,totalMatched:int,fields:list<string>,wordCount:int,corpus:list<array{id:int,dateCreated:?string,fields:array<string,string>,text:string}>}|array{isError:true,error:string}`

---

### MEDIUM confidence (correct & safe, but lower payoff / mild redundancy)

#### M1 — `src/mcp/tools/CategorizeSubmissionsTool.php:86` local `$groups` accumulator (no inline `@var`)
- `$groups` is built as `array<string, array{count?:int, submissionIds?:list<int>}>` (lines 98–101) and is then
  passed to `shapeGroups()`, whose `@param` already documents exactly that shape (line 132–133). PHPStan already
  infers it correctly via that call site, so an inline `@var array<string, array{count?:int,submissionIds?:list<int>}>`
  at line 86 is **documentary only** — it removes the reader's need to chase `shapeGroups()` to learn the shape.
  Safe; optional. MEDIUM (no weakness removed, but it's the one accumulator whose shape isn't obvious at a glance).

---

### LOW confidence / leave as-is (documentary at best, or genuine dynamic boundary)

#### L1 — `*::call(): array<string, mixed>` early-return `$built` (the builder error payload)
- The `if (is_array($built)) { return $built; }` early returns are already typed by the builder's
  `SubmissionQuery|array{isError:true,error:string}` signature; folding `array{isError:true,error:string}` into the
  `@return` union is covered by H1–H3. No separate action.

#### L2 — `$byNormalized` / `$bodies` locals in `DetectSpamPatternsTool` (no inline `@var`)
- `$bodies` is `array<int,string>`, `$byNormalized` is `array<string,list<int>>`. PHPStan infers both from the
  literal assignments; an inline `@var` is pure documentation. Skip unless the team wants belt-and-suspenders —
  lower payoff than M1 because both are tiny and local. LOW.

#### L3 — `inputSchema(): array<string, mixed>` on all three insight tools (and `ToolInterface`)
- This is a JSON Schema document assembled from `QuerySubmissionsTool::filterProperties()` (an open
  `array<string, mixed>` of property descriptors) plus literal property entries. The JSON-Schema vocabulary is
  open-ended (`type`/`properties`/`items`/`minimum`/`additionalProperties`/`description` mix scalars, nested
  arrays, and bools), so `array<string, mixed>` is the **honest type** for a schema blob — matching the inherited
  `ToolInterface::inputSchema(): array<string, mixed>`. A precise `array{type:string, properties:…, additionalProperties:bool}`
  shape would be brittle and add no static safety. Leave as-is.

#### L4 — `InsightCorpus::textValues()` / `fieldTypes()` / `freeTextHandles()`
- Already precisely shaped (`array<string,string>`, `array<string,string>`, `list<string>`). No finding.

#### L5 — Resource `list(): list<array<string, mixed>>` and `read(): array{contents:…}|array{isError:…}`
- `read()`'s union is already the exact inherited `ResourceProviderInterface::read()` shape — strong. `list()`'s
  descriptor entries are MCP resource descriptors with optional keys (`uri,name,title,description,mimeType`);
  the interface mandates `list<array<string, mixed>>` and the descriptors are consumed generically by
  `McpServer::resourceDescriptors()` → json-encoded. Narrowing to
  `array{uri:string,name:string,title:string,description:string,mimeType:string}` is possible and covariant, but
  the interface return is shared by both providers and would have to stay the looser inherited type, so a per-impl
  narrowing buys little. LOW — leave unless the team wants it.

#### L6 — `McpServer` additions: `mixed $id`, `array<string, mixed> $params/$request`, `resourceProviders(): list<ResourceProviderInterface>`
- All correct. `$id` is the JSON-RPC id (genuinely `mixed`), `$params`/`$request` are decoded JSON-RPC
  (`array<string, mixed>`), and `resourceProviders()` / `tools()` are precisely `list<…Interface>`. The new
  registration code introduced no weak types. No finding.

---

### Rejected (looks weak, is correct — do NOT "fix")

- **`Submission::$data` → `?array`.** Already documented `@var array<string, mixed>|null` (Submission.php:15).
  This is the decoded-JSON submission boundary; `mixed` element is honest. Not a finding.
- **MCP `array<string, mixed> $arguments` on every `call()`.** Inherited `ToolInterface` boundary for
  client-supplied, shape-validated args. Stays.
- **`groupKeys()` reading `($submission->data ?? [])[$groupBy]` as `mixed`.** Correct — a single field value out of
  the schemaless `data` blob is genuinely `mixed`; the method immediately guards (`is_array`, `(string)`). Stays.
- **`FormPresenter::form()/fields()` `array<string, mixed>` / `list<array<string, mixed>>`.** Pre-existing
  (#65), out of round-2 scope, and the field `config` is decoded JSON → honest `mixed`. Not touched.

---

## High-confidence implementation checklist

All three are **PHPDoc-only**, additive, covariant with `ToolInterface::call()`, and PHPStan-probe-verified. Apply,
then run `composer phpstan` (expect it to stay `[OK] No errors`).

- [ ] **H1** `src/mcp/tools/DetectSpamPatternsTool.php:83` — replace `@return array<string, mixed>` with
  `@return array{scanned:int,flaggedCount:int,linkThreshold:int,signals:list<string>,flagged:list<array{id:int,dateCreated:?string,signals:list<string>,linkCount:int,text:string}>}|array{isError:true,error:string}`.
- [ ] **H2** `src/mcp/tools/CategorizeSubmissionsTool.php:62` — replace `@return array<string, mixed>` with
  `@return array{count:int,groupBy:?string,textFields:list<string>,groups:list<array{value:string,count:int,submissionIds:list<int>}>,corpus:list<array{id:int,dateCreated:?string,fields:array<string,string>}>}|array{isError:true,error:string}`.
- [ ] **H3** `src/mcp/tools/SummarizeSubmissionsTool.php:64` — replace `@return array<string, mixed>` with
  `@return array{count:int,totalMatched:int,fields:list<string>,wordCount:int,corpus:list<array{id:int,dateCreated:?string,fields:array<string,string>,text:string}>}|array{isError:true,error:string}`.

Optional (MEDIUM, documentary): add inline `@var array<string, array{count?:int,submissionIds?:list<int>}> $groups`
at `CategorizeSubmissionsTool.php:86`.

After applying H1–H3, re-run `composer phpstan`. If any tool later adds a non-error early `return` not captured by
the union above, widen that one tool's `@return` to include the new branch (do not fall back to `array<string, mixed>`).
