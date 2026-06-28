<?php

namespace anvildev\simpleform\mcp\tools;

/**
 * Contract for a single MCP tool.
 *
 * A tool is the unit of capability exposed over the MCP transport. Each tool
 * declares the one scope it requires; the dispatcher ({@see \anvildev\simpleform\mcp\McpServer})
 * enforces that scope (deny-by-default) before ever invoking {@see self::call()}.
 *
 * The in-band error envelope tools and resources return on failure (the
 * dispatcher surfaces it to the client rather than throwing):
 *
 * @phpstan-type McpError array{isError:true, error:string}
 */
interface ToolInterface
{
    /** Unique tool name, as advertised in `tools/list` and invoked in `tools/call`. */
    public function name(): string;

    /** Human-readable description for the MCP client. */
    public function description(): string;

    /**
     * JSON Schema for the tool's arguments (MCP `inputSchema`).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * The single scope a token must hold to invoke this tool. Enforced by the
     * dispatcher; the tool itself can assume it has been authorised.
     */
    public function requiredScope(): string;

    /**
     * Execute the tool.
     *
     * @param array<string, mixed> $arguments Validated-by-shape arguments from the client.
     * @return array<string, mixed> Structured result (also serialised to a text content block by the dispatcher).
     */
    public function call(array $arguments): array;
}
