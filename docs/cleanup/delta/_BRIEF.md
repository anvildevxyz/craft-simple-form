# Delta Cleanup Brief (2026-06-22)

Plugin: **simple-form** — a Craft CMS 5 PHP plugin.
Root: `/Users/fh/Documents/experiments/craft-plugin-dev/plugins/simple-form`
Branch: `cleanup/quality-delta`

## Scope: DELTA pass
A full 8-concern audit already ran on 2026-06-21 (reports: `docs/cleanup/01..08-*.md`).
The codebase came back largely clean. Do NOT re-audit from scratch.
Focus ONLY on PHP source changed since audit commit `c5b8fe7`. Get the list with:

    git diff --name-only c5b8fe7 HEAD -- 'src/**/*.php' | grep -v -E 'translations/|/dist/'

Read the matching prior report (`docs/cleanup/0N-*.md`) first so you build on it, not repeat it.

## HARD RULES
- This is PHP. Follow Craft conventions — load the `craft-php-guidelines` skill before assessing.
- The quality gate is `composer check` (ecs + phpstan --memory-limit=1G + phpunit). Do not propose changes that would break it.
- **DO NOT EDIT ANY SOURCE FILE IN THIS PHASE.** You produce a report only.
- **AVOID these files entirely** (uncommitted WIP — do not propose edits to them; you may read for context only):
  - src/controllers/FormsController.php
  - src/elements/Form.php
  - src/elements/db/FormQuery.php
  - src/services/FormRenderService.php
  - any file under src/templates/ and tests/

## Deliverable
Write `docs/cleanup/delta/0N-<concern>.md` containing:
1. **Critical assessment** of the delta code for your concern (what's actually wrong, with evidence; be honest if it's clean).
2. **High-confidence patch list**: each item = `file:line` + exact problem + exact proposed change + one-line justification. Only HIGH-confidence, gate-safe items. Mark anything speculative as "LOW confidence — skip".
3. A final **one-line verdict**: how many high-confidence patches you recommend.
Keep prose tight. No filler. The patch list is what matters — I will apply them serialized in Phase 2.
