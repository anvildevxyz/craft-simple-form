# Simple Form Plugin Tests

This directory contains the test suite for the Simple Form plugin.

## Smoke Tests

Smoke tests validate the core functionality of the form builder:

- **FormBuilderCest.php**: CP form builder workflows (create, edit, list)
- **FormSubmissionCest.php**: Frontend form submission and validation (in future)
- **SubmissionManagementCest.php**: Submission browsing and management in CP (in future)

## Running Tests

### Setup

1. Install dev dependencies:
   ```bash
   composer install --dev
   ```

2. Initialize Craft test environment (usually done by Craft)

### Run All Tests

```bash
vendor/bin/codecept run
```

### Run Specific Test

```bash
vendor/bin/codecept run tests/smoke/FormBuilderCest.php
```

### Debug Mode

```bash
vendor/bin/codecept run --debug
```

## Test Database

Tests use a separate test database to avoid affecting production data. The test database is typically defined in `config/test.php`.

## Fixture Data

Test fixtures are stored in `tests/_data/` and loaded automatically per test scenario.
