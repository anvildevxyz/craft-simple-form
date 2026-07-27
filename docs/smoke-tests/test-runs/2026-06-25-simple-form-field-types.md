# Smoke run — Signature / Repeater / Calculation fields (S34–S42)

**Date:** 2026-06-25 · **Plugin:** simple-form · **Env:** craft-plugin-dev.ddev.site (MySQL 8)

## Method
Seeded a `fieldSmoke` form (quantity, price, total = `{quantity} * {price}`, an
`attendees` repeater with min 1 / max 3, a required signature) with correct field
config via a throwaway console command, then verified the rendered markup (curl)
and the full server behaviour through the real HTTP submit path
(`/actions/simple-form/submit`, captcha temporarily disabled via a config
override, restored after). The CP drag-drop builder UI (S34/S37/S40) was not
browser-automated — config shapes were seeded and verified in
`simpleform_fields.config`; the builder UI is covered by `FieldBuilderCest` + the
integration suite.

## Results — all passed, 0 plugin bugs

| Scenario | Result | Evidence |
|---|---|---|
| S35 Signature render + submit → asset | ✅ | `<canvas role="img" aria-label=…>` + hidden data-URL input; submit stored `value: [9306]`; asset `9306` = `signature-41-…png` (image, `uploads`) — not base64 |
| S36 Required signature blocks submit | ✅ | empty signature → `{"field_sig":["This field is required."]}`, no row |
| S38 Repeater → ordered list | ✅ | stored `[{attendeeName:"Ada",attendeeEmail:"ada@example.test"}]` |
| S39 Repeater over-max rejected | ✅ | 4 rows (max 3) → "Add no more than 3 row(s)." |
| S41 Calc live `<output>` + server total | ✅ | `<output data-sf-formula="{quantity} * {price}" data-sf-refs='["quantity","price"]'>`; submit stored `value: 12, display: "$12.00"` |
| S42 Forged total ignored | ✅ | posted `total=999` → stored server-recomputed `12`, never `999` |

## Notes
- Two first-pass "failures" were **harness errors, not bugs**, caught before reporting:
  1. A brace-less formula `quantity * price` computed `0` — the correct syntax is `{quantity} * {price}` (per the field docblock + `CalculationSubmissionTest`).
  2. Driving `SubmissionService::submit()` directly stored the signature as a raw data-URL — the base64→asset decode lives in `createFromRequest()` (the HTTP layer), which `submit()` bypasses. Through the real HTTP path it converts correctly.
- Environment fix during setup: the dev front-end was returning 503 (Craft's own DB schema was update-pending); `php craft up` cleared it.
- Cleanup: throwaway controller deleted, seed form + submissions removed, captcha config override reverted.
