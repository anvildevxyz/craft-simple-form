# Multi-field rows: drag-to-pair + column widths

Verifies the form builder lets authors place fields side by side by dragging one
onto another's edge, and lets each column carry a relative width.

## Preconditions
- Logged into the CP. A blank new form (`/admin/simple-form/forms/new`).

## Steps
1. **SETUP** — Add two `text` fields via the palette (click "Text" twice).
2. **EXECUTE** — Drag the second field onto the **right edge** of the first.
   - Native HTML5 DnD is hard to drive in Playwright; dispatch the real
     `dragstart` → `dragover` → `drop` events with a shared `DataTransfer`,
     using `clientX = rect.right - 5` of the first field so `dropTarget()`
     resolves to the right-edge band.
3. **VERIFY (UI)** — `#sf-canvas` now has a `.sf-builder-row[data-cols="2"]`
   wrapper with inline `--sf-cols: 1fr 1fr`; both fields' serialized config has
   `row: 1`.
4. **EXECUTE** — Select the first field; in the inspector set **Column Width**
   to **Wide (2×)**.
5. **VERIFY (UI)** — The inspector hint reads `This row: 2 : 1.`; the canvas row's
   `--sf-cols` becomes `2fr 1fr`; the field's config gains `width: 2`.
6. **EXECUTE** — Fill name/handle, **Save**.
7. **VERIFY (DB)** —
   `SELECT config FROM simpleform_fields WHERE formId={id} ORDER BY sortOrder;`
   → first `{"row": 1, "width": 2}`, second `{"row": 1}`.
8. **VERIFY (Front end)** — Render `{{ simpleForm('{handle}') }}`; the markup
   contains `<div class="simple-form-row" data-cols="2" style="--sf-cols: 2fr 1fr;">`
   with two `.simple-form-col` children.

## Notes
- The `--sf-cols` custom property (not an inline `grid-template-columns`) is used
  so the stylesheet's `@media (max-width: 600px)` collapse still wins on mobile.
- Backed end-to-end by `tests/integration/FormColumnLayoutRenderTest.php` and the
  Node parity test `tests/js/row-grouping.test.js`.
