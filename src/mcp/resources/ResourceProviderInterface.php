<?php

namespace anvildev\simpleform\mcp\resources;

/**
 * Contract for an MCP resource provider — a family of read-only resources
 * sharing one URI scheme (e.g. every {@code form://…} URI is served by one
 * provider).
 *
 * Resources let an agent read plugin state without a tool round-trip. Each
 * provider declares the single scope a token must hold to see and read its
 * resources; the dispatcher ({@see \anvildev\simpleform\mcp\McpServer})
 * enforces it BOTH when listing (scope-aware visibility) and when reading
 * (deny-by-default) — for resources, hiding an out-of-scope URI is part of the
 * privacy boundary, not merely cosmetic.
 *
 * Resource contents reuse the same presenters/serialisation as the tool layer
 * so a resource and the equivalent tool never disagree about the schema.
 *
 * @phpstan-import-type McpError from \anvildev\simpleform\mcp\tools\ToolInterface
 * @phpstan-type ResourceDescriptor array{uri:string, name:string, mimeType:string, title?:string, description?:string}
 * @phpstan-type ResourceContentsEntry array{uri:string, mimeType:string, text:string}
 * @phpstan-type McpResourceContents array{contents:list<ResourceContentsEntry>}
 */
interface ResourceProviderInterface
{
    /**
     * The single scope a token must hold to list OR read any of this provider's
     * resources. Enforced by the dispatcher.
     */
    public function requiredScope(): string;

    /**
     * Concrete resource descriptors for {@code resources/list}: one entry per
     * currently-existing resource, each with at least a {@code uri}, {@code name}
     * and {@code mimeType}. Only called when the caller holds {@see self::requiredScope()}.
     *
     * @return list<ResourceDescriptor>
     */
    public function list(): array;

    /**
     * Whether the given URI belongs to this provider (matches its scheme).
     */
    public function handles(string $uri): bool;

    /**
     * Read one resource by URI, returning its MCP {@code contents} entries
     * (each a {@code {uri, mimeType, text}} block), or an error payload when the
     * URI can't be resolved. Only called when the caller holds the required
     * scope (the dispatcher has already authorised the read).
     *
     * @return McpResourceContents|McpError
     */
    public function read(string $uri): array;
}
