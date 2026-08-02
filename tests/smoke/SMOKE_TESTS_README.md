# Simple Form — Functional Smoke Suite

Codeception **functional** smoke tests for Simple Form's public render and submit
paths. Each Cest boots a real Craft via the `\craft\test\Craft` module inside a
per-test DB transaction (configured in the root `codeception.yml`), seeds forms +
fields through the data layer, and exercises the real services — no JS Control
Panel, no browser.

## Running

The Craft DB host (`db`) only resolves inside DDEV, so the suite must run there:

```bash
ddev exec -d /var/www/html/plugins/simple-form 'composer test:smoke'
# or, equivalently:
ddev exec -d /var/www/html/plugins/simple-form 'vendor/bin/codecept run smoke'
```

Run a single Cest:

```bash
ddev exec -d /var/www/html/plugins/simple-form 'vendor/bin/codecept run smoke FormRenderingCest'
```

### Run everything sequentially (functional + browser)

```bash
plugins/simple-form/scripts/run-all-smoke.sh
```

This runs Codeception first, then the Playwright browser runner (`scripts/browser-smoke/run-all.mjs`). Reports land in `scripts/browser-smoke/report-YYYY-MM-DD.md`.

## How the suite is split

Two kinds of Cests live here:

1. **Functional Cests** — run for real against the booted Craft. They seed data
   through `BaseSmokeCest` (`createForm` / `createField`), render via the real
   `FormRenderService`, and submit via the shared
   `SubmissionService::createFromRequest()` / `submit()` entry points — the exact
   paths the front-end `SubmitController` and the GraphQL mutation use.

2. **Playwright-only Cests** — every test method is `markTestSkipped()` with the
   reason _"CP UI / browser-only — covered by the Playwright craft-smoke-test
   scenarios in the browser smoke suite"_. These drive the JS form builder, the CP
   submission index, Mailpit delivery, canvas/signature capture, or Twig-tag site
   rendering that the console-booted Codeception actor cannot exercise. They are
   covered end-to-end by the Playwright browser smoke suite, and
   their data-layer behaviour is additionally covered by `tests/integration`.

### Functional Cests (run for real)

| Cest | Covers |
|------|--------|
| `FormRenderingCest` | Rendered `<form>` HTML: action/method, CSRF + honeypot + `formHandle` hidden inputs, per-field markup for every field type, inline-vs-bundled assets. |
| `FormSubmissionCest` | End-to-end submit flow: multi-field persistence, value round-trip per field type, min/max + email validation. |
| `FormSubmissionAndValidationCest` | Validation matrix + persisted-data shape through the shared submit path; honeypot silent-drop; unknown-handle envelope. |
| `PostSubmitBehaviorCest` | Per-form success message interpolation, global fallback, URL/entry redirect resolution via `resolvePostSubmit()`. |
| `FormSchedulingCest` | Open/close window + quota: closed-message render + server-side rejection of crafted submissions. |
| `SpamDenylistCest` | Denylist enforcement (keyword/email, flag vs block) through the public submit path. |
| `UserLimitsCest` | Login-required guest rejection + per-user cap notice/rejection. |
| `IntegrationsSmokeCest` | Global integration save/attach, sync dispatch on submit, failure logging, disabled-integration skip. |
| `NotificationsSmokeCest` | Legacy `emailTo` + per-form notification rows: recipient resolution, autoresponder, no-recipient short-circuit. |
| `SaveResumeSmokeCest` | Draft round-trip, save-draft controller, opt-in gating, rate limit, resume prefill render, token hashing. |
| `FieldTypesSmokeCest` | Hidden (query), consent audit record, phone E.164 normalization, rating/opinion-scale ints + range rejection. |
| `DuplicatePreventionSmokeCest` | Email/content dedupe keys mark repeats as spam; different values still allowed. |
| `SubmissionEditingSmokeCest` | Edit-token issue/verify, authorizeEdit, in-place update, editing-disabled refusal. |
| `ConditionalLogicSmokeCest` | Hidden required fields skipped; shown required fields enforced server-side. |
| `SecurityHardeningSmokeCest` | Unsafe redirect stripped, safe relative redirect kept, payment bounds, empty payment params (when Commerce installed), draft rate limit (see `SaveResumeSmokeCest`). |

### Playwright-only Cests (skipped here)

`CalculationFieldCest`, `ColumnLayoutCest`, `CraftElementIntegrationCest`,
`EmailAndEventsCest`, `FieldBuilderCest`, `FieldValidationsCest`,
`FormBuilderCest`, `FormBuilderCompleteCest`, `FormImportExportCest`,
`FormStencilsDuplicateCest`, `LayoutBlocksCest`, `NotificationAttachmentsCest`,
`RenderingAndApiCest`, `RepeaterFieldCest`, `SignatureFieldCest`,
`SubmissionEditingCest`, `SubmissionManagementCest`, `TranslationAndMultiSiteCest`.

## Adding a functional Cest

Extend `BaseSmokeCest`, type-hint the `SmokeTester` actor, seed with
`createForm` / `createField`, and assert against the returned envelope or a
reloaded `Submission` element:

```php
class MyCest extends BaseSmokeCest
{
    public function testSomething(SmokeTester $I): void
    {
        $form = $this->createForm('My Form', 'myForm' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name', true);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada']);

        $I->assertNull($result['errors']);
        $I->assertInstanceOf(\anvildev\simpleform\elements\Submission::class, $result['submission']);
    }
}
```

All seeded data is rolled back automatically after each test.
