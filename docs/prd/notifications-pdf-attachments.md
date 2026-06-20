# PRD — Submission PDF Generation & Notification Attachments

**Status:** Proposed
**Author:** Fabian Haefliger
**Date:** 2026-06-20
**Tracking issue:** [#143](https://github.com/fabianhaef/craft-simple-form/issues/143)

---

## 1. Problem Statement

Simple Form's notification emails (`NotificationsService` / `EmailService`) send an HTML body
built from a translatable, overridable Twig template
(`src/templates/forms/notifications`). Two recurring asks are unmet:

1. **A PDF of the submission** — a clean, archival, print-ready record attached to the
   notification email and downloadable from the CP. Order confirmations, applications,
   registrations, and consent forms all want "a PDF of what was submitted".
2. **The submitter's uploaded files** — a submission may carry file-field uploads (Assets), but
   the notification email today only links to them. Recipients want the actual attachments.

Both Formie and Freeform gate PDF generation behind their **Pro** tier. Shipping it in a
"simple" plugin is a real differentiator, provided the PDF library stays an *optional*
dependency so the core install footprint doesn't grow for users who don't need it.

## 2. Problem-fit / what exists today

- `EmailService::send()` composes via `Craft::$app->getMailer()->compose()` and currently sets
  `setHtmlBody()` only — no `attach()`. Yii's mailer `Message` supports `attach()` /
  `attachContent()`, so attachments are a small extension here.
- `EmailService::formatFileValue()` already resolves file-field asset ids → assets, so we
  already have the code to enumerate a submission's uploaded files.
- Notification config lives in `NotificationModel` (per-form, conditional, multiple) — the
  natural home for a per-notification "attach PDF" / "attach uploaded files" toggle.

## 3. Goals

- Generate a **PDF of a submission** from a translatable, overridable Twig template.
- **Attach the PDF** to notification emails, controlled by a per-notification toggle.
- **Attach the submission's uploaded files** to notification emails, controlled by a separate
  per-notification toggle.
- Make the PDF **downloadable** from the CP submission detail view (a controller action).
- **Optionally store the PDF as an Asset** in a configured volume (so it persists / is reusable).
- Keep the PDF library (**dompdf**) an **optional** Composer dependency: the feature gracefully
  degrades (toggles disabled + a clear "install dompdf" notice) when it's absent.
- Do the generation **off the request** (queue) so a large PDF never blocks the visitor's
  submit.

## 4. Non-Goals (v1)

- No WYSIWYG PDF designer — the layout is a Twig template the developer overrides.
- No headless-Chrome / wkhtmltopdf engine in v1 (dompdf only; engine is abstracted so another
  can be added later).
- No PDF for *autoresponder* visitor emails in v1 if it complicates the toggle model — but the
  per-notification toggle should make this fall out naturally (revisit in Open Questions).
- No bulk "export all submissions as PDFs" (the existing CSV/Excel exporters cover bulk; a PDF
  bulk export is future work).
- No password-protected / signed PDFs.

## 5. Proposed Solution

### 5.1 Optional dependency + engine seam

- Add `dompdf/dompdf` to `composer.json` **`suggest`** (not `require`), documented as the
  enabler for PDF features.
- A `PdfService` with an internal `PdfEngineInterface`; the dompdf implementation is selected
  only if the class exists (`class_exists(\Dompdf\Dompdf::class)`). When absent, `PdfService`
  reports unavailable; the CP toggles render disabled with a "Install dompdf to enable PDF
  attachments" notice, and `defineSettingsRules` / save validation prevents enabling a PDF
  toggle without the library.

### 5.2 Template contract

- A new overridable template `src/templates/forms/notifications/pdf.twig`, resolved through the
  same site-aware, user-overridable lookup the existing notification body uses, and rendered
  **sandboxed** (the plugin already renders notification bodies sandboxed —
  `EmailService::renderSandboxed()` — per the Twig-sandbox reference; the PDF body must use the
  same SecurityPolicy so a form author can't break out).
- Template variables: `form`, `submission`, `data` (the same trio passed to the body renderer),
  plus a small `pdf` helper for page metadata. Default template renders a titled table of
  field label → formatted value, reusing `formatFieldValue()` / `formatFileValue()`.
- Translatable: rendered per the notification's site so a per-site/translated PDF matches the
  per-site translatable email body that already exists.

### 5.3 `PdfService` API

```php
public function isAvailable(): bool;
/** Render the submission to PDF bytes (sandboxed Twig → engine). */
public function render(Form $form, Submission $submission, array $data, ?int $siteId = null): string;
/** Render + persist as an Asset in the configured volume; returns the Asset. */
public function store(Form $form, Submission $submission, array $data): ?Asset;
```

### 5.4 Per-notification toggles (`NotificationModel`)

- `NotificationModel::$attachPdf` (bool, default false).
- `NotificationModel::$attachUploads` (bool, default false).
- Migration extends the notifications table (cf. `m260618_000004_notifications`) with the two
  boolean columns. New columns only — no breaking change. Register in `codeception.yml` + reset
  `craft_test` (test-DB snapshot reference).

### 5.5 Email attachment path (`EmailService` / `NotificationsService`)

When a notification has `attachPdf`, the (already queued) send renders/loads the PDF via
`PdfService` and calls `$mail->attachContent($pdfBytes, ['fileName' => '…','contentType' => 'application/pdf'])`.
When `attachUploads`, it resolves the submission's file-field asset ids (reusing the existing
asset-enumeration logic) and `attach()`es each by path/stream, with a total-size guard
(skip + log if over a configurable cap to protect deliverability).

Because notification sends are already queue-based, PDF rendering happens on the worker, not in
the submit request. The submit path is unchanged.

### 5.6 CP download + optional Asset storage

- Controller action `submissions/pdf?submissionId=…` (CP-permission-gated) streams a freshly
  rendered (or stored) PDF — a "Download PDF" button on the submission detail view.
- Optional global setting `pdfStorageVolume` (a volume handle): when set, `PdfService::store()`
  saves the PDF as an Asset on save, and the detail view links to the stored Asset instead of
  rendering on demand. When unset, PDFs are always rendered on demand (no storage).

### 5.7 Performance / safety

- Rendering is queue-side; a render failure (missing library, template error) is caught and
  logged, the email still sends *without* the attachment (degraded, never a lost notification).
- Upload attachments respect a configurable total-attachment-size cap; over-cap uploads fall
  back to the existing in-body download links.
- PDF generation reuses the cached form structure where possible; no N+1 over fields.

## 6. Acceptance Criteria

- [ ] With dompdf installed, the per-notification "Attach PDF" toggle is enabled; without it,
      the toggle is disabled with an install notice and cannot be saved as on.
- [ ] A notification with "Attach PDF" sends an email carrying a `application/pdf` attachment
      rendering the submission's fields.
- [ ] The PDF is rendered from the overridable `pdf.twig`, sandboxed, per the notification's
      site (translatable).
- [ ] A notification with "Attach uploaded files" attaches the submission's file-field uploads;
      over the size cap, it falls back to in-body links and logs the skip.
- [ ] The CP submission detail view has a permission-gated "Download PDF" button that streams a
      correct PDF.
- [ ] With `pdfStorageVolume` set, the PDF is stored as an Asset and the detail view links to it.
- [ ] A render failure does not block or drop the notification email (sends without attachment,
      logs a warning).
- [ ] PDF generation runs on the queue, not in the submit request.
- [ ] PHPStan L7 + ECS clean; all strings via `Craft::t('simple-form', …)`.

## 7. Testing

### Unit
- `PdfService::isAvailable()` true/false by `class_exists` (engine seam).
- `render()` produces non-empty PDF bytes for a sample submission (skipped when dompdf absent).
- Sandboxed template rendering rejects a disallowed Twig construct (security policy honored).
- `EmailService` attaches the PDF when `attachPdf` and not when off (assert on composed
  message attachments via a test mailer).
- Upload-attachment size cap: under cap → attached; over cap → fallback to links + warning.
- Notification model validation: enabling a PDF toggle without the library is rejected.

### craft-smoke-test scenarios
1. With dompdf available, enable "Attach PDF" on a form's notification; submit; verify the email
   in Mailpit has a PDF attachment whose content includes the submitted field values.
2. Enable "Attach uploaded files"; submit with a file upload; verify the file is attached to the
   notification email.
3. Open the submission detail in the CP; click "Download PDF"; verify a valid PDF downloads.
4. Set `pdfStorageVolume`; submit; verify a PDF Asset is created and the detail view links to it.
5. Override `pdf.twig` in the project's `templates/` overrides path; submit; verify the custom
   layout is used and is rendered for the notification's site/locale.
6. Simulate dompdf being absent; verify the toggle is disabled with the install notice and a
   notification with the toggle previously-on still sends (without attachment) and logs a warning.

## 8. Open Questions

- Should the **autoresponder** (visitor email) also support "Attach PDF"? It falls out of the
  per-notification toggle naturally, but giving a visitor a PDF of their own submission is a
  distinct, desirable use case worth confirming for v1.
- dompdf vs. mpdf as the bundled-suggested engine — dompdf is lighter and pure-PHP-friendly;
  mpdf handles UTF-8/RTL better. Leaning dompdf for v1 with the engine seam allowing mpdf later.
- Storage default: render-on-demand vs. always-store. Leaning render-on-demand (no volume churn)
  unless `pdfStorageVolume` is set; confirm that matches retention/GC expectations
  (`RetentionService` would need to clean stored PDFs when a submission is GC'd).
- Filename convention for the attachment/Asset (`{form}-{submissionId}-{date}.pdf`)?
- Total-attachment-size cap default (e.g. 10 MB)?
