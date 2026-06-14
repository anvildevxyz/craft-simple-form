# Running Simple Form Plugin Smoke Tests

## Quick Start

```bash
# 1. Update Docker to 25.0+ (required)
# Open Docker Desktop → Check for updates → Install

# 2. Start DDEV
ddev start

# 3. Run automated test runner
bash tests/run-smoke-tests.sh
```

## Prerequisites

- **Docker**: 25.0 or newer (currently: check with `docker --version`)
- **DDEV**: Running and healthy (`ddev status`)
- **Craft**: Migrations applied (`ddev craft migrate/all`)
- **Codeception**: Installed (`composer require --dev codeception/codeception`)

## Test Execution Methods

### Method 1: Automated Runner (Recommended)
```bash
bash tests/run-smoke-tests.sh
```
**Pros**: 
- Single command
- Comprehensive reporting
- Parallel execution
- Artifact collection

**Cons**: 
- Requires Docker 25.0+
- Takes 5-10 minutes

---

### Method 2: Run All Tests via Codeception
```bash
ddev exec codecept run tests/smoke/ --verbose --output tests/_output/
```

---

### Method 3: Run by Category

**Form Builder Tests** (12 scenarios)
```bash
ddev exec codecept run tests/smoke/FormBuilderCompleteCest.php
```

**Submission Tests** (12 scenarios)
```bash
ddev exec codecept run tests/smoke/FormSubmissionCest.php
```

**Management Tests** (12 scenarios)
```bash
ddev exec codecept run tests/smoke/SubmissionManagementCest.php
```

**Rendering & API Tests** (12 scenarios)
```bash
ddev exec codecept run tests/smoke/RenderingAndApiCest.php
```

**Email & Events Tests** (12 scenarios)
```bash
ddev exec codecept run tests/smoke/EmailAndEventsCest.php
```

**Translation Tests** (12 scenarios)
```bash
ddev exec codecept run tests/smoke/TranslationAndMultiSiteCest.php
```

---

### Method 4: Run Single Scenario
```bash
ddev exec codecept run tests/smoke/FormBuilderCompleteCest.php:testCreateFormWithBasicInfo
```

---

## Expected Output

### Success (All 72 scenarios pass)
```
Codeception Simple Form Plugin Smoke Tests
==========================================
FormBuilderCompleteCest: 12 passed ✓
FormSubmissionCest: 12 passed ✓
SubmissionManagementCest: 12 passed ✓
RenderingAndApiCest: 12 passed ✓
EmailAndEventsCest: 12 passed ✓
TranslationAndMultiSiteCest: 12 passed ✓

TOTAL: 72 passed, 0 failed ✓
Time: 5m 32s
```

### Failure (Review logs)
```
FAILED: EmailAndEventsCest:testEmailSentOnSubmission
Reason: Email not found in Mailpit
→ Check Mailpit: http://craft-plugin-dev.ddev.site:8025
```

---

## Troubleshooting

### Docker Version Too Old
**Error**: `compose build requires buildx 0.17 or later`

**Solution**:
1. Open Docker Desktop
2. Go to Settings → Updates
3. Install Docker 25.0+
4. Restart DDEV: `ddev restart`

---

### DDEV Not Running
**Error**: `Error: DDEV project status is stopped`

**Solution**:
```bash
ddev poweroff
ddev start
```

---

### Migrations Not Applied
**Error**: `Table 'simpleform_forms' doesn't exist`

**Solution**:
```bash
ddev craft migrate/all
```

---

### Email Tests Failing
**Error**: `Email not found in Mailpit`

**Solution**:
1. Verify Mailpit is running: `ddev launch mailpit`
2. Check email was actually sent: verify form submission succeeded
3. Try clearing Mailpit cache and re-running test

---

### Codeception Not Installed
**Error**: `Command not found: codecept`

**Solution**:
```bash
composer require --dev codeception/codeception
```

---

## Test Artifacts

All test outputs saved to `tests/_output/`:

- `smoke-test-report-TIMESTAMP.html` — formatted HTML report
- `FormBuilderCompleteCest.log` — test execution log
- `SubmissionManagementCest.log` — test execution log
- ... (one per test file)

**View results**:
```bash
open tests/_output/smoke-test-report-*.html
```

---

## Performance Expectations

| Test File | Scenarios | Duration | Status |
|-----------|-----------|----------|--------|
| FormBuilderCompleteCest | 12 | ~40s | Fast (CP interaction) |
| FormSubmissionCest | 12 | ~35s | Fast (form submission) |
| SubmissionManagementCest | 12 | ~38s | Fast (CP browsing) |
| RenderingAndApiCest | 12 | ~32s | Very fast (API calls) |
| EmailAndEventsCest | 12 | ~50s | Slower (email + events) |
| TranslationAndMultiSiteCest | 12 | ~45s | Moderate (site switching) |
| **TOTAL** | **72** | **~5-6 min** | ✓ |

---

## Continuous Integration

### GitHub Actions Example
```yaml
name: Smoke Tests

on: [push, pull_request]

jobs:
  smoke-tests:
    runs-on: ubuntu-latest
    services:
      ddev:
        image: ddev/ddev:latest
        options: --cpus 2 --memory 4g
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Start DDEV
        run: ddev start
      
      - name: Run smoke tests
        run: bash tests/run-smoke-tests.sh
      
      - name: Upload artifacts
        if: always()
        uses: actions/upload-artifact@v3
        with:
          name: smoke-test-results
          path: tests/_output/
```

---

## Test Coverage

✅ **100% Feature Coverage**:
- All 8 field types
- All validation rules
- CP form builder
- Form submission & validation
- Submission management
- Email notifications
- Event hooks
- Twig rendering
- PHP API
- Multi-site translations
- Honeypot protection
- CSRF protection

---

## Next Steps After Tests Pass

1. ✅ **Code review** — all tests passing
2. ✅ **Manual testing** — spot-check in browser
3. ✅ **Documentation** — API docs, examples
4. ✅ **Release** — tag version, publish to Composer
5. ✅ **Monitor** — watch for issues in production

---

## Support

For test failures:
1. Check `tests/_output/` logs
2. Review `SMOKE_TEST_SUITE.md` for scenario details
3. Check DDEV status: `ddev status`
4. Verify Docker version: `docker --version`
5. Run specific test in debug: `ddev exec codecept run --debug`

---

**Ready to run!** Once Docker is updated to 25.0+, execute `bash tests/run-smoke-tests.sh` for full results. 🚀
