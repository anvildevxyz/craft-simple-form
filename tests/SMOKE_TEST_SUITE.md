# Simple Form Plugin - Comprehensive Smoke Test Suite

**Total Test Scenarios**: 60+  
**Coverage**: All 11 implemented features  
**Framework**: Codeception (Functional Tests)

---

## Test Suite Structure

### 1. Form Builder (12 scenarios)
**File**: `tests/smoke/FormBuilderCompleteCest.php`

Tests CP form creation, editing, deletion, and field management:
- ✓ Create form with basic info
- ✓ Add Text field with validation
- ✓ Add Email field
- ✓ Add Textarea field
- ✓ Add Select field with options
- ✓ Add Checkbox field
- ✓ Add Radio field
- ✓ Add Date field
- ✓ Add Number field
- ✓ Edit existing form
- ✓ Delete form
- ✓ Reorder fields via drag

**Verifications**: Database state, UI rendering, form persistence

---

### 2. Form Submission (12 scenarios)
**File**: `tests/smoke/FormSubmissionCest.php`

Tests form submission, validation, and data handling:
- ✓ Submit form with valid data
- ✓ Submit form with invalid email
- ✓ Submit with missing required fields
- ✓ Honeypot protection
- ✓ Text field length validation
- ✓ Select field validation
- ✓ Checkbox multiple values
- ✓ Date field validation
- ✓ Number field min/max validation
- ✓ CSRF token validation
- ✓ Multi-step submission flow
- ✓ Form reset after successful submission

**Verifications**: Frontend validation, database storage, CSRF protection

---

### 3. Submission Management (12 scenarios)
**File**: `tests/smoke/SubmissionManagementCest.php`

Tests CP submission browsing, filtering, and status management:
- ✓ View submissions list
- ✓ Filter by form
- ✓ Filter by status
- ✓ Search submissions
- ✓ View submission details
- ✓ Toggle status new → read
- ✓ Toggle status read → archived
- ✓ Pagination
- ✓ View all submission data
- ✓ Submission date display
- ✓ User info in submission
- ✓ Multiple form submissions

**Verifications**: CP UI, database queries, status transitions

---

### 4. Rendering & API (12 scenarios)
**File**: `tests/smoke/RenderingAndApiCest.php`

Tests Twig rendering and PHP API:
- ✓ Twig tag basic rendering
- ✓ Form fields render correctly
- ✓ Form labels render
- ✓ Required markers display
- ✓ Custom submit text
- ✓ Form styling applied
- ✓ CSRF token in rendered form
- ✓ Honeypot field hidden
- ✓ PHP API - Load form
- ✓ PHP API - Get field config
- ✓ PHP API - Validate field
- ✓ PHP API - Create submission

**Verifications**: HTML output, API responses, form configuration

---

### 5. Email & Events (12 scenarios)
**File**: `tests/smoke/EmailAndEventsCest.php`

Tests email notifications and event hooks:
- ✓ Email sent on form submission
- ✓ Email contains submission data
- ✓ Email subject configured
- ✓ Email reply-to set
- ✓ Event before submission save
- ✓ Event after submission save
- ✓ Event contains submission data
- ✓ Webhook triggered on submission
- ✓ CRM integration via event
- ✓ Custom validation via event
- ✓ Event modification of submission
- ✓ Multiple event listeners

**Verifications**: Email delivery (Mailpit), event execution, webhook calls

---

### 6. Translation & Multi-Site (12 scenarios)
**File**: `tests/smoke/TranslationAndMultiSiteCest.php`

Tests multi-site translations and localization:
- ✓ Create form with English translation
- ✓ Translate form to French
- ✓ Verify English and French titles coexist
- ✓ Translate field labels
- ✓ Translate email subject
- ✓ Submission records site language
- ✓ Form renders in correct language
- ✓ Email subject in correct language
- ✓ Multi-site submissions list
- ✓ Filter submissions by site
- ✓ Translate form description
- ✓ Regional form configurations

**Verifications**: Translation persistence, site detection, language switching

---

## Running the Tests

### Prerequisites
```bash
# Ensure DDEV is running
ddev start

# Install Codeception
composer require --dev codeception/codeception

# Run database migrations
ddev craft migrate/all
```

### Run All Tests
```bash
# Run entire smoke test suite
ddev exec codecept run tests/smoke/

# With verbose output
ddev exec codecept run tests/smoke/ --verbose

# With output directory for artifacts
ddev exec codecept run tests/smoke/ --output tests/_output/
```

### Run Specific Test File
```bash
ddev exec codecept run tests/smoke/FormBuilderCompleteCest.php
ddev exec codecept run tests/smoke/FormSubmissionCest.php
ddev exec codecept run tests/smoke/SubmissionManagementCest.php
ddev exec codecept run tests/smoke/RenderingAndApiCest.php
ddev exec codecept run tests/smoke/EmailAndEventsCest.php
ddev exec codecept run tests/smoke/TranslationAndMultiSiteCest.php
```

### Run Single Scenario
```bash
ddev exec codecept run tests/smoke/FormBuilderCompleteCest.php:testCreateFormWithBasicInfo
```

### Debug Mode
```bash
ddev exec codecept run tests/smoke/ --debug
```

---

## Test Coverage Matrix

| Feature | Builder | Submission | Management | Rendering | Email | Translation |
|---------|---------|-----------|------------|-----------|-------|------------|
| **Text Field** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Email Field** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Textarea** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Select** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Checkbox** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Radio** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Date** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Number** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Validation** | ✓ | ✓ | - | ✓ | - | ✓ |
| **Honeypot** | - | ✓ | - | ✓ | - | - |
| **CSRF** | - | ✓ | - | ✓ | - | - |
| **CP UI** | ✓ | - | ✓ | - | - | ✓ |
| **Email** | ✓ | ✓ | - | - | ✓ | ✓ |
| **Events** | - | ✓ | - | - | ✓ | - |
| **API** | - | - | - | ✓ | - | - |
| **Multi-Site** | - | - | ✓ | - | - | ✓ |

---

## Test Data Requirements

### Forms Created
- Newsletter Signup (`newsletter-signup`)
- Contact Form (`contact-*`)
- Email Form (`email-*`)
- Feedback Form (`feedback-*`)
- Survey (`survey-*`)
- Preferences (`prefs-*`)
- Options (`options-*`)
- Event Form (`event-*`)
- Quantity Form (`qty-*`)
- Multi-Lang Form (`multilang`)

### Sites Required
- English (default)
- French (for translation tests)

### Mailpit Access
- http://craft-plugin-dev.ddev.site:8025 (email verification)

---

## Codeception Helpers

### Custom Assertions
```php
$I->createTestForm($name, $handle, $email = null);
$I->submitForm($data);
$I->createMultipleSubmissions($formHandle, $count);
$I->loginAsAdmin();
```

### Database Helpers
```php
$I->seeInDatabase('simpleform_forms', [...]);
$I->seeInDatabase('simpleform_submissions', [...]);
```

### UI Helpers
```php
$I->click('button text', '//selector');
$I->selectOption('field', 'value');
$I->fillField('field', 'value');
```

---

## Expected Results

### Success Criteria
- All 60+ scenarios pass
- No PHP errors in logs
- All emails delivered (Mailpit)
- All events triggered
- Database state consistent
- UI renders correctly across sites

### Performance Baseline
- Form creation: < 1s
- Submission: < 500ms
- List loading: < 1s
- Email delivery: < 2s

---

## Continuous Integration

### GitHub Actions Integration
```yaml
- name: Run Smoke Tests
  run: ddev exec codecept run tests/smoke/ --fail-fast
```

### Pre-Release Checklist
- [ ] Run full smoke test suite
- [ ] Verify all scenarios pass
- [ ] Check email delivery (Mailpit)
- [ ] Review error logs
- [ ] Verify database integrity
- [ ] Test on multiple sites/languages

---

## Debugging Failed Tests

### Common Issues

**Form not created**
```bash
ddev exec codecept run --debug
# Check CP navigation, form controller
```

**Submission not saved**
```bash
ddev mysql -e "SELECT * FROM simpleform_submissions;"
# Verify migrations ran, tables exist
```

**Email not sent**
```bash
ddev exec "curl http://mailpit:1025"
# Check Mailpit is running
```

**Event not triggered**
```bash
ddev craft queue/run
# Verify event listeners registered
```

---

## Test Maintenance

### Adding New Tests
1. Identify feature not yet covered
2. Create test in appropriate Cest file
3. Follow naming convention: `test[Feature][Scenario]`
4. Update coverage matrix above
5. Run: `ddev exec codecept run`

### Updating Selectors
When CP templates change:
1. Verify selector still valid: `$I->seeElement('selector')`
2. Update test with new selector
3. Re-run test to verify

### Handling Flakiness
For intermittent failures:
1. Add `$I->wait(1)` for async operations
2. Use explicit waits for AJAX: `$I->waitForElement()`
3. Increase timeout if needed: `->timeout(30)`

---

## Test Report Sample

```
Codeception Simple Form Plugin Smoke Tests
===========================================
FormBuilderCompleteCest: 12 passed
FormSubmissionCest: 12 passed
SubmissionManagementCest: 12 passed
RenderingAndApiCest: 12 passed
EmailAndEventsCest: 12 passed
TranslationAndMultiSiteCest: 12 passed

TOTAL: 72 passed, 0 failed
Time: 3m 24s
```

---

## Reference

- **Codeception Docs**: https://codeception.com/
- **Craft Testing**: https://craftcms.com/docs/4.x/extend/testing.html
- **FunctionalTester**: Built-in Craft test helper
- **Mailpit**: http://mailpit:1025 (DDEV)
