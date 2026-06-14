# Simple Form Plugin - Smoke Tests for /craft-smoke-test Skill

Run these tests sequentially using the `/craft-smoke-test` command to verify all plugin functionality.

## Test Suite 1: Form Builder - Create Form

```
/craft-smoke-test Create a new form in the control panel - navigate to Simple Form → Forms, click "New Form", fill in Name: "Contact Form", Handle: "contact-form", Title: "Get in Touch", Description: "Send us a message", Email To: "admin@example.com", Email Subject: "New Contact Request", then save. Verify the form appears in the forms list with correct name and handle.
```

## Test Suite 2: Form Builder - Add Text Field

```
/craft-smoke-test Add a Text field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "Your Name", Handle: "your_name", select Type: Text, check Required, set Min Length: 2, set Max Length: 100, then save. Verify the field appears in the form's field list with correct label and type.
```

## Test Suite 3: Form Builder - Add Email Field

```
/craft-smoke-test Add an Email field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "Email Address", Handle: "email_address", select Type: Email, check Required, then save. Verify the field appears in the form's field list.
```

## Test Suite 4: Form Builder - Add Textarea Field

```
/craft-smoke-test Add a Textarea field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "Message", Handle: "message", select Type: Textarea, check Required, then save. Verify the field appears in the form's field list.
```

## Test Suite 5: Form Builder - Add Select Field

```
/craft-smoke-test Add a Select field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "Subject", Handle: "subject", select Type: Select, enter Options: "General Question", "Bug Report", "Feature Request", then save. Verify the field appears in the form's field list.
```

## Test Suite 6: Form Builder - Add Checkbox Field

```
/craft-smoke-test Add a Checkbox field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "Interests", Handle: "interests", select Type: Checkbox, enter Options: "Product News", "Company Updates", "Event Invitations", then save. Verify the field appears in the form's field list.
```

## Test Suite 7: Form Builder - Add Radio Field

```
/craft-smoke-test Add a Radio field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "How did you hear about us?", Handle: "hear_about_us", select Type: Radio, enter Options: "Search Engine", "Social Media", "Referral", "Other", then save. Verify the field appears in the form's field list.
```

## Test Suite 8: Form Builder - Add Date Field

```
/craft-smoke-test Add a Date field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "Preferred Contact Date", Handle: "preferred_date", select Type: Date, then save. Verify the field appears in the form's field list.
```

## Test Suite 9: Form Builder - Add Number Field

```
/craft-smoke-test Add a Number field to the contact form - navigate to the contact form edit page, click "Add Field", fill in Label: "How many people in your organization?", Handle: "org_size", select Type: Number, set Min Value: 1, set Max Value: 10000, then save. Verify the field appears in the form's field list.
```

## Test Suite 10: Form Rendering - Twig Tag

```
/craft-smoke-test Test Twig form rendering - navigate to the frontend at /forms/contact-form, verify the form renders with all 8 fields visible (Name, Email, Message, Subject dropdown, Interests checkboxes, How did you hear radio buttons, Preferred Date, and Org Size). Verify the form has a Submit button and all fields display required indicators.
```

## Test Suite 11: Form Submission - Valid Data

```
/craft-smoke-test Submit a valid form - navigate to /forms/contact-form, fill in: Name: "John Doe", Email: "john@example.com", Message: "This is a test message", Subject: "General Question", check "Product News" for Interests, select "Search Engine" for How did you hear, enter Date: "12/25/2026", enter Org Size: "50", then click Submit. Verify a success message appears and the form resets.
```

## Test Suite 12: Form Submission - Invalid Email

```
/craft-smoke-test Test email validation - navigate to /forms/contact-form, fill in valid Name and Org Size, enter invalid Email: "not-an-email", fill in Message, then click Submit. Verify an error message appears indicating invalid email format and form data is preserved.
```

## Test Suite 13: Form Submission - Missing Required Fields

```
/craft-smoke-test Test required field validation - navigate to /forms/contact-form, leave all fields empty, click Submit. Verify error messages appear for all required fields (Name, Email, Message, Subject, Interests, How did you hear) and form data is not saved.
```

## Test Suite 14: Form Submission - Text Length Validation

```
/craft-smoke-test Test text length validation - navigate to /forms/contact-form, enter Name: "A" (less than min 2), fill other required fields, click Submit. Verify error message appears indicating name must be at least 2 characters. Then enter Name with 101+ characters, submit again and verify max length error.
```

## Test Suite 15: Form Submission - Number Validation

```
/craft-smoke-test Test number field validation - navigate to /forms/contact-form, enter Org Size: "0" (below min 1), fill other required fields, click Submit. Verify error appears. Then enter Org Size: "10001" (above max 10000), fill other fields, submit and verify max error appears.
```

## Test Suite 16: Form Submission - Honeypot Protection

```
/craft-smoke-test Test honeypot protection - using browser developer tools, attempt to fill a hidden honeypot field in the contact form, submit the form. Verify the submission is rejected and no data is saved to the database.
```

## Test Suite 17: Submission Management - View Submissions List

```
/craft-smoke-test View submissions in control panel - navigate to Simple Form → Submissions, verify the submission from Test Suite 11 appears in the list with correct date, form name (Contact Form), and status (New). Click on the submission to view full details.
```

## Test Suite 18: Submission Management - View Submission Details

```
/craft-smoke-test View submission details - from the Submissions list, click on the most recent submission, verify all submitted data is displayed correctly: Name: "John Doe", Email: "john@example.com", Message content, Subject: "General Question", Interests: "Product News", How did you hear: "Search Engine", Date, Org Size: "50".
```

## Test Suite 19: Submission Management - Toggle Status New to Read

```
/craft-smoke-test Toggle submission status - from the Submissions list, locate the submission with status "New", click on the status badge and change it to "Read". Verify the status updates immediately in the list and the submission details show the new status.
```

## Test Suite 20: Submission Management - Toggle Status to Archived

```
/craft-smoke-test Archive a submission - from the Submissions list, locate a submission with status "Read", click the status badge and change it to "Archived". Verify the status updates to "Archived" and the submission is marked as archived in the system.
```

## Test Suite 21: Submission Management - Filter by Form

```
/craft-smoke-test Filter submissions by form - navigate to Simple Form → Submissions, click the Form filter dropdown, select "Contact Form", verify only submissions from the Contact Form are displayed in the list.
```

## Test Suite 22: Submission Management - Filter by Status

```
/craft-smoke-test Filter submissions by status - navigate to Simple Form → Submissions, click the Status filter dropdown, select "New", verify only submissions with "New" status are displayed. Then select "Archived" and verify only archived submissions appear.
```

## Test Suite 23: Submission Management - Search Submissions

```
/craft-smoke-test Search submissions - navigate to Simple Form → Submissions, enter "john@example.com" in the search field, verify only submissions containing this email are displayed in the results.
```

## Test Suite 24: Submission Management - Pagination

```
/craft-smoke-test Test pagination - navigate to Simple Form → Submissions, create 15+ test submissions by submitting the form multiple times with different data. Verify pagination controls appear at the bottom of the submissions list and clicking "Next" loads the next page of submissions.
```

## Test Suite 25: Email Notifications - Email Sent on Submission

```
/craft-smoke-test Verify email notification - submit the contact form with valid data, then navigate to Mailpit at http://craft-plugin-dev.ddev.site:8025, verify an email appears in the inbox with Subject: "New Contact Request", To: "admin@example.com", and the body contains the submitted form data including the submitter's name and email.
```

## Test Suite 26: Email Notifications - Email Contains Form Data

```
/craft-smoke-test Verify email content - in Mailpit, open the most recent email notification, verify the email body displays all submitted form fields and their values in a formatted table or list, including Name, Email, Message, Subject, Interests, How did you hear, Date, and Org Size.
```

## Test Suite 27: Email Notifications - Custom Email Subject

```
/craft-smoke-test Test custom email subject - navigate to the Contact Form edit page, change Email Subject: "New Customer Inquiry - Contact Form", save, then submit a new form. Check Mailpit and verify the email subject has updated to "New Customer Inquiry - Contact Form".
```

## Test Suite 28: Email Notifications - Custom Email Reply-To

```
/craft-smoke-test Test email reply-to header - navigate to the Contact Form edit page, set Email Reply-To: "replies@example.com", save, then submit a new form. Check Mailpit and verify the Reply-To header in the email is set to "replies@example.com".
```

## Test Suite 29: Multi-Site - Create Form on English Site

```
/craft-smoke-test Test multi-site support - switch to the English site in the control panel (if multi-site is configured), navigate to Simple Form → Forms, create a new form: Name: "Inquiry", Handle: "inquiry", Title: "Send Inquiry (English)", save. Verify the form is created on the English site.
```

## Test Suite 30: Multi-Site - Translate Form to French

```
/craft-smoke-test Test form translation - if multi-site is configured, switch to the French site in the control panel, navigate to Simple Form → Forms, find the "Inquiry" form, change Title: "Envoyer Enquête (Français)", save. Switch back to English and verify the English title is unchanged. Switch to French and verify the French translation is present.
```

## Test Suite 31: Translation - Translate Field Labels

```
/craft-smoke-test Test field translation - on the French site, edit the Inquiry form, click to edit the first field, change Label from English to French translation, save. Navigate to the frontend form on the French site (/forms/inquiry) and verify the field label displays in French.
```

## Test Suite 32: Translation - Multilingual Form Rendering

```
/craft-smoke-test Test rendering in different languages - if multi-site is configured with English and French, navigate to the form on the English site and verify all field labels are in English. Then switch to the French site (if frontend supports it) and verify the same form displays with French field labels.
```

## Test Suite 33: CP Integration - Form List Display

```
/craft-smoke-test Verify form list in CP - navigate to Simple Form → Forms, verify all created forms are listed with columns: Name, Handle, Email To, Created Date. Verify forms are sortable by clicking column headers and searchable using the search field.
```

## Test Suite 34: CP Integration - Delete Form

```
/craft-smoke-test Test form deletion - navigate to Simple Form → Forms, click the delete icon on a test form, confirm the deletion in the dialog, verify the form is removed from the list and no longer exists in the database.
```

## Test Suite 35: CP Integration - CP Navigation

```
/craft-smoke-test Verify CP navigation - from the main Craft CP, locate "Simple Form" in the left sidebar, verify it has two menu items: "Forms" and "Submissions". Click each to verify they navigate to the correct pages.
```

## Test Suite 36: API - PHP Form Loading

```
/craft-smoke-test Test PHP API form loading - via a test template or console, load a form using the PHP API: FormModel::find()->handle('contact-form')->one(), verify it returns the Form object with correct name, handle, and field count.
```

## Test Suite 37: API - PHP Field Configuration

```
/craft-smoke-test Test PHP API field config - via the PHP API, load the Contact Form and iterate through getFields(), verify each field returns its configuration: label, handle, type, required status, validation rules.
```

## Test Suite 38: API - PHP Form Rendering

```
/craft-smoke-test Test PHP API rendering - via a test template, use the FormModel API to render the form HTML, verify the rendered output includes all 8 fields with correct labels, types, and required indicators.
```

## Test Suite 39: Events - Before Submission Hook

```
/craft-smoke-test Test BEFORE_SUBMISSION_SAVE event - register a custom event listener for Plugin::EVENT_BEFORE_SUBMISSION_SAVE that logs the submission data, submit a form, verify the event fires and the listener receives the submission data before it's saved to the database.
```

## Test Suite 40: Events - After Submission Hook

```
/craft-smoke-test Test AFTER_SUBMISSION_SAVE event - register a custom event listener for Plugin::EVENT_AFTER_SUBMISSION_SAVE that logs the submission ID, submit a form, verify the event fires after the submission is saved with the correct ID.
```

## Test Suite 41: CSRF Protection

```
/craft-smoke-test Verify CSRF protection - inspect the HTML source of the contact form page, verify a CSRF token field is present in the form. Attempt to submit the form with an invalid/missing CSRF token via a modified form request, verify the submission is rejected.
```

## Test Suite 42: Database Integrity

```
/craft-smoke-test Verify database schema - query the database directly to verify all Simple Form tables exist: simpleform_forms, simpleform_fields, simpleform_submissions. Verify the tables have correct columns, foreign keys, and relationships between them.
```

## Test Suite 43: Form Validation - All Required Rules

```
/craft-smoke-test Test all validation rules together - submit a form where one field violates each validation rule (text too short, email invalid, required field missing, number out of range), verify appropriate error messages appear for each violation.
```

## Test Suite 44: Submission Data Preservation

```
/craft-smoke-test Verify form data preservation on validation error - submit a form with invalid email, verify the form redisplays with validation errors AND all previously entered data (Name, Message, etc.) is preserved in the form fields for user correction.
```

## Test Suite 45: Admin User Permissions

```
/craft-smoke-test Verify admin access - log in as admin user, navigate to Simple Form → Forms and Submissions, verify you can view, create, edit, and delete forms and submissions. Log out and attempt to access /admin/simple-form as anonymous user, verify access is denied.
```

---

## Instructions for Running Tests

1. **Run sequentially**: Execute each test in order using `/craft-smoke-test <test description>`
2. **Document results**: Note PASS/FAIL for each test and any error messages
3. **Coverage**: These 45 tests provide comprehensive coverage of all 11 plugin features
4. **Dependencies**: Tests build on each other - Test Suite 11 creates a submission used in subsequent tests
5. **Expected time**: ~45-60 minutes to run all tests manually

## Test Coverage Map

| Feature | Tests |
|---------|-------|
| Form Builder | 1-9, 34 |
| Rendering | 10, 38 |
| Submission Validation | 11-16, 43-44 |
| Submission Management | 17-24 |
| Email Notifications | 25-28 |
| Multi-Site Support | 29-32 |
| CP Integration | 33-35 |
| PHP API | 36-38 |
| Event Hooks | 39-40 |
| Security | 41 |
| Database | 42 |
| Permissions | 45 |
