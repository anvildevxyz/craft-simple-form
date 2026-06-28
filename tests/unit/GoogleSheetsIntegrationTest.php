<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\integrations\AbstractGoogleIntegration;
use anvildev\simpleform\integrations\GoogleAuthException;
use anvildev\simpleform\integrations\GoogleSheetsIntegration;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Google Sheets connector with a mocked HTTP client and public seams onto the
 * protected auth/transport so they can be exercised without the live API.
 */
class MockGoogleSheetsIntegration extends GoogleSheetsIntegration
{
    /** @param array<int, array<string, mixed>> $history */
    public function __construct(private MockHandler $mock, private array &$history = [])
    {
    }

    protected function httpClient(): Client
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new Client(['handler' => $stack]);
    }

    /** @param array<string, mixed> $settings */
    public function accessTokenPublic(array $settings, bool $forceRefresh = false): string
    {
        return $this->accessToken($settings, $forceRefresh);
    }
}

class GoogleSheetsIntegrationTest extends TestCase
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    // =========================================================================
    // Identity / registration
    // =========================================================================

    public function testHandleAndDisplayName(): void
    {
        $this->assertSame('google-sheets', GoogleSheetsIntegration::handle());
        $this->assertSame('Google Sheets', GoogleSheetsIntegration::displayName());
    }

    // =========================================================================
    // Spreadsheet ID extraction
    // =========================================================================

    public function testExtractSpreadsheetIdFromUrl(): void
    {
        $sheets = new GoogleSheetsIntegration();
        $url = 'https://docs.google.com/spreadsheets/d/1AbC-dEf_123/edit#gid=0';
        $this->assertSame('1AbC-dEf_123', $sheets->extractSpreadsheetId($url));
    }

    public function testExtractSpreadsheetIdFromBareId(): void
    {
        $sheets = new GoogleSheetsIntegration();
        $this->assertSame('1AbC-dEf_123', $sheets->extractSpreadsheetId('  1AbC-dEf_123  '));
    }

    public function testExtractSpreadsheetIdEmpty(): void
    {
        $this->assertSame('', (new GoogleSheetsIntegration())->extractSpreadsheetId(''));
    }

    // =========================================================================
    // Service-account JWT assembly
    // =========================================================================

    public function testBuildServiceAccountJwtIsSignedAndDecodable(): void
    {
        $key = $this->serviceAccountKey();
        $jwt = (new GoogleSheetsIntegration())->buildServiceAccountJwt($key, self::SCOPE, now: 1_000_000);

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT is header.claims.signature');

        $claims = json_decode($this->base64UrlDecode($parts[1]), true);
        $this->assertSame($key['client_email'], $claims['iss']);
        $this->assertSame(self::SCOPE, $claims['scope']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame(1_000_000, $claims['iat']);
        $this->assertSame(1_003_600, $claims['exp']);

        // Verify the RS256 signature against the public key.
        $signingInput = $parts[0] . '.' . $parts[1];
        $private = openssl_pkey_get_private($key['private_key']);
        $this->assertNotFalse($private);
        $details = openssl_pkey_get_details($private);
        $this->assertIsArray($details);
        $ok = openssl_verify($signingInput, $this->base64UrlDecode($parts[2]), $details['key'], OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok, 'JWT signature verifies against the key');
    }

    public function testBuildServiceAccountJwtThrowsOnBadKey(): void
    {
        $this->expectException(GoogleAuthException::class);
        (new GoogleSheetsIntegration())->buildServiceAccountJwt(
            ['client_email' => 'a@b.test', 'private_key' => 'not-a-key'],
            self::SCOPE,
        );
    }

    // =========================================================================
    // Token exchange (service account + OAuth refresh)
    // =========================================================================

    public function testServiceAccountTokenExchangePostsJwtAssertion(): void
    {
        $history = [];
        $sheets = new MockGoogleSheetsIntegration(
            new MockHandler([new Response(200, [], $this->json(['access_token' => 'ya29.sa', 'expires_in' => 3600]))]),
            $history,
        );

        $token = $sheets->accessTokenPublic([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->json($this->serviceAccountKey()),
        ]);

        $this->assertSame('ya29.sa', $token);
        $this->assertCount(1, $history);
        /** @var Request $request */
        $request = $history[0]['request'];
        $this->assertSame('https://oauth2.googleapis.com/token', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $this->assertArrayHasKey('assertion', $form);
    }

    public function testOauthRefreshTokenExchange(): void
    {
        $history = [];
        $sheets = new MockGoogleSheetsIntegration(
            new MockHandler([new Response(200, [], $this->json(['access_token' => 'ya29.oauth', 'expires_in' => 3600]))]),
            $history,
        );

        $token = $sheets->accessTokenPublic([
            'authMode' => AbstractGoogleIntegration::AUTH_OAUTH,
            'refreshToken' => 'refresh-abc',
            'clientId' => 'client-123',
            'clientSecret' => 'secret-xyz',
        ]);

        $this->assertSame('ya29.oauth', $token);
        parse_str((string) $history[0]['request']->getBody(), $form);
        $this->assertSame('refresh_token', $form['grant_type']);
        $this->assertSame('refresh-abc', $form['refresh_token']);
        $this->assertSame('client-123', $form['client_id']);
    }

    public function testTokenIsCachedAcrossCalls(): void
    {
        $history = [];
        // Only one token response queued: a second exchange would throw.
        $sheets = new MockGoogleSheetsIntegration(
            new MockHandler([new Response(200, [], $this->json(['access_token' => 'cached', 'expires_in' => 3600]))]),
            $history,
        );

        $settings = [
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->json($this->serviceAccountKey()),
        ];
        $this->assertSame('cached', $sheets->accessTokenPublic($settings));
        $this->assertSame('cached', $sheets->accessTokenPublic($settings));
        $this->assertCount(1, $history, 'second call reuses the cached token');
    }

    public function testTokenExchangeFailureThrows(): void
    {
        $sheets = new MockGoogleSheetsIntegration(
            new MockHandler([new Response(401, [], $this->json(['error' => 'invalid_grant']))]),
        );

        $this->expectException(GoogleAuthException::class);
        $sheets->accessTokenPublic([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->json($this->serviceAccountKey()),
        ]);
    }

    public function testMalformedServiceAccountKeyThrows(): void
    {
        $sheets = new MockGoogleSheetsIntegration(new MockHandler([]));
        $this->expectException(GoogleAuthException::class);
        $sheets->accessTokenPublic([
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => '{not valid',
        ]);
    }

    // =========================================================================
    // appendRow transport
    // =========================================================================

    public function testAppendRowSuccess(): void
    {
        $history = [];
        $sheets = new MockGoogleSheetsIntegration(new MockHandler([new Response(200, [], '{}')]), $history);
        $result = $sheets->appendRow('sheet-1', 'Sheet1', ['Ada', 'ada@example.test'], 'bearer-1');

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->responseCode);

        /** @var Request $request */
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringContainsString('/spreadsheets/sheet-1/values/Sheet1:append', (string) $request->getUri());
        $this->assertStringContainsString('valueInputOption=RAW', (string) $request->getUri());
        $this->assertSame('Bearer bearer-1', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame([['Ada', 'ada@example.test']], $body['values']);
    }

    public function testAppendRowFailureCarriesScrubbableReason(): void
    {
        $errorBody = $this->json(['error' => ['message' => 'Unable to parse range: NoSuchTab!A:A']]);
        $sheets = new MockGoogleSheetsIntegration(new MockHandler([new Response(400, [], $errorBody)]));
        $result = $sheets->appendRow('sheet-1', 'NoSuchTab', ['x'], 'bearer-1');

        $this->assertFalse($result->success);
        $this->assertSame(400, $result->responseCode);
        $this->assertStringContainsString('Unable to parse range', $result->message);
        // The bearer token must never appear in the failure message (F7).
        $this->assertStringNotContainsString('bearer-1', $result->message);
    }

    // =========================================================================
    // fetchWorksheets
    // =========================================================================

    public function testFetchWorksheetsListsTitles(): void
    {
        $body = $this->json(['sheets' => [
            ['properties' => ['title' => 'Leads']],
            ['properties' => ['title' => 'Archive']],
        ]]);
        $sheets = new MockGoogleSheetsIntegration(new MockHandler([
            new Response(200, [], $this->json(['access_token' => 't', 'expires_in' => 3600])),
            new Response(200, [], $body),
        ]));

        $titles = $sheets->fetchWorksheets('sheet-1', [
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->json($this->serviceAccountKey()),
        ]);

        $this->assertSame(['Leads', 'Archive'], $titles);
    }

    public function testFetchWorksheetsReturnsNullOnFailure(): void
    {
        $sheets = new MockGoogleSheetsIntegration(new MockHandler([
            new Response(200, [], $this->json(['access_token' => 't', 'expires_in' => 3600])),
            new Response(403, [], 'forbidden'),
        ]));

        $titles = $sheets->fetchWorksheets('sheet-1', [
            'authMode' => AbstractGoogleIntegration::AUTH_SERVICE_ACCOUNT,
            'serviceAccountKey' => $this->json($this->serviceAccountKey()),
        ]);

        $this->assertNull($titles);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A throwaway RSA service-account key for signing/exchange tests.
     *
     * @return array{client_email: string, private_key: string}
     */
    private function serviceAccountKey(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($res);
        openssl_pkey_export($res, $privateKey);
        return [
            'client_email' => 'svc@project.iam.gserviceaccount.com',
            'private_key' => (string) $privateKey,
        ];
    }

    private function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * JSON-encode for a test fixture, asserting success (PHPStan: always string).
     *
     * @param mixed $value
     */
    private function json($value): string
    {
        $json = json_encode($value);
        $this->assertNotFalse($json);
        return $json;
    }
}
