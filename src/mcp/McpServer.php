<?php

namespace fabianhaef\simpleform\mcp;

use Craft;
use fabianhaef\simpleform\mcp\resources\FormSchemaResource;
use fabianhaef\simpleform\mcp\resources\ResourceProviderInterface;
use fabianhaef\simpleform\mcp\resources\SubmissionsDatasetResource;
use fabianhaef\simpleform\mcp\tools\AddFieldTool;
use fabianhaef\simpleform\mcp\tools\CategorizeSubmissionsTool;
use fabianhaef\simpleform\mcp\tools\CreateFormTool;
use fabianhaef\simpleform\mcp\tools\DeleteFieldTool;
use fabianhaef\simpleform\mcp\tools\DeleteFormTool;
use fabianhaef\simpleform\mcp\tools\DetectSpamPatternsTool;
use fabianhaef\simpleform\mcp\tools\ExportSubmissionsTool;
use fabianhaef\simpleform\mcp\tools\GetFormTool;
use fabianhaef\simpleform\mcp\tools\GetSubmissionTool;
use fabianhaef\simpleform\mcp\tools\ListFormsTool;
use fabianhaef\simpleform\mcp\tools\QuerySubmissionsTool;
use fabianhaef\simpleform\mcp\tools\ReorderFieldsTool;
use fabianhaef\simpleform\mcp\tools\SubmissionStatsTool;
use fabianhaef\simpleform\mcp\tools\SummarizeSubmissionsTool;
use fabianhaef\simpleform\mcp\tools\ToolInterface;
use fabianhaef\simpleform\mcp\tools\UpdateFieldTool;
use fabianhaef\simpleform\mcp\tools\UpdateFormTool;

/**
 * The transport-agnostic MCP server: capability handshake, tool listing, and
 * scope-gated tool dispatch over JSON-RPC 2.0.
 *
 * This class knows nothing about HTTP — it takes a decoded JSON-RPC request
 * (plus the already-resolved, already-authenticated {@see McpToken}) and
 * returns a decoded JSON-RPC response array. The controller
 * ({@see \fabianhaef\simpleform\controllers\McpController}) owns the transport
 * (parsing the POST body, the Authorization header, and writing the response),
 * which keeps this dispatcher reusable if/when SSE streaming is added.
 *
 * SECURITY: every tool is gated by the single scope it declares. Dispatch is
 * deny-by-default — a tool runs only when the token explicitly holds its scope.
 */
class McpServer
{
    /** The protocol version this server implements (MCP spec, current). */
    public const PROTOCOL_VERSION = '2025-06-18';

    // JSON-RPC 2.0 standard error codes.
    private const ERR_INVALID_REQUEST = -32600;
    private const ERR_METHOD_NOT_FOUND = -32601;
    private const ERR_INVALID_PARAMS = -32602;
    // Implementation-defined: token lacks the scope a tool requires.
    private const ERR_FORBIDDEN = -32001;

    /**
     * The tools this server exposes. Add later-slice tools (#64/#65/#66/#67)
     * here; each declares its own required scope.
     *
     * @return list<ToolInterface>
     */
    private function tools(): array
    {
        return [
            // Form management (#65) — scope: forms:manage.
            new ListFormsTool(),
            new GetFormTool(),
            new CreateFormTool(),
            new UpdateFormTool(),
            new DeleteFormTool(),
            new AddFieldTool(),
            new UpdateFieldTool(),
            new ReorderFieldsTool(),
            new DeleteFieldTool(),
            // Submissions (#64) — scopes: submissions:read / submissions:export.
            new QuerySubmissionsTool(),
            new GetSubmissionTool(),
            new ExportSubmissionsTool(),
            new SubmissionStatsTool(),
            // AI-insight (#67) — scope: submissions:read.
            new SummarizeSubmissionsTool(),
            new CategorizeSubmissionsTool(),
            new DetectSpamPatternsTool(),
        ];
    }

    /**
     * The resource providers this server exposes (#66). Each owns one URI scheme
     * and declares the single scope a token must hold to list AND read it.
     *
     * @return list<ResourceProviderInterface>
     */
    private function resourceProviders(): array
    {
        return [
            // form://{handle} — form schema. Scope: forms:manage.
            new FormSchemaResource(),
            // submissions://{handle} — submission dataset. Scope: submissions:read.
            new SubmissionsDatasetResource(),
        ];
    }

    /**
     * Handle a single decoded JSON-RPC message and return the decoded response
     * (or null for a notification, which gets no response per JSON-RPC).
     *
     * @param array<string, mixed> $request    Decoded JSON-RPC request object.
     * @param McpToken             $token       The authenticated token (already validated by the controller).
     * @return array<string, mixed>|null
     */
    public function handle(array $request, McpToken $token): ?array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? null;

        if (!is_string($method) || ($request['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, self::ERR_INVALID_REQUEST, 'Invalid JSON-RPC request.');
        }

        /** @var array<string, mixed> $params */
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        // Notifications (no id) expect no response. The only one we care about
        // is notifications/initialized; acknowledge silently.
        $isNotification = !array_key_exists('id', $request);

        switch ($method) {
            case 'initialize':
                return $this->result($id, $this->initializeResult());

            case 'ping':
                return $this->result($id, (object)[]);

            case 'notifications/initialized':
                return null;

            case 'tools/list':
                return $this->result($id, ['tools' => $this->toolDescriptors()]);

            case 'tools/call':
                return $this->handleToolCall($id, $params, $token);

            case 'resources/list':
                return $this->result($id, ['resources' => $this->resourceDescriptors($token)]);

            case 'resources/read':
                return $this->handleResourceRead($id, $params, $token);

            default:
                if ($isNotification) {
                    return null;
                }
                return $this->error($id, self::ERR_METHOD_NOT_FOUND, "Method not found: $method");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeResult(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                // Tools are static within a session.
                'tools' => ['listChanged' => false],
                // Resources (#66): no server-initiated change notifications and
                // no per-resource subscriptions in this transport.
                'resources' => ['subscribe' => false, 'listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'simple-form-mcp',
                'title' => 'Simple Form MCP Server',
                'version' => (string)(\fabianhaef\simpleform\Plugin::getInstance()->version ?? '1.0.0'),
            ],
            'instructions' => 'Simple Form MCP server. Tools and resources are gated by token '
                . 'scope (deny-by-default). Resources: form://{handle} (schema, forms:manage) and '
                . 'submissions://{handle} (dataset, submissions:read).',
        ];
    }

    /**
     * Tool descriptors for tools/list. We do NOT filter by the caller's scope:
     * advertising the full surface is fine, and a call to a tool outside the
     * token's scope is still rejected at tools/call. (A future slice could hide
     * out-of-scope tools, but visibility is not a security boundary here.)
     *
     * @return list<array<string, mixed>>
     */
    private function toolDescriptors(): array
    {
        $descriptors = [];
        foreach ($this->tools() as $tool) {
            $descriptors[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return $descriptors;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolCall(mixed $id, array $params, McpToken $token): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->error($id, self::ERR_INVALID_PARAMS, 'Missing tool name.');
        }

        $tool = null;
        foreach ($this->tools() as $candidate) {
            if ($candidate->name() === $name) {
                $tool = $candidate;
                break;
            }
        }

        if ($tool === null) {
            return $this->error($id, self::ERR_METHOD_NOT_FOUND, "Unknown tool: $name");
        }

        // SCOPE ENFORCEMENT — deny-by-default. A token may only invoke a tool
        // whose required scope it explicitly holds.
        if (!$token->hasScope($tool->requiredScope())) {
            // Audit the denial with the token identity (label/id), never the secret.
            Craft::info(
                sprintf(
                    'MCP scope denied: token "%s" (%s) lacks scope "%s" for tool "%s"',
                    $token->label,
                    $token->id,
                    $tool->requiredScope(),
                    $name,
                ),
                'simple-form',
            );
            // Generic message: do not disclose which scope is missing to the
            // caller (avoids scope enumeration). The detail is in the server log.
            return $this->error($id, self::ERR_FORBIDDEN, 'Forbidden: token is not authorized for this tool.');
        }

        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        // AUDIT: every authenticated, authorised tool invocation is attributable
        // to the token identity. Writes (later slices) MUST log here too.
        Craft::info(
            sprintf('MCP tool call: token "%s" (%s) invoked "%s"', $token->label, $token->id, $name),
            'simple-form',
        );

        try {
            $structured = $tool->call($arguments);
        } catch (\Throwable $e) {
            // Tool-execution errors are reported in-band (isError:true), not as
            // a JSON-RPC protocol error. Do not leak internals to the client.
            Craft::error('MCP tool "' . $name . '" failed: ' . $e->getMessage(), 'simple-form');
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Tool execution failed.']],
                'isError' => true,
            ]);
        }

        // A tool can flag a DOMAIN error (e.g. validation failure, not-found,
        // missing confirmation) by returning an "isError" key in its structured
        // result. Per MCP this is reported in-band (isError:true) with the
        // structured detail preserved — NOT as a JSON-RPC protocol error and
        // never a 500 — so the caller sees the actual validation errors and can
        // correct its input. Anything else is a successful call.
        $isError = ($structured['isError'] ?? false) === true;

        // Per MCP: structured content SHOULD also be serialised into a text
        // content block for backwards compatibility.
        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => (string)json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
            'structuredContent' => $structured,
            'isError' => $isError,
        ]);
    }

    /**
     * Resource descriptors for resources/list. Unlike tools/list, this IS
     * scope-aware: only resources whose required scope the token holds are
     * listed. For resources, hiding an out-of-scope URI is part of the privacy
     * boundary (a submissions:// URI must not even be discoverable without
     * submissions:read) — and the read path still enforces independently.
     *
     * @return list<array<string, mixed>>
     */
    private function resourceDescriptors(McpToken $token): array
    {
        $descriptors = [];
        foreach ($this->resourceProviders() as $provider) {
            if (!$token->hasScope($provider->requiredScope())) {
                continue;
            }
            foreach ($provider->list() as $descriptor) {
                $descriptors[] = $descriptor;
            }
        }

        return $descriptors;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleResourceRead(mixed $id, array $params, McpToken $token): array
    {
        $uri = $params['uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            return $this->error($id, self::ERR_INVALID_PARAMS, 'Missing resource uri.');
        }

        $provider = null;
        foreach ($this->resourceProviders() as $candidate) {
            if ($candidate->handles($uri)) {
                $provider = $candidate;
                break;
            }
        }

        if ($provider === null) {
            return $this->error($id, self::ERR_METHOD_NOT_FOUND, "Unknown resource: $uri");
        }

        // SCOPE ENFORCEMENT — deny-by-default, INDEPENDENT of list visibility. A
        // token may only read a resource whose required scope it explicitly holds.
        if (!$token->hasScope($provider->requiredScope())) {
            Craft::info(
                sprintf(
                    'MCP resource scope denied: token "%s" (%s) lacks scope "%s" for resource "%s"',
                    $token->label,
                    $token->id,
                    $provider->requiredScope(),
                    $uri,
                ),
                'simple-form',
            );
            return $this->error($id, self::ERR_FORBIDDEN, 'Forbidden: token is not authorized for this resource.');
        }

        Craft::info(
            sprintf('MCP resource read: token "%s" (%s) read "%s"', $token->label, $token->id, $uri),
            'simple-form',
        );

        try {
            $result = $provider->read($uri);
        } catch (\Throwable $e) {
            Craft::error('MCP resource read "' . $uri . '" failed: ' . $e->getMessage(), 'simple-form');
            return $this->error($id, self::ERR_INVALID_PARAMS, 'Resource read failed.');
        }

        if (array_key_exists('isError', $result)) {
            // A resolvable-scheme but missing resource is a JSON-RPC error per the
            // MCP resources spec (e.g. resource not found).
            return $this->error($id, self::ERR_INVALID_PARAMS, $result['error']);
        }

        return $this->result($id, ['contents' => $result['contents']]);
    }

    /**
     * @param array<string, mixed>|object $result
     * @return array<string, mixed>
     */
    public function result(mixed $id, array|object $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    public function error(mixed $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
