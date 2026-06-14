# Simple Form Plugin Profile
Generated: 2026-06-14 | Plugin version: 1.0.0

## Elements
- **Form** - table: `simpleform_forms`, statuses: none
  - Key fields: name, handle, title, description, emailTo, emailSubject
  - CP route: /admin/simple-form/forms/{id}
  - Localized: yes

- **Submission** - table: `simpleform_submissions`, statuses: [new, read, archived]
  - Key fields: formId, data (JSON), userId, readStatus
  - CP route: /admin/simple-form/submissions/{id}
  - Localized: yes

## CP Navigation
- Simple Form → Forms (index + new)
- Simple Form → Submissions (index + view)

## Controllers & Actions
- `simple-form/forms/index` — GET — list all forms
- `simple-form/forms/edit` — GET/POST — create/edit form
- `simple-form/forms/save` — POST — save form
- `simple-form/forms/delete` — POST — delete form
- `simple-form/submissions/index` — GET — list submissions with filters
- `simple-form/submissions/view` — GET — view submission details
- `simple-form/submissions/toggle-status` — POST (AJAX) — change submission status
- `simple-form/submit/index` — POST — frontend form submission handler

## CP Routes
- `/admin/simple-form` → forms index
- `/admin/simple-form/forms` → forms index
- `/admin/simple-form/forms/new` → form create
- `/admin/simple-form/forms/edit/{id}` → form edit
- `/admin/simple-form/submissions` → submissions index
- `/admin/simple-form/submissions/{id}` → submission view
- `/simple-form/submit` → POST endpoint (site, not CP)

## DB Tables
- `simpleform_forms` — columns: id, siteId, name, handle, title, description, emailTo, emailSubject, emailReplyTo
- `simpleform_fields` — columns: id, formId, type, name, label, helpText, config (JSON), sortOrder
- `simpleform_submissions` — columns: id, formId, siteId, data (JSON), userId, readStatus

## Services & Key Operations
- **FieldTypeRegistry**: getFieldType(type, config), getAllFieldTypes()
- **EmailService**: sendSubmissionEmail(form, submission, data)
- **SubmissionService**: createFromRequest(form, request), getSubmission(id), updateStatus(id, status)

## Twig API
- `{{ simpleForm('handle') }}` — renders complete form with validation/submission handling
- `{{ simpleForm('handle', {submitText: 'Send'}) }}` — with custom submit text
