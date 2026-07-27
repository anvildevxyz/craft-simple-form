<?php

namespace anvildev\simpleform\mcp;

use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

/**
 * Generates, hashes, stores, and validates MCP bearer tokens.
 *
 * SECURITY MODEL
 * --------------
 * Tokens are random 256-bit secrets. We persist only a keyed hash, never the
 * plaintext:
 *
 *   hash = HMAC-SHA256(secret, appSecurityKey)
 *
 * Why HMAC-SHA256 with the app security key rather than
 * `Security::hashPassword()` (bcrypt)?
 *
 *   - These are high-entropy machine secrets (32 random bytes), not
 *     human-chosen passwords, so the slow, salted bcrypt KDF buys us nothing —
 *     there is no low-entropy guess space to defend against. Constant-time
 *     comparison against a fast keyed hash is sufficient and standard for API
 *     tokens (cf. Craft's own GraphQL access tokens).
 *   - bcrypt embeds a per-hash random salt, so the same secret hashes
 *     differently every time. That makes O(1) lookup of a presented bearer
 *     token impossible — we would have to bcrypt-verify against every stored
 *     token on every request, which is both slow and a timing oracle for the
 *     token *count*. A deterministic keyed hash lets us look the token up
 *     directly and compare in constant time.
 *   - Keying the hash with the app security key means a leak of project config
 *     alone (the stored hashes) is not enough to forge a token; the attacker
 *     also needs the security key from `.env`.
 *
 * All comparisons use {@see hash_equals()} (constant time). We never log,
 * echo, or store the plaintext after creation.
 *
 * Tokens live in a dedicated table ({@see self::TABLE}), never in plugin
 * settings / project config, so the keyed hashes don't sync into git or across
 * environments.
 */
class TokenManager
{
    /** Bytes of cryptographic entropy in a generated secret (32 = 256 bits). */
    private const SECRET_BYTES = 32;

    /** Visible prefix so an operator can recognise a Simple Form MCP token. */
    private const SECRET_PREFIX = 'sfmcp_';

    /** Dedicated storage — never plugin settings / project config. */
    private const TABLE = '{{%simpleform_mcp_tokens}}';

    /**
     * Generate a new token, persist its hash + metadata, and return the
     * plaintext secret ONCE.
     *
     * The returned secret is the only time the plaintext exists; the caller
     * must surface it to the operator immediately and must not persist it. We
     * store the {@see McpToken} (hash only) in the dedicated tokens table.
     *
     * @param list<string> $scopes Requested scopes; unknown scopes are dropped.
     * @param int|null $expiresInDays Optional lifetime in days; null (or <= 0) for
     *   a token that never expires.
     * @return array{token: McpToken, secret: string}
     */
    public function createToken(string $label, array $scopes, ?int $expiresInDays = null): array
    {
        $scopes = array_values(array_filter(
            $scopes,
            static fn(string $s): bool => Scopes::isValid($s),
        ));

        // F12 (CWE-330): use a CSPRNG with full byte entropy. StringHelper::
        // randomString() draws from a 26-char lowercase alphabet (~150 bits at
        // 32 chars), well below the 256 bits this token is meant to carry.
        $secret = self::SECRET_PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));

        $expiresAt = ($expiresInDays !== null && $expiresInDays > 0)
            ? (new \DateTime())->modify('+' . $expiresInDays . ' days')->format(\DateTime::ATOM)
            : null;

        $now = new \DateTime();
        $token = new McpToken(
            id: StringHelper::UUID(),
            label: $label !== '' ? $label : 'Unnamed token',
            hash: $this->hashSecret($secret),
            scopes: $scopes,
            dateCreated: $now->format(\DateTime::ATOM),
            lastUsed: null,
            expiresAt: $expiresAt,
        );

        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'tokenId' => $token->id,
            'label' => $token->label,
            'hash' => $token->hash,
            'scopes' => Json::encode($token->scopes),
            'expiresAt' => $expiresAt !== null ? Db::prepareDateForDb(new \DateTime($expiresAt)) : null,
            'dateCreated' => Db::prepareDateForDb($now),
            'dateUpdated' => Db::prepareDateForDb($now),
            'uid' => StringHelper::UUID(),
        ])->execute();

        return ['token' => $token, 'secret' => $secret];
    }

    /**
     * Revoke (delete) a token by its id. Returns true if a token was removed.
     */
    public function revokeToken(string $id): bool
    {
        $affected = Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['tokenId' => $id])
            ->execute();

        return $affected > 0;
    }

    /**
     * Resolve a presented bearer secret to its stored token, or null if no
     * token matches.
     *
     * SECURITY: the lookup compares the keyed hash of the presented secret
     * against every stored hash using {@see hash_equals()}, and intentionally
     * iterates over ALL tokens (no early break on match) so the work — and
     * therefore the response time — does not vary with which token matched or
     * how many precede it. Callers must treat a null result as a generic
     * "invalid token" and never disclose whether the token was unknown,
     * malformed, or merely under-scoped.
     *
     * NOTE: this is the natural seam for rate-limiting / brute-force defence
     * (e.g. a per-IP attempt counter keyed in Craft's cache). It is deliberately
     * deferred; secrets are 256-bit random, so online
     * guessing is infeasible, but a future slice should add a throttle here.
     */
    public function validateSecret(?string $secret): ?McpToken
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        $presentedHash = $this->hashSecret($secret);

        $match = null;
        foreach ($this->allTokens() as $token) {
            // Constant-time compare against each stored hash. Do not break on
            // match to keep timing independent of token position/count.
            if (hash_equals($token->hash, $presentedHash)) {
                $match = $token;
            }
        }

        // Reject an expired token as if it did not match. The check is on the
        // single matched token (O(1)) and runs after the full-list scan, so it
        // adds no timing signal about the other tokens.
        if ($match !== null && $match->isExpired()) {
            return null;
        }

        return $match;
    }

    /**
     * Record that a token was just used (best-effort; failures are swallowed so
     * a settings-write hiccup never blocks an otherwise valid request).
     */
    public function touch(McpToken $token): void
    {
        try {
            $now = Db::prepareDateForDb(new \DateTime());
            Craft::$app->getDb()->createCommand()->update(
                self::TABLE,
                ['lastUsed' => $now, 'dateUpdated' => $now],
                ['tokenId' => $token->id],
            )->execute();
        } catch (\Throwable $e) {
            Craft::warning('Could not update MCP token lastUsed: ' . $e->getMessage(), 'simple-form');
        }
    }

    /**
     * All configured tokens (hash-only models), oldest first.
     *
     * @return list<McpToken>
     */
    public function allTokens(): array
    {
        $rows = (new Query())
            ->select(['tokenId', 'label', 'hash', 'scopes', 'dateCreated', 'lastUsed', 'expiresAt'])
            ->from(self::TABLE)
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map($this->rowToToken(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToToken(array $row): McpToken
    {
        $scopes = Json::decodeIfJson((string) ($row['scopes'] ?? '[]'));

        return new McpToken(
            id: (string) ($row['tokenId'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            hash: (string) ($row['hash'] ?? ''),
            scopes: is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            dateCreated: isset($row['dateCreated']) ? (string) $row['dateCreated'] : null,
            lastUsed: ($row['lastUsed'] ?? null) !== null ? (string) $row['lastUsed'] : null,
            expiresAt: ($row['expiresAt'] ?? null) !== null ? (string) $row['expiresAt'] : null,
        );
    }

    /**
     * Deterministic keyed hash of a secret. See the class docblock for the
     * rationale (HMAC-SHA256 keyed with the app security key).
     */
    private function hashSecret(string $secret): string
    {
        // F9 (CWE-321): require the real security key. The previous fallback to
        // Craft::$app->id used a guessable, public application id as the HMAC
        // key when securityKey was empty, which would let an attacker forge a
        // valid token hash. Fail closed instead — production always sets it.
        $key = (string) Craft::$app->getConfig()->getGeneral()->securityKey;
        if ($key === '') {
            throw new \RuntimeException('A securityKey must be configured in .env to use Simple Form MCP tokens.');
        }
        return hash_hmac('sha256', $secret, $key);
    }
}
