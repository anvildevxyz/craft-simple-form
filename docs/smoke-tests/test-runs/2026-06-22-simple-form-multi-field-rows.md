# Smoke run: multi-field rows (drag-to-pair + column widths) — 2026-06-22

Form: `rowSmokeTest` (id **9163**, created during this run — test data).

| # | Step | Result |
|---|------|--------|
| 1 | Add two text fields via palette | ✓ 2 fields in canvas |
| 2 | Drag field 2 onto field 1's right edge (real drop event) | ✓ `.sf-builder-row[data-cols="2"]`, `--sf-cols: 1fr 1fr`, both `row:1` |
| 3 | Inspector → Column Width = Wide (2×) on field 1 | ✓ hint "This row: 2 : 1.", `--sf-cols: 2fr 1fr`, config `width:2` |
| 4 | Save form | ✓ redirected to edit/9163 |
| 5 | DB: `simpleform_fields` config | ✓ `{"row":1,"width":2}` / `{"row":1}` |
| 6 | Front-end render `simpleForm('rowSmokeTest')` | ✓ `class="simple-form-row" data-cols="2" style="--sf-cols: 2fr 1fr;"`, 2 cols |
| 7 | Logs | ✓ no exceptions (only dev-mode request dump) |

All steps passed. Cosmetic fix applied after the run: width option labels
3×/4× now read "Wider"/"Widest" (were both "Wide").

Test data left behind: form id 9163 (`rowSmokeTest`) — delete if not wanted.
