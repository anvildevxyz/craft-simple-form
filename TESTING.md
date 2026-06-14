# Simple Form Plugin - Testing Guide

## Prerequisites

- Craft 4.x or 5.x instance with DDEV
- Plugin installed via Composer: `composer require fabianhaef/craft-simple-form`
- Admin access to Control Panel

## Installation

1. **Install the plugin**:
   ```bash
   composer require fabianhaef/craft-simple-form
   php craft plugin/install simple-form
   ```

2. **Run migrations**:
   ```bash
   php craft migrate/all
   ```

3. **Verify in CP**: Navigate to Settings → Plugins and confirm "Simple Form" is installed

## Test Scenarios

### 1. Form Builder (CP)

**Steps**:
1. Go to **Simple Form → Forms**
2. Click **New Form**
3. Fill in:
   - **Name**: `Contact Us`
   - **Handle**: `contact-us`
   - **Title**: `Contact Form`
   - **Description**: `Send us your feedback`
   - **Email To**: `admin@example.com`
   - **Email Subject**: `New Contact Submission`
4. Click **Save**

**Expected**: Form appears in list with name, handle, and email

---

### 2. Add Fields to Form

**Steps**:
1. Edit the form created above
2. Click **Add Field** (field management UI)
3. Add fields:
   - **Name** (Text field, required)
   - **Email** (Email field, required)
   - **Message** (Textarea, required)
4. Save each field
5. Verify fields appear in form editor

**Expected**: Fields listed in order with labels and types

---

### 3. Frontend Form Rendering (Twig)

**Steps**:
1. Create a test page template with:
   ```twig
   <h1>Contact Us</h1>
   {{ simpleForm('contact-us') }}
   ```

2. View the page on frontend
3. Verify form renders with:
   - All fields (Name, Email, Message)
   - Required markers
   - Submit button
   - CSRF token (inspect HTML)

**Expected**: Form displays with proper HTML, styling, and validation

---

### 4. Form Submission - Valid Data

**Steps**:
1. On the frontend form, enter:
   - **Name**: `John Doe`
   - **Email**: `john@example.com`
   - **Message**: `Hello, this is a test`
2. Click **Submit**

**Expected**:
- Form shows success message
- Form resets
- No errors displayed

---

### 5. Form Submission - Invalid Data

**Steps**:
1. Submit form with:
   - **Email**: `invalid-email` (invalid format)
   - **Message**: (empty, required field)
2. Click **Submit**

**Expected**:
- Form displays error messages
- Fields are highlighted
- Data is preserved in form

---

### 6. Email Notification

**Steps**:
1. Submit a valid form
2. Check email (use Mailpit in DDEV):
   ```bash
   ddev launch mailpit
   ```
3. Verify email received with:
   - Subject: "New Contact Submission"
   - Recipient: `admin@example.com`
   - Body contains all submission fields and values

**Expected**: Email formatted with table layout, submission date, and user info

---

### 7. Submission Management (CP)

**Steps**:
1. Go to **Simple Form → Submissions**
2. Verify submitted form appears with:
   - Date
   - Form name
   - Status: "NEW"
   - User info
3. Click submission to view details
4. Verify all submitted data displayed
5. Toggle status: NEW → READ → ARCHIVED

**Expected**: Submission details visible, status changes reflected immediately

---

### 8. Submissions - Search & Filter

**Steps**:
1. Create 5+ submissions across different forms
2. In Submissions index:
   - **Filter by form**: Select `Contact Us` → see only contact submissions
   - **Filter by status**: Select `New` → see only new submissions
   - **Search**: Type field value → filter submissions

**Expected**: Filters work correctly, results update in real-time

---

### 9. PHP API - Custom Form Rendering

**Steps**:
1. In a custom controller, use:
   ```php
   $form = \fabianhaef\simpleform\models\FormModel::find()
       ->handle('contact-us')
       ->one();
   
   foreach ($form->getFields() as $field) {
       echo $field->getLabel() . ': ' . $field->renderInput('field_' . $field->getId());
   }
   ```

2. Verify form renders correctly
3. Submit form with custom handler:
   ```php
   $submissionService = \fabianhaef\simpleform\Plugin::getInstance()->submissionService;
   $result = $submissionService->createFromRequest('contact-us');
   
   if ($result['errors']) {
       // Show errors
   } else {
       // Submission saved
   }
   ```

**Expected**: PHP API works, form renders, submission saves

---

### 10. Translation Support

**Steps** (if multi-site):
1. Edit form in CP
2. Switch to French site
3. Translate:
   - **Title**: `Formulaire de Contact`
   - **Field labels**: Translate to French
4. Save

**Steps** (frontend):
1. View form on French site: `{{ simpleForm('contact-us') }}`
2. Verify labels display in French

**Expected**: Form translates per site, submissions record site/language

---

### 11. Event Hooks - Custom Integration

**Steps**:
1. In `config/app.php`, add listener:
   ```php
   use fabianhaef\simpleform\Plugin;
   use fabianhaef\simpleform\events\SubmissionEvent;
   
   Event::on(Plugin::class, Plugin::EVENT_AFTER_SUBMISSION_SAVE, function(SubmissionEvent $event) {
       // Custom logic: webhook, CRM sync, etc.
       \Craft::warning('Submission saved: ' . $event->submission->id);
   });
   ```

2. Submit a form
3. Check logs for custom message

**Expected**: Event fires, custom logic executes

---

## Validation Checklist

- [ ] Form creates in CP without errors
- [ ] Form appears in forms list
- [ ] Fields can be added/edited/deleted
- [ ] Form renders on frontend with Twig tag
- [ ] Valid form submission succeeds
- [ ] Invalid submission shows errors
- [ ] Email notification sent with correct content
- [ ] Submission appears in CP Submissions list
- [ ] Submission details visible and correct
- [ ] Status toggle works (NEW → READ → ARCHIVED)
- [ ] Search/filter in submissions works
- [ ] Multi-site translations work (if applicable)
- [ ] PHP API renders and submits forms
- [ ] Event hooks fire correctly
- [ ] No PHP errors in logs

## Known Limitations

- Field type configuration UI not yet implemented (use API/migrations for setup)
- File uploads not supported (V2 feature)
- Conditional fields not supported (V2 feature)
- Repeater fields not supported (V2 feature)

## Troubleshooting

**Form not appearing in CP**:
- Verify migration ran: `php craft migrate/all --plugin=simple-form`
- Check plugin is enabled: `php craft plugin/info simple-form`

**Form not submitting**:
- Check CSRF token: inspect HTML, verify `{{ csrfInput() }}` present
- Check browser console for JS errors
- Verify email recipient is valid

**Email not sending**:
- Check email settings in CP (Settings → System Settings → Email)
- Use Mailpit for local testing
- Check plugin logs: `storage/logs/`

**Submission not saving**:
- Check database permissions
- Verify form exists and has ID
- Check DB tables created: `simpleform_forms`, `simpleform_fields`, `simpleform_submissions`
