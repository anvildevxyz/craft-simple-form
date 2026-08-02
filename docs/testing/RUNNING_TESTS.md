# Running tests

Simple Form ships its own Composer dev environment (its own `vendor/`), so all
test and quality tooling runs from the plugin directory. In the DDEV dev setup
that means prefixing commands with
`ddev exec -d /var/www/html/plugins/simple-form '…'`.

## One-time setup

```bash
composer install
```

## Quality gate

```bash
composer check      # ECS + PHPStan (level 7) + unit tests — the full gate
composer ecs        # coding-standard check only
composer ecs-fix    # auto-fix coding-standard issues
composer phpstan     # static analysis only (--memory-limit=1G)
```

## Unit tests

Fast, Craft-free reflection/source-level tests under `tests/unit/`, run via PHPUnit:

```bash
composer test
```

## Integration tests

Codeception suite that boots a real Craft 5 and isolates each test in a DB
transaction (see `codeception.yml`, `tests/integration/`). Requires the
`craft_test` database and a `tests/.env` (copy from `tests/.env.example`).

```bash
composer test:integration
composer test:all          # unit + js + integration + smoke
```

The integration suite covers form create/render, submission create + validation
(with DB round-trip), email notification, and multi-site content resolution.

## JavaScript tests

Node parity tests for the client-side conditional-logic and formula evaluators:

```bash
composer test:js
```

## Smoke tests

A Codeception **functional** smoke suite (`tests/smoke/`, `tests/smoke.suite.yml`)
drives the end-to-end front-end flows — render → submit → assert — headlessly:

```bash
composer test:smoke        # run inside DDEV, like the integration suite
```

CP-builder UI and browser-rendering scenarios that need a real browser are kept
as a Playwright scenario library maintained outside this repository, driven by
`scripts/browser-smoke/run-all.mjs`.
