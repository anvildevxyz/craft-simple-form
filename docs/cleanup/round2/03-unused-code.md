# Concern #3 — Unused Code (Assessment) — ROUND 2

Plugin: **Simple Form** (Craft CMS 5, PHP 8.2) — `plugins/simple-form`
Scope (this round): the NEW MCP "insight tools" + "resources" feature ONLY:

- `src/mcp/tools/{DetectSpamPatternsTool,CategorizeSubmissionsTool,SummarizeSubmissionsTool}.php`
- `src/mcp/tools/support/InsightCorpus.php`
- `src/mcp/resources/{ResourceProviderInterface,FormSchemaResource,SubmissionsDatasetResource}.php`
- `src/mcp/McpServer.php` (the new `resourceProviders()` / `resources/*` paths)

Phase: **ASSESSMENT ONLY** — no source files were edited, created, or deleted.
Date: 2026-06-15

Round-1 context: the round-1 cleanup (`a56e6ed`) already removed the
`ElementQueryHelper` class and the dead `FormModel`/`FieldModel` getters. None of
those re-appear here. This round targets the freshly-added MCP insight/resource
surface that round-1 explicitly called out as "WIP, fully wired, do not touch" —
it is now in `main`'s committed `McpServer.php`, so re-verification is warranted.

---

## Tooling

`composer-unused` and `composer-require-checker` are **not** in `vendor/bin/`
(verified — `vendor/bin` holds: carbon, codecept, ecs, markdown, php-parse,
phpstan, phpstan.phar, phpunit, psysh, var-dump-server, yaml-lint, yii). No new
tooling was installed. Two installed tools were run as corroborating signals;
**grep remained the authority for every verdict**:

1. **ECS** (`vendor/bin/ecs check <file>`) — ran clean (`[OK] No errors found`)
   on the resource + tool files. NOTE: ECS's `NoUnusedImportsFixer` is **not**
   enabled in this project's `ecs.php`, so ECS does **not** detect unused
   imports here — its OK verdict is not evidence an import is used.
2. **PHPStan 1.12** at the project's configured level
   (`vendor/bin/phpstan analyse <file>`) — `[OK] No errors`. PHPStan 1.x does not
   flag unused imports by default either. So neither tool catches the dead
   import below; **grep does**.

Grep note: the shell's `grep` is `ugrep`, which rejects a leading `->` as an
option. All method-call greps below therefore use `grep -nF -e '...'` (fixed
string, no leading-dash ambiguity) or `grep -nE '(->|::)name\b'`.

---

## Summary

The new MCP insight/resource surface is **almost entirely live and reachable**.
All three insight tools are registered + dispatched by name, every private helper
inside them is called, every `InsightCorpus` public method and both its constants
are consumed, and every resource class is registered + dispatched. Two genuinely
dead symbols survived verification:

| # | Symbol | Kind | Confidence |
|---|--------|------|------------|
| 1 | `ResourceProviderInterface::scheme()` + its 2 implementations (`FormSchemaResource::scheme()`, `SubmissionsDatasetResource::scheme()`) | dead interface method + impls (never invoked) | **HIGH** |
| 2 | `use fabianhaef\simpleform\elements\Submission;` in `SubmissionsDatasetResource.php` | unused `use` import | **HIGH** |

**HIGH-confidence removals: 2.**

Everything else in scope was confirmed reachable (see the "LOOK unused but NOT"
section).

---

## Findings

### 1 — `scheme()` interface method + both implementations — **HIGH**

`ResourceProviderInterface::scheme()` (interface l.23) is declared in the
contract and implemented in both providers (`FormSchemaResource::scheme()` l.22,
`SubmissionsDatasetResource::scheme()` l.28, each `return self::SCHEME`). But
**nothing ever calls `scheme()`**. The dispatcher in `McpServer` routes a
`resources/read` URI to a provider purely via `handles($uri)`
(`McpServer.php:321`), and lists via `list()` — it never asks a provider for its
scheme. Each provider's own `handles()` / `list()` / `read()` build their URIs
from the private `self::SCHEME` const directly, not via the public `scheme()`
method.

Proof (zero call sites, whole tree):

```
# any call of scheme() as a method (instance or static), all of src/tests/config:
grep -rnE '(->|::)scheme\b' src tests config
#   (no output) — scheme() is never invoked anywhere

# the interface methods that ARE dispatched polymorphically by McpServer
# (for contrast — scheme() is conspicuously absent):
grep -nF -e '->requiredScope(' -e '->list(' -e '->handles(' -e '->read(' src/mcp/McpServer.php
#   297,333  $provider->requiredScope()
#   300      $provider->list()
#   321      $candidate->handles($uri)
#   353      $provider->read($uri)
#   (no $provider->scheme() — it is never called)

# only definitions exist:
grep -rn 'function scheme' src
#   src/mcp/resources/ResourceProviderInterface.php:23  (declaration)
#   src/mcp/resources/FormSchemaResource.php:22         (impl, return self::SCHEME)
#   src/mcp/resources/SubmissionsDatasetResource.php:28 (impl, return self::SCHEME)
```

The `private const SCHEME` in each provider is **still used** internally (by
`handles()`/`list()`/`read()` — see grep in finding-NOT list), so the const stays;
only the public `scheme()` accessor is dead.

**Why HIGH (not downgraded for being an interface method):** `scheme()` is not
framework-dispatched. MCP/Yii never call it — the only consumer that *could* call
it is this plugin's own `McpServer`, and it demonstrably does not. It is internal
to the plugin (the interface is not part of any third-party extension contract;
`ResourceProviderInterface` is implemented only by these two in-tree classes —
`grep -rln "implements ResourceProviderInterface" src` → exactly the two
providers). So this is dead within a closed set, grep-proven, with zero dynamic
-dispatch escape hatch.

**Recommendation:** remove `scheme()` from the interface and from both providers
(3 symbols). If a defensive "keep the contract method for future providers"
argument is preferred, it could be downgraded to MEDIUM and kept as documented
API — but as written it is unreferenced dead code. The const-backed routing via
`handles()` is the actual mechanism, so `scheme()` is redundant.

Re-verify before removing:
`grep -rnE '(->|::)scheme\b' src tests config` → expect no output.

---

### 2 — Unused `use` import: `Submission` in `SubmissionsDatasetResource.php` — **HIGH**

`src/mcp/resources/SubmissionsDatasetResource.php:6` imports
`use fabianhaef\simpleform\elements\Submission;`. The class **never references the
`Submission` symbol** — no type hint, no `Submission::`, no `instanceof`, no
docblock `@param Submission`. The submission rows it returns come from
`SubmissionQueryBuilder::present($s, true)` (l.93), where `$s` is an untyped
closure param. The only textual "Submission" hit in the file body is the **string
literal** `'Submission dataset (up to …'` at l.53 — a coincidence, not a use.

Proof:

```
# every occurrence of the bare word "Submission" in the file:
grep -nE '\bSubmission\b' src/mcp/resources/SubmissionsDatasetResource.php
#   6:  use fabianhaef\simpleform\elements\Submission;      <- the import itself
#   53: 'description' => 'Submission dataset (up to ...'     <- STRING LITERAL, not the class
#   (note: "SubmissionQueryBuilder" matches at 8/17/84/93 are a DIFFERENT symbol,
#    its own import on l.8 — \bSubmission\b would also catch them, so confirmed
#    the import on l.6 has no code/docblock consumer)

# confirm no type-hint / static / instanceof use of the Submission class:
grep -nE 'Submission(\s*\$|::|\)|>| )' src/mcp/resources/SubmissionsDatasetResource.php \
  | grep -v 'SubmissionQueryBuilder' | grep -v 'use fabianhaef'
#   53: ... 'Submission dataset ...'   <- only the string literal
```

Neither ECS (NoUnusedImports not enabled) nor PHPStan 1.12 (default level)
flagged this, so grep is the authority. Contrast with `DetectSpamPatternsTool.php`
and `CategorizeSubmissionsTool.php`, whose `use ...Submission` imports ARE used
(docblock `@param list<Submission>` and a `Submission $submission` type hint
respectively — see NOT list) — those stay.

**Recommendation:** delete line 6 (`use fabianhaef\simpleform\elements\Submission;`)
from `SubmissionsDatasetResource.php`. Purely cosmetic safety; no runtime effect.

Re-verify before removing:
`grep -nE '\bSubmission\b' src/mcp/resources/SubmissionsDatasetResource.php`
→ expect only l.6 (import) and l.53 (string literal).

---

## Things that LOOK unused but are NOT (do not remove)

Verified reachable via grep / framework dispatch:

### The three insight tools — all live
`SummarizeSubmissionsTool`, `CategorizeSubmissionsTool`, `DetectSpamPatternsTool`
are each instantiated in `McpServer::tools()` (l.78–80) and dispatched by name in
`handleToolCall()`. Their `name()` strings are also exercised by the integration
suite.

```
grep -rln 'SummarizeSubmissionsTool\|CategorizeSubmissionsTool\|DetectSpamPatternsTool' src tests
#   src/mcp/McpServer.php + each tool's own file  (registered)
grep -rn "'summarize_submissions'\|'categorize_submissions'\|'detect_spam_patterns'" src tests
#   each tool's name() + tests/integration/McpInsightToolsTest.php (3 call sites + a loop)
```

### Every private helper in the three tools — all called
```
grep -nF -e 'this->normalize' -e 'this->countLinks' -e 'this->isShouting' -e 'this->resolveForm' \
  src/mcp/tools/DetectSpamPatternsTool.php
#   resolveForm:101  normalize:112,125  countLinks:130  isShouting:135   (all called)
grep -nF -e 'this->groupKeys' -e 'this->shapeGroups' -e 'this->resolveGroupBy' -e 'this->resolveForm' \
  src/mcp/tools/CategorizeSubmissionsTool.php
#   resolveForm:78  resolveGroupBy:82  groupKeys:98  shapeGroups:110       (all called)
grep -nF -e 'this->resolveHandles' -e 'this->resolveForm' src/mcp/tools/SummarizeSubmissionsTool.php
#   resolveHandles:81  resolveForm:120                                     (all called)
```

### Every tool constant — all used
```
DetectSpamPatternsTool:  MAX_ROWS:97  DEFAULT_LINK_THRESHOLD:69,99  SHOUTING_MIN_LENGTH:174
Categorize/Summarize:    MAX_ROWS used at l.76 / l.77 respectively
```

### `InsightCorpus` — every public method + both constants consumed
```
grep -rn 'InsightCorpus::fieldTypes\|InsightCorpus::freeTextHandles\|InsightCorpus::textValues' src
#   fieldTypes      -> Categorize:79, Detect:103, Summarize:122
#   freeTextHandles -> Categorize:80, Detect:103, Summarize:122
#   textValues      -> Detect:110, Summarize:86, Categorize:92
grep -rn 'FREE_TEXT_TYPES\|InsightCorpus::OPTION_TYPES' src
#   FREE_TEXT_TYPES -> InsightCorpus.php:56  (self::, inside freeTextHandles)  -> USED
#   OPTION_TYPES    -> CategorizeSubmissionsTool.php:161 (InsightCorpus::OPTION_TYPES) -> USED
```
(The FieldOps / FieldSyncService / edit.html `OPTION_TYPES` matches are **separate,
independent constants** of the same name — not `InsightCorpus`'s. `InsightCorpus::
OPTION_TYPES` is the const consumed by `CategorizeSubmissionsTool`.)

### Resource providers — both registered + dispatched
`FormSchemaResource` + `SubmissionsDatasetResource` are instantiated in
`McpServer::resourceProviders()` (l.94, l.96), then dispatched via `resources/list`
(`resourceDescriptors()` → `$provider->list()`) and `resources/read`
(`handleResourceRead()` → `$candidate->handles($uri)` → `$provider->read($uri)`).
The integration suite `tests/integration/McpResourcesTest.php` hits both
`resources/list` and `resources/read` for `form://…` and `submissions://…` URIs.

### Resource interface methods (except `scheme()`) — polymorphically dispatched
`requiredScope()`, `list()`, `handles()`, `read()` are all invoked on the
`ResourceProviderInterface` type in `McpServer` (lines 297/333, 300, 321, 353).
Only `scheme()` is dead (finding #1).

### Resource constants — `SCHEME` and `MIME` both used; `MAX_ROWS` used
```
FormSchemaResource:        SCHEME -> 24,44,58,66   MIME -> 49,82
SubmissionsDatasetResource: SCHEME -> 30,50,64,72  MIME -> 55,108  MAX_ROWS -> 53,91
```
`SCHEME` stays even after removing `scheme()` (used by `handles()`/`list()`/`read()`).

### Other imports in scope — all referenced
- `SubmissionQuery` in all three tools — used in the `/** @var SubmissionQuery $query */`
  narrowing docblock (Summarize:71, Categorize:71, Detect:91). Docblock type use
  is a real use (PHPStan/IDE consume it); keep.
- `Submission` in `DetectSpamPatternsTool` (docblock `@param list<Submission>` l.184)
  and `CategorizeSubmissionsTool` (`Submission $submission` type hint l.121 +
  docblock l.171) — both genuinely used; keep. **Only** the
  `SubmissionsDatasetResource` `Submission` import is dead (finding #2).
- `Form`, `Scopes`, `InsightCorpus`, `SubmissionQueryBuilder`, `FormPresenter`
  imports — all have real body references in every file (per-file token scan:
  body-ref counts all ≥ 1, no zero-ref import other than the one in finding #2).

---

## High-confidence implementation checklist (grep-proven-safe removals)

Two HIGH-confidence items:

- [ ] **Remove the unused import** in
      `src/mcp/resources/SubmissionsDatasetResource.php` — delete line 6
      `use fabianhaef\simpleform\elements\Submission;`. Zero code/docblock use;
      only a string-literal coincidence. Re-verify:
      `grep -nE '\bSubmission\b' src/mcp/resources/SubmissionsDatasetResource.php`
      → expect only l.6 (the import) and l.53 (string literal).

- [ ] **Remove the dead `scheme()` method** — from
      `ResourceProviderInterface` (declaration), `FormSchemaResource` (impl), and
      `SubmissionsDatasetResource` (impl). Never invoked; routing uses
      `handles()`. Keep each provider's `private const SCHEME` (still used by
      `handles()`/`list()`/`read()`). Re-verify:
      `grep -rnE '(->|::)scheme\b' src tests config` → expect no output.
      (If a "reserve the contract method for future providers" policy is
      preferred, downgrade this to a deliberate-keep instead — but as written it
      is unreferenced.)

Nothing else in the scoped surface is removable: the three insight tools, all
their private helpers and constants, all `InsightCorpus` public methods +
constants, both resource providers' other methods/constants, and all remaining
`use` imports are grep-proven referenced or framework-dispatched.

---

## Commands run (for reproducibility)

```
# class registration / dispatch
grep -rln '{Summarize,Categorize,DetectSpam}SubmissionsTool' src tests
grep -rn  "'summarize_submissions'|'categorize_submissions'|'detect_spam_patterns'" src tests config

# InsightCorpus surface
grep -rn 'InsightCorpus::{fieldTypes,freeTextHandles,textValues}' src tests config
grep -rn 'FREE_TEXT_TYPES|OPTION_TYPES' src tests config

# private helpers + constants per tool (grep -nF -e 'this->...'; self::CONST)
# resource constants + interface-method dispatch in McpServer
grep -nF -e '->requiredScope(' -e '->list(' -e '->handles(' -e '->read(' src/mcp/McpServer.php
grep -rnE '(->|::)scheme\b' src tests config        # -> ZERO (finding #1)

# per-file import body-reference scan (every `use` token word-matched in body)
# unused-import confirm
grep -nE '\bSubmission\b' src/mcp/resources/SubmissionsDatasetResource.php   # -> finding #2

# corroborating tools (not authoritative here):
vendor/bin/ecs check src/mcp/resources/SubmissionsDatasetResource.php        # [OK] (NoUnusedImports not enabled)
vendor/bin/phpstan analyse src/mcp/resources/SubmissionsDatasetResource.php  # [OK] (1.12, no unused-import rule)
```
