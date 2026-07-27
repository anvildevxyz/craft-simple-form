# Concern 8 — AI slop / stubs / unhelpful & in-motion comments

**Date:** 2026-06-27 (re-run focused on net-new code)
**Scope:** Net-new code (coupons #246, address autocomplete #250, workflow #248,
conversational theme #243, logic jumps #245, payments #116, review-fix commit
c274dea) plus a full sweep of `src/`. (A prior round's report is preserved at
`08-comments-slop.prev.md`.)

## Critical assessment

The net-new code is, for this concern, **clean to the point of being exemplary**.
Every comment I inspected falls squarely in the "KEEP/improve" bucket the brief
defines: concise rationale, Craft/concurrency gotcha warnings, and issue refs
(`#246`, `#248`, `#250`, etc.) used for genuine traceability. There is no
restating-the-code narration, no "now we… / previously X now Y" change-logs in
comments, no placeholder/stub bodies, no fake `example.com`/`foo`/`lorem` larp,
no empty docblocks, and no banner-only filler.

Specific evidence:

- **In-motion narration:** the only grep hit for change-log phrasing in source is
  `web/assets/form/dist/js/simple-form.js:1106` ("changed, so an unrelated resize
  doesn't wipe an in-progress signature") — that describes a runtime condition
  (the size *changed*), not code history. All `previously` / `no longer` /
  `used to` matches (`services/CouponsService.php:173`,
  `services/FieldSyncService.php:390,559`, `services/ReportsService.php:352`,
  `controllers/FormsController.php:113`, `templates/settings/_tabs/coupons.twig:19`)
  describe **current data state, current behavior, or UI copy** — not edits.
  Correct usage; keep.
- **The review-fix commit (c274dea)** is the highest-risk candidate for leaked
  changelog comments, because its commit *message* is a detailed before/after
  narrative. I diffed every added comment line: they are all forward-looking
  rationale — e.g. `services/PaymentsService.php:182` ("…the race where two
  simultaneous submits both pass evaluate()'s usage…"),
  `web/assets/form/dist/js/simple-form.js:958` ("Sequence guard: a slow earlier
  response must not overwrite…"), and the `tryConsume`/`releaseUsage` docblocks in
  `services/CouponsService.php:147-154,172-176`. The changelog correctly lives in
  the commit message, not the code.
- **Stubs/TODOs:** zero `TODO`/`FIXME`/`HACK`/"not implemented" in source. The one
  "NOT implemented yet" string (`controllers/McpController.php:27`) is a deliberate
  rationale note explaining a scoped-out streaming transport with the dispatch
  isolated — a legitimate design comment, not a stub body.
- **"dummy"/"placeholder"/"stub" grep hits** are all real domain concepts: Craft's
  `DummyCache` class (`models/Settings.php:128`,
  `services/FormStructureService.php:22,143,187`), the repeater `__INDEX__`
  `INDEX_PLACEHOLDER` constant (`fields/RepeaterFieldType.php:39`),
  disabled-integration import placeholders
  (`services/FormPortabilityService.php:26,997,1030`), and `placeholder` HTML
  attributes. None are slop.
- **Docblock quality:** the service/model/event headers (`CouponsService`,
  `CouponModel`, `WorkflowService`, `WorkflowTransitionEvent`, `AddressFieldType`,
  `CompositeFieldType`) are concise, accurate, carry `@author`/`@since`, and
  explain *why* (e.g. the atomic-claim docblock at `CouponsService.php:147-154`,
  the "Entirely inert when disabled" note at `WorkflowService.php:19-21`). No
  redundant "Get the X." getter docs anywhere (grep returned nothing).
- **JS comments** (`simple-form.js`, ~148 comment lines) are dense but every one
  earns its place: PHP-parity sync warnings, server-is-authoritative reminders,
  a11y rationale (`#105`), best-effort/swallow-error explanations, and
  progressive-enhancement notes (e.g. address autocomplete `:836-839`, coupon
  preview `:760-763`). These are the kind of comments that should be written more
  often, not removed.
- **Twig comments** in the net-new templates (`_form/form.twig`,
  `settings/_tabs/workflow.twig`, `submissions/{view,index}.twig`) are short
  section markers or genuine rationale (e.g. "Links so it works without JS",
  "Logs come newest-first"). No slop.

## Recommendations

**None.** The code is already clean for this concern. There is no slop, stub,
larp, or unhelpful/in-motion comment to remove, and no comment that needs
tightening for a new reader. Recommending edits here would be inventing work and
would risk stripping the intentional rationale/traceability comments the brief
explicitly says to preserve.

## Verdict

Largely clean: **yes** (fully clean for this concern).
High-confidence recommendations: **0**. Medium: **0**.
