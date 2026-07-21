<?php

namespace anvildev\simpleform\integrations;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\support\ApiConnector;
use Craft;
use craft\helpers\Json;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Shared base for Google API connectors (Sheets today; Drive/Calendar later).
 * Owns the two auth modes — a service-account JSON key (sign a JWT, exchange it
 * for a bearer) and OAuth2 (exchange a stored refresh token for a bearer) — plus
 * a short-lived access-token cache keyed by credential. The HTTP/mapping plumbing
 * comes from {@see ApiConnector}; subclasses add the resource-specific call.
 *
 * Hand-rolls the JWT + token exchange over Guzzle rather than pulling in
 * `google/apiclient`, keeping the dependency footprint small (the "simple" in
 * Simple Form). All secrets stay out of every exception/log message — the
 * dispatch layer scrubs them, and this base never echoes a key or token.
 *
 * @phpstan-type ServiceAccountKey array{client_email: string, private_key: string, token_uri?: string}
 */
abstract class AbstractGoogleIntegration implements IntegrationTypeInterface
{
    use ApiConnector;

    // =========================================================================
    // Const Properties
    // =========================================================================

    /** Service-account auth: sign a JWT with a pasted JSON key. */
    public const AUTH_SERVICE_ACCOUNT = 'service_account';

    /** OAuth2 auth: exchange a stored refresh token for an access token. */
    public const AUTH_OAUTH = 'oauth';

    /** Google's OAuth2 token endpoint (service-account + refresh-token grants). */
    protected const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /** Refresh a token this many seconds before its stated expiry (clock skew). */
    private const EXPIRY_SKEW = 30;

    // =========================================================================
    // Private Properties
    // =========================================================================

    /**
     * Per-credential access-token cache: `cacheKey => [token, expiresAt]`. Keyed
     * by the credential hash so two integrations sharing a key reuse one token.
     *
     * @var array<string, array{token: string, expiresAt: int}>
     */
    private array $_tokenCache = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    abstract public function settingsHtml(array $settings): string;

    abstract public function send(Submission $submission, array $settings): IntegrationResult;

    abstract public function defineSettingsRules(): array;

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Mint (or return a cached) Google API access token for the configured auth
     * mode. Throws on a credential/exchange failure so the caller surfaces a
     * scrubbed {@see IntegrationResult::failure()}.
     *
     * @param array<string, mixed> $settings env-resolved settings
     * @param bool $forceRefresh skip the cache (used after a 401)
     * @throws GoogleAuthException when the credential is missing/malformed or the exchange fails
     */
    protected function accessToken(array $settings, bool $forceRefresh = false): string
    {
        $mode = (string) ($settings['authMode'] ?? self::AUTH_SERVICE_ACCOUNT);
        $scope = $this->oauthScope();

        if ($mode === self::AUTH_OAUTH) {
            return $this->oauthAccessToken($settings, $scope, $forceRefresh);
        }

        return $this->serviceAccountAccessToken($settings, $scope, $forceRefresh);
    }

    /**
     * The OAuth2 scope(s) this connector needs (space-separated). Subclasses
     * narrow this to the minimum required.
     */
    protected function oauthScope(): string
    {
        return 'https://www.googleapis.com/auth/spreadsheets';
    }

    /**
     * Parse and sanity-check a service-account JSON key. Returns the decoded
     * structure or null when the JSON is malformed or missing required members
     * (`client_email`, `private_key`).
     *
     * @return ServiceAccountKey|null
     */
    protected function parseServiceAccountKey(string $json): ?array
    {
        $json = trim($json);
        if ($json === '') {
            return null;
        }

        $decoded = Json::decodeIfJson($json);
        if (!is_array($decoded)) {
            return null;
        }

        $email = $decoded['client_email'] ?? null;
        $privateKey = $decoded['private_key'] ?? null;
        if (!is_string($email) || $email === '' || !is_string($privateKey) || $privateKey === '') {
            return null;
        }

        $out = ['client_email' => $email, 'private_key' => $privateKey];
        if (isset($decoded['token_uri']) && is_string($decoded['token_uri'])) {
            $out['token_uri'] = $decoded['token_uri'];
        }
        return $out;
    }

    /**
     * Assemble and RS256-sign a service-account JWT assertion for the token
     * exchange. Exposed (internal) so the assembly can be exercised in tests
     * without a live exchange.
     *
     * @param ServiceAccountKey $key
     * @param int|null $now override the clock (tests); defaults to time()
     * @throws GoogleAuthException when the private key can't sign
     * @internal
     */
    public function buildServiceAccountJwt(array $key, string $scope, ?int $now = null): string
    {
        $now ??= time();
        $audience = $key['token_uri'] ?? self::TOKEN_ENDPOINT;

        $header = $this->base64Url(Json::encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64Url(Json::encode([
            'iss' => $key['client_email'],
            'scope' => $scope,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = $header . '.' . $claims;
        $signature = '';
        // An invalid/unusable private key makes openssl_sign() emit a warning and
        // return false; suppress the warning and convert the false into a clean,
        // credential-free exception.
        $signed = @openssl_sign($signingInput, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new GoogleAuthException('Could not sign the service-account assertion (invalid private key).');
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * @param array<string, mixed> $settings
     * @throws GoogleAuthException
     */
    private function serviceAccountAccessToken(array $settings, string $scope, bool $forceRefresh): string
    {
        $key = $this->parseServiceAccountKey((string) ($settings['serviceAccountKey'] ?? ''));
        if ($key === null) {
            throw new GoogleAuthException('Missing or malformed service-account JSON key.');
        }

        $cacheKey = 'sa:' . md5($key['client_email'] . '|' . $scope);
        $cached = $this->cachedToken($cacheKey, $forceRefresh);
        if ($cached !== null) {
            return $cached;
        }

        $assertion = $this->buildServiceAccountJwt($key, $scope);

        // Pin the token endpoint to Google's constant — never the `token_uri` from
        // the uploaded service-account JSON. Honoring that field would let whoever
        // supplies the key POST the signed assertion (and the resulting token
        // exchange) to an arbitrary host, bypassing the SSRF guard (CWE-918).
        return $this->exchange($cacheKey, self::TOKEN_ENDPOINT, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     * @throws GoogleAuthException
     */
    private function oauthAccessToken(array $settings, string $scope, bool $forceRefresh): string
    {
        $refreshToken = trim((string) ($settings['refreshToken'] ?? ''));
        $clientId = trim((string) ($settings['clientId'] ?? ''));
        $clientSecret = trim((string) ($settings['clientSecret'] ?? ''));
        if ($refreshToken === '' || $clientId === '' || $clientSecret === '') {
            throw new GoogleAuthException('Missing OAuth client credentials or refresh token.');
        }

        $cacheKey = 'oauth:' . md5($clientId . '|' . $refreshToken . '|' . $scope);
        $cached = $this->cachedToken($cacheKey, $forceRefresh);
        if ($cached !== null) {
            return $cached;
        }

        return $this->exchange($cacheKey, self::TOKEN_ENDPOINT, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
    }

    /**
     * POST a token request and cache the resulting bearer until just before its
     * stated expiry. The endpoint is constant (Google's) so there is no SSRF
     * surface; failures throw a credential-free {@see GoogleAuthException}.
     *
     * @param array<string, string> $form
     * @throws GoogleAuthException
     */
    private function exchange(string $cacheKey, string $tokenUri, array $form): string
    {
        try {
            $response = $this->httpClient()->request('POST', $tokenUri, [
                'form_params' => $form,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // Don't surface the Guzzle message — it can contain the request body
            // (our assertion / refresh token). Log it server-side only.
            Craft::warning('Google token exchange transport error: ' . $e->getMessage(), 'simple-form');
            throw new GoogleAuthException('Could not reach the Google token endpoint.');
        }

        $code = $response->getStatusCode();
        $decoded = Json::decodeIfJson((string) $response->getBody());
        if ($code < 200 || $code >= 300 || !is_array($decoded) || !isset($decoded['access_token'])) {
            throw new GoogleAuthException("Google token exchange failed (HTTP $code).");
        }

        $token = (string) $decoded['access_token'];
        $expiresIn = isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : 3600;
        $this->_tokenCache[$cacheKey] = [
            'token' => $token,
            'expiresAt' => time() + max(0, $expiresIn - self::EXPIRY_SKEW),
        ];

        return $token;
    }

    /** Return a cached, still-valid token for the key, or null. */
    private function cachedToken(string $cacheKey, bool $forceRefresh): ?string
    {
        if ($forceRefresh) {
            unset($this->_tokenCache[$cacheKey]);
            return null;
        }

        $entry = $this->_tokenCache[$cacheKey] ?? null;
        if ($entry !== null && $entry['expiresAt'] > time()) {
            return $entry['token'];
        }

        return null;
    }

    /** URL-safe, unpadded base64 (JWT/JWS encoding). */
    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
