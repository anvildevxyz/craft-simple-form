<?php

namespace anvildev\simpleform\mcp;

/**
 * A single MCP access token as persisted in plugin settings.
 *
 * SECURITY: the secret is NEVER stored here. Only its hash (`hash`) is
 * persisted; the plaintext is shown to the operator exactly once, at creation
 * time, and then discarded. This object therefore safely round-trips through
 * project config / the settings store without leaking the bearer secret.
 *
 * Identity for auditing is the opaque `id` + operator-chosen `label`. Logs
 * reference these, never the secret (see {@see McpServer}).
 *
 * @phpstan-type TokenArray array{id?:string,label?:string,hash?:string,scopes?:list<string>,dateCreated?:?string,lastUsed?:?string,expiresAt?:?string}
 */
final class McpToken
{
    /**
     * @param string $id          Opaque, stable identifier used in logs/UI.
     * @param string $label       Operator-chosen human label (e.g. "Claude desktop").
     * @param string $hash        Hash of the secret (see {@see TokenManager}). Never the secret itself.
     * @param list<string> $scopes Granted capability scopes (see {@see Scopes}).
     * @param string|null $dateCreated ISO-8601 creation timestamp.
     * @param string|null $lastUsed    ISO-8601 timestamp of the last authenticated use.
     * @param string|null $expiresAt   ISO-8601 expiry, or null for a token that never expires.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $hash,
        public array $scopes,
        public ?string $dateCreated = null,
        public ?string $lastUsed = null,
        public ?string $expiresAt = null,
    ) {
    }

    /**
     * Whether this token's scope set contains the given scope. Deny-by-default:
     * a scope absent from the set is not granted.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /**
     * Whether the token's expiry has passed. A null expiry never expires; an
     * unparseable expiry is treated as expired (fail closed) rather than granting
     * an unbounded token.
     */
    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        try {
            $expires = new \DateTimeImmutable($this->expiresAt);
        } catch (\Exception) {
            return true;
        }

        return ($now ?? new \DateTimeImmutable()) >= $expires;
    }

    /**
     * @param TokenArray $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)($data['id'] ?? ''),
            label: (string)($data['label'] ?? ''),
            hash: (string)($data['hash'] ?? ''),
            scopes: array_values(array_filter(
                (array)($data['scopes'] ?? []),
                static fn($s): bool => is_string($s),
            )),
            dateCreated: isset($data['dateCreated']) ? (string)$data['dateCreated'] : null,
            lastUsed: isset($data['lastUsed']) ? (string)$data['lastUsed'] : null,
            expiresAt: isset($data['expiresAt']) ? (string)$data['expiresAt'] : null,
        );
    }

    /**
     * @return TokenArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'hash' => $this->hash,
            'scopes' => array_values($this->scopes),
            'dateCreated' => $this->dateCreated,
            'lastUsed' => $this->lastUsed,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
