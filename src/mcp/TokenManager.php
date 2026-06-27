<?php

namespace anvildev\simpleform\mcp;

use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use Craft;
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
 * @phpstan-import-type TokenArray from McpToken
 */
class TokenManager
{
    /** Bytes of cryptographic entropy in a generated secret (32 = 256 bits). */
    private const SECRET_BYTES = 32;

    /** Visible prefix so an operator can recognise a Simple Form MCP token. */
    private const SECRET_PREFIX = 'sfmcp_';

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    /**
     * Generate a new token, persist its hash + metadata, and return the
     * plaintext secret ONCE.
     *
     * The returned secret is the only time the plaintext exists; the caller
     * must surface it to the operator immediately and must not persist it. We
     * store the {@see McpToken} (hash only) into plugin settings.
     *
     * @param list<string> $scopes Requested scopes; unknown scopes are dropped.
     * @return array{token: McpToken, secret: string}
     */
    public function createToken(string $label, array $scopes): array
    {
        $scopes = array_values(array_filter(
            $scopes,
            static fn(string $s): bool => Scopes::isValid($s),
        ));

        // F12 (CWE-330): use a CSPRNG with full byte entropy. StringHelper::
        // randomString() draws from a 26-char lowercase alphabet (~150 bits at
        // 32 chars), well below the 256 bits this token is meant to carry.
        $secret = self::SECRET_PREFIX . bin2hex(random_bytes(self::SECRET_BYTES));

        $token = new McpToken(
            id: StringHelper::UUID(),
            label: $label !== '' ? $label : 'Unnamed token',
            hash: $this->hashSecret($secret),
            scopes: $scopes,
            dateCreated: (new \DateTime())->format(\DateTime::ATOM),
            lastUsed: null,
        );

        $tokens = $this->allTokens();
        $tokens[] = $token;
        $this->persist($tokens);

        return ['token' => $token, 'secret' => $secret];
    }

    /**
     * Revoke (delete) a token by its id. Returns true if a token was removed.
     */
    public function revokeToken(string $id): bool
    {
        $tokens = $this->allTokens();
        $remaining = array_values(array_filter(
            $tokens,
            static fn(McpToken $t): bool => $t->id !== $id,
        ));

        if (count($remaining) === count($tokens)) {
            return false;
        }

        $this->persist($remaining);
        return true;
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

        return $match;
    }

    /**
     * Record that a token was just used (best-effort; failures are swallowed so
     * a settings-write hiccup never blocks an otherwise valid request).
     */
    public function touch(McpToken $token): void
    {
        try {
            $tokens = $this->allTokens();
            foreach ($tokens as $t) {
                if ($t->id === $token->id) {
                    $t->lastUsed = (new \DateTime())->format(\DateTime::ATOM);
                }
            }
            $this->persist($tokens);
        } catch (\Throwable $e) {
            Craft::warning('Could not update MCP token lastUsed: ' . $e->getMessage(), 'simple-form');
        }
    }

    /**
     * All configured tokens (hash-only models).
     *
     * @return list<McpToken>
     */
    public function allTokens(): array
    {
        $raw = $this->settings()->mcpTokens;
        $tokens = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                /** @var TokenArray $entry */
                $tokens[] = McpToken::fromArray($entry);
            }
        }
        return $tokens;
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

    /**
     * Persist the full token list back into plugin settings.
     *
     * @param list<McpToken> $tokens
     */
    private function persist(array $tokens): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $values = $settings->getAttributes();
        $values['mcpTokens'] = array_map(static fn(McpToken $t): array => $t->toArray(), $tokens);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            throw new \RuntimeException('Could not persist MCP tokens: ' . implode(', ', $settings->getFirstErrors()));
        }
    }
}
