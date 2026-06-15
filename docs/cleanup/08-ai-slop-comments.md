# 08 — AI Slop, Stubs, LARP & Unhelpful Comments (Assessment)

**Scope:** `src/**` (~72 PHP files) + `src/templates/**` Twig/inline-JS.
**Phase:** 1 — assessment only. No source files were edited.
**Method:** four parallel read-throughs (MCP; services + Plugin + WIP `FieldSyncService`; elements/fields/models/migrations; controllers/gql/helpers/templates), each instructed to grep callers for any suspected stub. All non-trivial findings were re-read by hand in context before being recorded here.

## Summary

The codebase is **remarkably clean**. The comments that exist are overwhelmingly *why*-comments — Craft/Yii propagation rules, the json-column double-encode gotcha, FK cascade behaviour, CSRF/auth posture, cache-invalidation scope. There is **no LARP**: no `throw new \Exception('not implemented')`, no no-op methods pretending to work, no leftover sample/example code, and no TODO/FIXME scaffolding. The one "NOT implemented yet" string (SSE streaming in `McpController`) is a *deliberate, documented scope boundary* with the dispatch isolated so it can be added later — **KEEP**, not a stub.

The WIP files (`FieldSyncService`, the MCP tools) are **not** carrying AI-narration slop — their comments are substantive. `FieldSyncService` is fully wired (`FormsController::actionSave` → `validate()`/`sync()`), so it is not a stub.

What remains is a small amount of low-stakes noise:
- **4 HIGH restatement comments** in `TwigExtension.php` (label what the next line obviously does).
- **2 HIGH/REPLACE change-history phrasings** in the init migration ("now", "unchanged; still") — the *rationale* in each is worth keeping, only the history framing should go.
- **7 LOW decorative banner comments** in the inline JS of `forms/edit.html` (cosmetic dashes; the section labels themselves have navigational value in a 629-line file).
- A handful of **LOW** mild restatements in controllers / GQL / one Twig block.

### Inter-agent disagreements I resolved by re-reading

- `McpServer.php:95` and `:130` were flagged by the elements-agent as "incomplete fragment" comments. **False positive** — those are mid-sentence line-wraps of complete, useful two-line comments (JSON-RPC notification semantics; `listChanged:false` rationale). The MCP agent, which read whole files, correctly passed them. **KEEP.**
- `FieldSyncService.php:174` was flagged DELETE as "restatement". **Overridden to KEEP** — it explains *why* the cache is dropped (the field set changed) and that the scope is all sites, which the `invalidate()` name alone does not convey.

## Findings

### HIGH — safe to DELETE (pure restatement)

| File:line | Exact text | Class | Notes |
|---|---|---|---|
| `src/TwigExtension.php:58` | `// Add honeypot field when enabled` | DELETE | Restates the `if ($settings->enableHoneypot)` directly below. |
| `src/TwigExtension.php:63` | `// Render form fields` | DELETE | Restates the `foreach ($fields as $field)` render loop. |
| `src/TwigExtension.php:91` | `// Captcha widget when enabled` | DELETE | Restates `$this->renderCaptcha($settings)` (which already gates on the setting). |
| `src/TwigExtension.php:94` | `// Submit button` | DELETE | Restates the `<button type="submit">` line. |

### HIGH — REPLACE (keep the rationale, drop the change-history framing)

| File:line | Exact text | Class | Proposed replacement |
|---|---|---|---|
| `src/migrations/m240614_000001_init.php:23` | `// Handle is shared across sites now, so it is globally unique.` | REPLACE | `// Handle is shared across all sites, so the index is globally unique (not per-site).` |
| `src/migrations/m240614_000001_init.php:79` | `// Submissions table — unchanged; formId still resolves to simpleform_forms.id (the element id)` | REPLACE | `// formId references simpleform_forms.id, which is the Form element id.` |

> Rationale: both use "now"/"unchanged…still" to describe state relative to a prior version, but this is a brand-new `init` migration — there is no prior version for a reader. The *content* (shared-handle uniqueness; formId → element id) is genuinely useful, so strip only the history framing.

### LOW — decorative banner comments (cosmetic AI-tell; labels have some value)

Inline `<script>` field-builder in a 629-line template. The dashed-banner *style* is the AI-tell; the section *labels* aid navigation in a long script. Recommend either leave as-is or de-decorate to a plain `// canvas rendering` form. Not worth a churny diff on its own.

| File:line | Exact text | Class |
|---|---|---|
| `src/templates/forms/edit.html:241` | `// ---- canvas rendering ----…` | DELETE (LOW) |
| `src/templates/forms/edit.html:275` | `// ---- mutation ----…` | DELETE (LOW) |
| `src/templates/forms/edit.html:301` | `// ---- inspector ----…` | DELETE (LOW) |
| `src/templates/forms/edit.html:450` | `// ---- canvas events: select / delete ----…` | DELETE (LOW) |
| `src/templates/forms/edit.html:459` | `// ---- drag & drop ----…` | DELETE (LOW) |
| `src/templates/forms/edit.html:518` | `// ---- submit guard ----…` | DELETE (LOW) |
| `src/templates/forms/edit.html:550` | `// ---- init ----…` | DELETE (LOW) |

### LOW — mild restatements (optional)

| File:line | Exact text | Class | Notes |
|---|---|---|---|
| `src/controllers/SubmissionsController.php:84` | `// Get submission statistics` | DELETE (LOW) | Restates `$this->getSubmissionStats(...)`. |
| `src/controllers/SubmissionsController.php:78` | `// Get all forms for filter dropdown` | KEEP/LOW | Borderline — adds the *purpose* ("for filter dropdown"). Lean KEEP. |
| `src/gql/mutations/FormMutations.php:91` | `// Build the field-id => value map from the input list.` | KEEP/LOW | Clarifies the output shape of the loop; lean KEEP. |
| `src/templates/submissions/index.html:66` | `{# Pagination #}` | DELETE (LOW) | Labels the pagination block; trivial. |
| `src/helpers/SiteHelper.php:51` | `/** Set the current site and application language. */` | KEEP | Accurate one-liner on a private helper; fine. |

### KEEP — confirmed good comments (do NOT strip)

These were checked because they sit near flagged areas or looked terse; all encode real Craft/Yii or domain knowledge:

- `src/services/FieldSyncService.php:13–17, 24–25, 106, 115, 137, 162, 174–176` — immutable-type, per-site label upsert, json double-encode gotcha, FK cascade, cross-site cache invalidation.
- `src/controllers/FieldsController.php:60, 124, 128` — json double-encode gotcha; shared-column (no site filter) semantics.
- `src/controllers/McpController.php:13–41` — transport notes; SSE-deferred scope boundary; CSRF-disabled / anonymous-allowed / off-by-default security posture.
- `src/mcp/McpServer.php:94–95, 130` — JSON-RPC notification semantics; `listChanged:false` rationale.
- `src/mcp/TokenManager.php:10–40` — token security model (HMAC-SHA256 rationale, timing).
- `src/migrations/m240614_000001_init.php:25, 28` — "the plugin row IS the element; deleting cascades"; per-site translatable content.
- `src/services/FormStructureService.php:12–27` — what is/isn't cached, dev-mode bypass, tag-based invalidation.
- `src/templates/forms/edit.html:8–9, 29, 32` — native CP site-selector mechanism; siteId hidden-input purpose; `fieldsData` serialisation contract.
- `src/services/CaptchaService.php:11–27` — verify-returns-true-when-disabled contract.

## Stubs / LARP / no-op check

**None found.** Explicitly verified:
- No `throw new \Exception('not implemented')` / "not implemented" stubs. The only "NOT implemented yet" (`McpController.php:26`, SSE streaming) is a documented, intentional scope boundary — KEEP.
- No TODO / FIXME / XXX / HACK markers anywhere in `src/`.
- No `for now` / `coming soon` / placeholder-implementation comments. (`FormFieldType.php:60` "Placeholder text, when configured." is a GraphQL field *description* for the field's HTML placeholder attribute — not a stub.)
- `FieldSyncService` (the freshest WIP) is fully implemented and wired up via `FormsController` (`new FieldSyncService()` → `validate()` at :116, `sync()` at :129). Not a stub.

## High-confidence implementation checklist (Phase 2)

Apply these in `src/` — all HIGH confidence, no behaviour change:

1. **DELETE** `src/TwigExtension.php:58` — `// Add honeypot field when enabled`
2. **DELETE** `src/TwigExtension.php:63` — `// Render form fields`
3. **DELETE** `src/TwigExtension.php:91` — `// Captcha widget when enabled`
4. **DELETE** `src/TwigExtension.php:94` — `// Submit button`
5. **REPLACE** `src/migrations/m240614_000001_init.php:23` → `// Handle is shared across all sites, so the index is globally unique (not per-site).`
6. **REPLACE** `src/migrations/m240614_000001_init.php:79` → `// formId references simpleform_forms.id, which is the Form element id.`

Optional (LOW, batch with other template work if touching `forms/edit.html`): de-decorate the 7 banner comments at lines 241/275/301/450/459/518/550 to plain labels, and drop `{# Pagination #}` / `// Get submission statistics`.

**Do NOT touch** anything in the KEEP list above — those comments encode real Craft/Yii/domain knowledge and the two cross-agent false positives (`McpServer.php:95/130`).

## Counts

- **HIGH DELETE:** 4 (all `TwigExtension.php`)
- **HIGH REPLACE:** 2 (init migration)
- LOW DELETE (optional): ~9 (7 banner + 2 mild restatements)
- KEEP / false-positives defended: ~20 comment groups
- Stubs / LARP / TODO: **0**
