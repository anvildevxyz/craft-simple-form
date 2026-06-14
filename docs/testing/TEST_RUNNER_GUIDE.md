# Simple Form Plugin - Automated Test Runner Guide

## Overview

The automated smoke test runner executes all 45 comprehensive test scenarios sequentially and generates detailed reports. This guide explains how to use it.

## Files

- **`SMOKE_TESTS.md`** - 45 test scenarios (copy-paste into `/craft-smoke-test` skill)
- **`run-smoke-tests.sh`** - Bash script to orchestrate test execution
- **`tests/_reports/`** - Generated test reports and logs

## Quick Start

### Run All 45 Tests

```bash
cd plugins/simple-form
bash run-smoke-tests.sh
```

### Run Individual Tests with /craft-smoke-test Skill

Each test in `SMOKE_TESTS.md` can be run independently using the skill:

```bash
/craft-smoke-test Create a new form in the control panel - navigate to Simple Form → Forms, click "New Form", fill in Name: "Contact Form", Handle: "contact-form", Title: "Get in Touch", Description: "Send us a message", Email To: "admin@example.com", Email Subject: "New Contact Request", then save. Verify the form appears in the forms list with correct name and handle.
```

## Test Suite Structure

The 45 tests are organized into logical groups:

### Group 1: Form Builder (Tests 1-9)
- Create form with basic info
- Add all 8 field types with validation

### Group 2: Rendering (Test 10)
- Verify Twig template rendering

### Group 3: Submission Validation (Tests 11-16)
- Valid data submission
- Email validation
- Required field validation
- Text length validation
- Number validation
- Honeypot protection

### Group 4: Submission Management (Tests 17-24)
- View submissions list
- View submission details
- Toggle status (New → Read → Archived)
- Filter by form and status
- Search submissions
- Pagination

### Group 5: Email Notifications (Tests 25-28)
- Email sent on submission
- Email contains form data
- Custom email subject
- Custom reply-to header

### Group 6: Multi-Site & Translation (Tests 29-32)
- Create form on primary site
- Translate form to secondary site
- Translate field labels
- Rendering in different languages

### Group 7: CP Integration (Tests 33-35)
- Form list display and sorting
- Delete form
- CP navigation

### Group 8: PHP API (Tests 36-38)
- Form loading
- Field configuration
- Form rendering

### Group 9: Event Hooks (Tests 39-40)
- BEFORE_SUBMISSION_SAVE event
- AFTER_SUBMISSION_SAVE event

### Group 10: Security (Tests 41-42)
- CSRF token validation
- Database schema integrity

### Group 11: Data Preservation (Tests 43-44)
- All validation rules together
- Form data preserved on error

### Group 12: Permissions (Test 45)
- Admin user access control

## How to Execute Tests

### Method 1: Sequential Manual Testing (Using /craft-smoke-test Skill)

**Step 1-9: Build the Form**
```bash
/craft-smoke-test [Test 1 scenario]
/craft-smoke-test [Test 2 scenario]
# ... continue through Test 9
```

**Step 10: Test Rendering**
```bash
/craft-smoke-test [Test 10 scenario]
```

**Step 11-16: Test Submission**
```bash
/craft-smoke-test [Test 11 scenario]
/craft-smoke-test [Test 12 scenario]
# ... continue through Test 16
```

And so on for the remaining test groups.

### Method 2: Automated Test Runner

Run all tests automatically with status tracking:

```bash
bash run-smoke-tests.sh
```

Output includes:
- Color-coded progress (✓ PASS, ✗ FAIL)
- Real-time test counter
- Summary statistics
- Detailed report file
- JSON summary

### Method 3: Running Specific Test Groups

Run tests 1-9 (Form Builder):
```bash
for i in {1..9}; do
  /craft-smoke-test [scenario for test $i]
done
```

Run tests 25-28 (Email Notifications):
```bash
for i in {25..28}; do
  /craft-smoke-test [scenario for test $i]
done
```

## Test Report Output

After running tests, find reports in:

```
plugins/simple-form/tests/_reports/
├── smoke-test-report-20260614_120000.txt    # Detailed text report
└── smoke-test-report-20260614_120000.json   # JSON summary
```

### Text Report Contents

```
═══════════════════════════════════════════════════════════════════════════
Simple Form Plugin - Smoke Test Report
Generated: 2026-06-14 12:00:00

TEST EXECUTION LOG
─────────────────────────────────────────────────────────────────────────

[ℹ TEST 1/45] Test 1: Create a new form in the control panel
[✓ PASS] Test 1 passed

[ℹ TEST 2/45] Test 2: Add a Text field to the contact form
[✓ PASS] Test 2 passed

... (43 more tests)

═══════════════════════════════════════════════════════════════════════════
TEST SUMMARY
═══════════════════════════════════════════════════════════════════════════

Total Tests Run: 45
Tests Passed: 45
Tests Failed: 0
Tests Skipped: 0

Pass Rate: 100%
```

### JSON Report Contents

```json
{
  "timestamp": "2026-06-14T12:00:00Z",
  "plugin": "simple-form",
  "test_summary": {
    "total": 45,
    "passed": 45,
    "failed": 0,
    "skipped": 0,
    "pass_rate": "100%"
  },
  "test_coverage": {
    "form_builder": 9,
    "rendering": 1,
    "submission_validation": 6,
    "submission_management": 8,
    "email_notifications": 4,
    "multi_site": 4,
    "cp_integration": 3,
    "php_api": 3,
    "event_hooks": 2,
    "security": 2,
    "database": 1,
    "permissions": 1
  }
}
```

## Prerequisites

- ✅ DDEV running (`ddev status`)
- ✅ Craft site accessible at https://craft-plugin-dev.ddev.site
- ✅ Simple Form plugin installed
- ✅ Database initialized with migrations
- ✅ Admin user account

## Running from Different Locations

### From plugin root:
```bash
bash run-smoke-tests.sh
```

### From project root:
```bash
bash plugins/simple-form/run-smoke-tests.sh
```

### With absolute path:
```bash
bash /Users/fh/Documents/experiments/craft-plugin-dev/plugins/simple-form/run-smoke-tests.sh
```

## Interpreting Results

### All Tests Pass ✅
```
Pass Rate: 100%
All tests passed!
```
Status: Plugin is functioning correctly.

### Some Tests Fail ❌
```
Pass Rate: 86% (38/44)
Failed Tests:
  ✗ Test 3: Add an Email field to the contact form
  ✗ Test 12: Test email validation
  ✗ Test 28: Test email reply-to header
```
Action: Review the failed test scenarios and debug the failing features.

## Common Issues

### "Craft site is not accessible"
**Solution**: Ensure DDEV is running:
```bash
ddev start
```

### "SMOKE_TESTS.md not found"
**Solution**: Run from plugin root directory:
```bash
cd plugins/simple-form
bash run-smoke-tests.sh
```

### "Permission denied"
**Solution**: Make script executable:
```bash
chmod +x run-smoke-tests.sh
```

## Test Timing

| Test Group | Count | Est. Time |
|-----------|-------|-----------|
| Form Builder | 9 | 5-7 min |
| Rendering | 1 | 1 min |
| Submission Validation | 6 | 4-5 min |
| Submission Management | 8 | 6-8 min |
| Email Notifications | 4 | 3-4 min |
| Multi-Site & Translation | 4 | 4-5 min |
| CP Integration | 3 | 2-3 min |
| PHP API | 3 | 2 min |
| Event Hooks | 2 | 1-2 min |
| Security | 2 | 1-2 min |
| Data Preservation | 2 | 1-2 min |
| Permissions | 1 | 1 min |
| **TOTAL** | **45** | **35-45 min** |

## Tips for Success

1. **Run sequentially**: Tests build on each other (Test 1 creates form used in Tests 2-9)
2. **Document results**: Note any failures for debugging
3. **Review reports**: Check the generated reports for detailed logs
4. **Clear database between runs**: If tests fail, reset and try again:
   ```bash
   ddev craft migrate/rollback --all
   ddev craft migrate/all
   ```

## Next Steps

After running all tests:

1. ✅ Review the test report
2. ✅ Fix any failing tests (feature bugs)
3. ✅ Re-run tests to confirm fixes
4. ✅ Archive the successful report
5. ✅ Deploy the plugin to production

## Integration with CI/CD

To integrate with GitHub Actions or other CI:

```yaml
# .github/workflows/smoke-tests.yml
name: Smoke Tests

on: [push, pull_request]

jobs:
  smoke-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Start DDEV
        run: ddev start
      - name: Run smoke tests
        run: bash plugins/simple-form/run-smoke-tests.sh
      - name: Upload report
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: smoke-test-reports
          path: plugins/simple-form/tests/_reports/
```

## Support

For test failures or issues:

1. Check the detailed report in `tests/_reports/`
2. Review the specific failing test scenario in `SMOKE_TESTS.md`
3. Manually run the failing test using `/craft-smoke-test` skill
4. Debug the feature in the CP or frontend
5. Fix the issue and re-run the test

---

**Ready to test?** Run `bash run-smoke-tests.sh` to execute all 45 tests! 🚀
