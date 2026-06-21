<?php

namespace fabianhaef\simpleform\mcp\resources;

use fabianhaef\simpleform\elements\Form;

/**
 * Shared plumbing for the form-scoped MCP resource providers ({@code form://…},
 * {@code submissions://…}). Hoists the byte-identical scheme matching, the
 * handle-strip + missing-handle + multi-site lookup + not-found guards, the
 * handle-deduped {@code list()} loop, and the json-encode-into-{@code contents}
 * tail, so a concrete provider only declares its scheme, scope, MIME and the
 * per-resource shaping ({@see self::describe()} / {@see self::payload()}).
 *
 * @phpstan-import-type McpError from \fabianhaef\simpleform\mcp\tools\ToolInterface
 * @phpstan-import-type McpResourceContents from ResourceProviderInterface
 */
abstract class AbstractFormResource implements ResourceProviderInterface
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $resources = [];
        $seen = [];
        // siteId('*') returns one element instance per site; dedupe by handle so
        // a multi-site form yields a single (handle-keyed) resource entry.
        $forms = Form::find()->siteId('*')->status(null)->all();
        foreach ($forms as $form) {
            if (!$form instanceof Form || $form->handle === null || isset($seen[$form->handle])) {
                continue;
            }
            $seen[$form->handle] = true;
            $resources[] = $this->describe($form);
        }

        return $resources;
    }

    /**
     * @inheritdoc
     */
    public function handles(string $uri): bool
    {
        return str_starts_with($uri, $this->scheme() . '://');
    }

    /**
     * @inheritdoc
     *
     * @return McpResourceContents|McpError
     */
    public function read(string $uri): array
    {
        $form = $this->resolveForm($uri);
        if (is_array($form)) {
            return $form;
        }

        return $this->contents($uri, $this->payload($form));
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Resolve the {@see Form} addressed by a {@code <scheme>://<handle>} URI,
     * across all sites, with the shared missing-handle and not-found guards.
     *
     * @return Form|McpError
     */
    protected function resolveForm(string $uri): Form|array
    {
        $handle = substr($uri, strlen($this->scheme() . '://'));
        if ($handle === '') {
            return ['isError' => true, 'error' => 'Missing form handle in URI: ' . $uri];
        }

        $form = Form::find()->siteId('*')->status(null)->handle($handle)->one();
        if (!$form instanceof Form) {
            return ['isError' => true, 'error' => 'Form not found: ' . $handle];
        }

        return $form;
    }

    /**
     * Wrap a JSON-encodable payload in the MCP {@code contents} envelope (a
     * single {@code {uri, mimeType, text}} block), identical for every provider.
     *
     * @param array<string, mixed> $payload
     * @return McpResourceContents
     */
    protected function contents(string $uri, array $payload): array
    {
        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => $this->mimeType(),
                'text' => (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
        ];
    }

    /**
     * The URI scheme this provider serves (without {@code ://}).
     */
    abstract protected function scheme(): string;

    /**
     * The MIME type of this provider's resource contents.
     */
    abstract protected function mimeType(): string;

    /**
     * The {@code resources/list} descriptor for one form (uri, name, title,
     * description, mimeType).
     *
     * @return array<string, mixed>
     */
    abstract protected function describe(Form $form): array;

    /**
     * The JSON-encodable body returned by {@see self::read()} for one form.
     *
     * @return array<string, mixed>
     */
    abstract protected function payload(Form $form): array;
}
