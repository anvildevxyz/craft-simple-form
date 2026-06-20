<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\mcp\McpServer;
use fabianhaef\simpleform\mcp\McpToken;
use fabianhaef\simpleform\mcp\TokenManager;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

/**
 * MCP transport endpoint (Streamable HTTP).
 *
 * Exposes a single POST route (`simple-form/mcp`) that speaks the Model Context
 * Protocol over JSON-RPC 2.0. A standard MCP client connects here with a
 * `Authorization: Bearer <token>` header.
 *
 * TRANSPORT NOTES
 * ---------------
 * This implements the request/response half of MCP's Streamable HTTP transport:
 * one POST whose body is a JSON-RPC request and whose response is
 * `application/json`. That is sufficient for the synchronous request/response
 * tools in this foundation. SSE streaming (server-initiated messages over a GET
 * stream) is intentionally NOT implemented yet; the dispatch is isolated in
 * {@see McpServer} so an SSE action can be added later without touching auth or
 * tool logic.
 *
 * SECURITY POSTURE
 * ----------------
 *  - CSRF is disabled: this is a token-authenticated machine API, not a browser
 *    form. There is no session/cookie auth to protect, so the CSRF token would
 *    be meaningless (and unobtainable by an MCP client).
 *  - Anonymous Craft session is allowed: authentication is the bearer token, not
 *    a CP login. We override Craft's auth so the action runs without a logged-in
 *    user, then enforce the token ourselves.
 *  - The server is OFF by default. When `enableMcp` is false the endpoint
 *    refuses with 404 (it pretends not to exist, leaking nothing about the
 *    feature) before doing any work.
 */
class McpController extends Controller
{
    /**
     * Allow the endpoint to be hit without a logged-in CP/front-end user; the
     * bearer token is the credential.
     *
     * @var array<string, int>|bool|int
     */
    protected array|bool|int $allowAnonymous = true;

    /** Token-authenticated API: no browser CSRF token to validate. */
    public $enableCsrfValidation = false;

    /** Failed-auth attempts per IP within {@see AUTH_FAIL_WINDOW} before a 429. */
    private const AUTH_FAIL_MAX = 20;

    /** Sliding window (seconds) for the failed-auth counter. */
    private const AUTH_FAIL_WINDOW = 300;

    /**
     * The MCP endpoint. Single POST → single JSON-RPC response.
     */
    public function actionIndex(): Response
    {
        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $response->format = Response::FORMAT_JSON;

        // 1. OFF BY DEFAULT. Refuse with 404 before processing anything so a
        //    disabled server is indistinguishable from an unmapped route.
        if (!Plugin::getInstance()->getSettings()->enableMcp) {
            $response->setStatusCode(404);
            $response->data = ['error' => 'Not found.'];
            return $response;
        }

        // 2. Transport: only POST carries JSON-RPC requests in this slice.
        if (!$request->getIsPost()) {
            $response->setStatusCode(405);
            $response->data = $this->jsonRpcError(null, -32600, 'Only POST is supported.');
            return $response;
        }

        // 3. RATE LIMIT (F13, CWE-307): throttle brute-force / unauthenticated
        //    floods per IP. Once too many recent attempts have failed, short-
        //    circuit with 429 before doing any token work.
        $ip = $request->getUserIP() ?? 'unknown';
        $failKey = 'simple-form:mcp-auth-fail:' . $ip;
        $cache = Craft::$app->getCache();
        if ((int) $cache->get($failKey) >= self::AUTH_FAIL_MAX) {
            $response->setStatusCode(429);
            $response->data = $this->jsonRpcError(null, -32000, 'Too many requests.');
            return $response;
        }

        // 4. AUTH: bearer token. A missing/invalid token is a 401 with a generic
        //    message — we never disclose whether the token was absent, malformed,
        //    or simply unknown (avoids oracles).
        $token = $this->authenticate($request);
        if ($token === null) {
            $cache->set($failKey, (int) $cache->get($failKey) + 1, self::AUTH_FAIL_WINDOW);
            $response->getHeaders()->set('WWW-Authenticate', 'Bearer');
            $response->setStatusCode(401);
            $response->data = $this->jsonRpcError(null, -32000, 'Unauthorized.');
            return $response;
        }

        // Best-effort audit/usage tracking. Identity is the label/id, never the secret.
        Plugin::getInstance()->getMcpTokenManager()->touch($token);

        // 5. Parse the JSON-RPC body.
        $raw = $request->getRawBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $response->data = $this->jsonRpcError(null, -32700, 'Parse error.');
            return $response;
        }

        // 6. Dispatch through the transport-agnostic server.
        $server = new McpServer();
        $result = $server->handle($decoded, $token);

        // A notification yields no response body; reply 202 Accepted with no content.
        if ($result === null) {
            $response->setStatusCode(202);
            $response->data = null;
            return $response;
        }

        $response->data = $result;
        return $response;
    }

    /**
     * Resolve the bearer token from the Authorization header to a stored
     * {@see McpToken}, or null if absent/invalid.
     */
    private function authenticate(\craft\web\Request $request): ?McpToken
    {
        $header = (string)$request->getHeaders()->get('Authorization', '');

        // Expect exactly "Bearer <secret>". Case-insensitive scheme per RFC 6750.
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return null;
        }

        $secret = trim($m[1]);

        /** @var TokenManager $manager */
        $manager = Plugin::getInstance()->getMcpTokenManager();
        return $manager->validateSecret($secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRpcError(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
