<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\AbstractGoogleIntegration;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\integrations\GoogleSheetsIntegration;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * A Google Sheets connector with a swappable Guzzle mock so the full
 * `send()` → dispatch-log path can run against a real submission without
 * touching the live Sheets API.
 */
class MockGoogleSheets extends GoogleSheetsIntegration
{
    public static ?MockHandler $handler = null;

    public static function handle(): string
    {
        return 'mock_google_sheets';
    }

    protected function httpClient(): Client
    {
        return new Client(['handler' => HandlerStack::create(self::$handler)]);
    }
}

/**
 * @group requires-craft
 */
class GoogleSheetsDispatchTest extends SimpleFormTestCase
{
    private const VALID_SA_KEY = ['client_email' => 'svc@p.iam.gserviceaccount.com'];

    protected function _before(): void
    {
        parent::_before();
        Plugin::getInstance()->getIntegrationTypeRegistry()->registerType(MockGoogleSheets::class);
    }

    // =========================================================================
    // send() → dispatch-log
    // =========================================================================

    public function testSuccessfulAppendLogsSuccess(): void
    {
        $this->requireCraft();
        MockGoogleSheets::$handler = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'ya29.x', 'expires_in' => 3600])),
            new Response(200, [], '{"updates":{"updatedRows":1}}'),
        ]);

        [$integration, $submission] = $this->seed([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->serviceAccountKeyJson(),
            'spreadsheetId' => 'sheet-1',
            'worksheet' => 'Leads',
            'columnMapping' => [['handle' => 'name', 'column' => 'Name']],
        ]);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $submission);

        $this->assertTrue($result->success);
        $log = $this->latestLog((int) $submission->id);
        $this->assertSame(DispatchStatus::SUCCESS, $log['status']);
        $this->assertSame(200, (int) $log['responseCode']);
    }

    public function testRenamedWorksheetLogsFailure(): void
    {
        $this->requireCraft();
        MockGoogleSheets::$handler = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'ya29.x', 'expires_in' => 3600])),
            new Response(400, [], json_encode(['error' => ['message' => 'Unable to parse range: Gone!A:A']])),
        ]);

        [$integration, $submission] = $this->seed([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->serviceAccountKeyJson(),
            'spreadsheetId' => 'sheet-1',
            'worksheet' => 'Gone',
            'columnMapping' => [['handle' => 'name', 'column' => 'Name']],
        ]);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $submission);

        $this->assertFalse($result->success);
        $log = $this->latestLog((int) $submission->id);
        $this->assertSame(DispatchStatus::FAILED, $log['status']);
        $this->assertSame(400, (int) $log['responseCode']);
        $this->assertStringContainsString('Unable to parse range', (string) $log['message']);
    }

    public function testExpiredTokenIsRefreshedAndRetried(): void
    {
        $this->requireCraft();
        MockGoogleSheets::$handler = new MockHandler([
            // First token mint.
            new Response(200, [], json_encode(['access_token' => 'stale', 'expires_in' => 3600])),
            // Append returns 401 (token revoked).
            new Response(401, [], json_encode(['error' => ['message' => 'Invalid Credentials']])),
            // Forced refresh mints a fresh token.
            new Response(200, [], json_encode(['access_token' => 'fresh', 'expires_in' => 3600])),
            // Retry succeeds.
            new Response(200, [], '{"updates":{"updatedRows":1}}'),
        ]);

        [$integration, $submission] = $this->seed([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->serviceAccountKeyJson(),
            'spreadsheetId' => 'sheet-1',
            'worksheet' => 'Leads',
            'columnMapping' => [['handle' => 'name', 'column' => 'Name']],
        ]);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $submission);

        $this->assertTrue($result->success, '401 should be transparently refreshed-and-retried');
        $this->assertSame(DispatchStatus::SUCCESS, $this->latestLog((int) $submission->id)['status']);
    }

    public function testHeaderRowWrittenOnEmptySheetOnly(): void
    {
        $this->requireCraft();
        MockGoogleSheets::$handler = new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'ya29.x', 'expires_in' => 3600])),
            // sheetIsEmpty probe → no values yet.
            new Response(200, [], '{}'),
            // header append.
            new Response(200, [], '{}'),
            // row append.
            new Response(200, [], '{"updates":{"updatedRows":1}}'),
        ]);

        [$integration, $submission] = $this->seed([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->serviceAccountKeyJson(),
            'spreadsheetId' => 'sheet-1',
            'worksheet' => 'Leads',
            'writeHeader' => true,
            'columnMapping' => [['handle' => 'name', 'column' => 'Name']],
        ]);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $submission);
        $this->assertTrue($result->success);
    }

    // =========================================================================
    // Settings validation + secret handling
    // =========================================================================

    public function testValidateSettingsRejectsIncompleteConfig(): void
    {
        $this->requireCraft();
        $type = new GoogleSheetsIntegration();
        $errors = Plugin::getInstance()->getIntegrations()->validateSettings($type, [
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => '{not json',
            'spreadsheetId' => '',
            'columnMapping' => [],
        ]);

        $this->assertArrayHasKey('spreadsheetId', $errors);
        $this->assertArrayHasKey('serviceAccountKey', $errors);
        $this->assertArrayHasKey('columnMapping', $errors);
    }

    public function testValidateSettingsAcceptsValidConfig(): void
    {
        $this->requireCraft();
        $type = new GoogleSheetsIntegration();
        $errors = Plugin::getInstance()->getIntegrations()->validateSettings($type, [
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->serviceAccountKeyJson(),
            'spreadsheetId' => 'https://docs.google.com/spreadsheets/d/abc/edit',
            'worksheet' => 'Leads',
            'columnMapping' => [['handle' => 'name', 'column' => 'Name']],
        ]);

        $this->assertSame([], $errors);
    }

    public function testServiceAccountKeyIsStoredEncrypted(): void
    {
        $this->requireCraft();
        $plain = $this->serviceAccountKeyJson();
        [$integration] = $this->seed([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $plain,
            'spreadsheetId' => 'sheet-1',
            'columnMapping' => [['handle' => 'name', 'column' => 'Name']],
        ]);

        $stored = (new Query())
            ->select(['settings'])
            ->from('{{%simpleform_integrations}}')
            ->where(['id' => $integration->id])
            ->scalar();

        $this->assertIsString($stored);
        $this->assertStringNotContainsString('private_key', $stored, 'the plaintext key must not sit in the DB');
        $this->assertStringContainsString('sfenc:', $stored, 'the secret is stored as ciphertext');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Seed a form + field + submission and a saved Google Sheets integration.
     *
     * @param array<string, mixed> $settings
     * @return array{0: IntegrationModel, 1: Submission}
     */
    private function seed(array $settings): array
    {
        $handle = 'gs_' . substr(md5(serialize($settings) . microtime()), 0, 8);
        $form = $this->createForm('GS ' . $handle, $handle);
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $submission = new Submission();
        $submission->formId = (int) $form->id;
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->data = ['field_' . $fieldId => ['label' => 'Name', 'type' => 'text', 'value' => 'Ada']];
        $submission->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($submission));

        $service = Plugin::getInstance()->getIntegrations();
        $integration = new IntegrationModel();
        $integration->type = MockGoogleSheets::handle();
        $integration->name = 'GS test';
        $integration->enabled = true;
        $integration->settings = $settings;
        $this->assertTrue($service->saveIntegration($integration));

        return [$integration, $submission];
    }

    /** @return array<string, mixed> */
    private function latestLog(int $submissionId): array
    {
        /** @var array<string, mixed>|null $row */
        $row = (new Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $submissionId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertNotNull($row, 'a dispatch-log row should exist');
        return $row;
    }

    private function serviceAccountKeyJson(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKey);
        return (string) json_encode(self::VALID_SA_KEY + ['private_key' => $privateKey]);
    }
}
