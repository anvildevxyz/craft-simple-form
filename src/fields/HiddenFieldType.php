<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use craft\elements\User;
use fabianhaef\simpleform\helpers\HiddenValueResolver;

/**
 * Hidden field (#124). A non-visible field whose value is populated at render
 * time from a configurable source — a static default, a URL query param, the
 * logged-in user's email/id/username, or a cookie — and captured into the
 * submission for tracking/attribution.
 *
 * The field carries a translatable label used only as the export/CP column
 * label; it is never rendered to the visitor. The rendered control is a bare
 * `<input type="hidden">` with no `<label>` or wrapper.
 *
 * Security: for the `user` source the value MUST be re-resolved server-side at
 * submit time from the authenticated identity (see {@see self::resolveForSubmit()})
 * so a forged hidden value cannot impersonate another user. `static`/`query`/
 * `cookie` values are inherently client-influenced and pass through sanitized.
 *
 * Config keys:
 *  - source:        'static' | 'query' | 'user' | 'cookie' (default 'static')
 *  - default:       fallback when the source yields nothing
 *  - queryParam:    request query param name (source = query)
 *  - userAttribute: 'email' | 'id' | 'username' (source = user)
 *  - cookieName:    cookie name (source = cookie)
 *  - maxLength:     optional sanity bound (default 255)
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class HiddenFieldType extends FieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'hidden';
    }

    public static function getLabel(): string
    {
        return 'Hidden';
    }

    /**
     * Hidden fields are non-visible, so they never participate in the standard
     * labelled field group — the front-end template emits the bare input only.
     * They still collect a stored value, so {@see FieldType::isInput()} stays
     * true and the value flows through validation, storage, and export.
     */
    public function rendersInGroup(): bool
    {
        return false;
    }

    /**
     * Validation is permissive: a Hidden field is never visitor-`required`, and
     * an empty value (e.g. a guest with no default) is valid. Only the
     * `maxLength` bound is enforced as a light tamper signal; the value is
     * already clamped on resolve, so this rejects only an oversized forged POST.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        if (HiddenValueResolver::withinMaxLength($value, $this->config)) {
            return [];
        }

        return [Craft::t('simple-form', 'Must be no more than {max} characters.', [
            'max' => HiddenValueResolver::maxLength($this->config),
        ])];
    }

    /**
     * Render the bare hidden input. The value is resolved at render time from
     * the configured source (the passed-in $value, e.g. a resume prefill, wins
     * when present) and HTML-escaped into the attribute.
     */
    public function renderInput(string $name, mixed $value = null): string
    {
        $resolved = $value !== null && $value !== ''
            ? HiddenValueResolver::sanitize($value, $this->config)
            : $this->resolveForRender();

        return sprintf(
            '<input type="hidden" id="%s" name="%s" value="%s">',
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($resolved, ENT_QUOTES),
        );
    }

    /**
     * Compute the render-time value by pulling the live request/user/cookie
     * inputs and delegating to the pure resolver.
     */
    public function resolveForRender(): string
    {
        $source = (string) ($this->config['source'] ?? HiddenValueResolver::SOURCE_STATIC);

        if ($source === HiddenValueResolver::SOURCE_USER) {
            return HiddenValueResolver::resolveUser($this->config, $this->currentUserAttributes());
        }

        // The static source is request-independent — resolve it without touching
        // the request so the bare-input render works in any context.
        if ($source === HiddenValueResolver::SOURCE_STATIC) {
            return HiddenValueResolver::resolveClientSource($this->config, [], []);
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $queryParams = $request->getIsConsoleRequest() ? [] : $request->getQueryParams();
        $cookies = $this->requestCookies();

        return HiddenValueResolver::resolveClientSource($this->config, $queryParams, $cookies);
    }

    /**
     * Resolve the value to persist at submit time.
     *
     * For the `user` source the posted value is ignored and the attribute is
     * re-resolved from the authenticated user passed in $context (`userId`),
     * defeating a spoofed hidden input. For `static`/`query`/`cookie` the posted
     * value is accepted but sanitized to bounded plain text.
     *
     * @param array{userId?: ?int} $context
     */
    public function resolveForSubmit(mixed $posted, array $context = []): string
    {
        $source = (string) ($this->config['source'] ?? HiddenValueResolver::SOURCE_STATIC);

        if ($source === HiddenValueResolver::SOURCE_USER) {
            return HiddenValueResolver::resolveUser($this->config, $this->userAttributesById($context['userId'] ?? null));
        }

        return HiddenValueResolver::sanitize($posted, $this->config);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The current request's cookie values, keyed by name. Empty in console
     * contexts where no web request exists.
     *
     * @return array<string, string>
     */
    private function requestCookies(): array
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request) {
            return [];
        }

        $values = [];
        foreach ($request->getCookies() as $name => $cookie) {
            $values[(string) $name] = (string) $cookie->value;
        }

        return $values;
    }

    /**
     * The currently authenticated user's safe attributes, or null for a guest.
     *
     * @return array{email: ?string, id: int|null, username: ?string}|null
     */
    private function currentUserAttributes(): ?array
    {
        $identity = Craft::$app->getUser()->getIdentity();

        return $identity instanceof User ? $this->attributesFor($identity) : null;
    }

    /**
     * Resolve a user by id to their safe attributes, or null when absent.
     *
     * @return array{email: ?string, id: int|null, username: ?string}|null
     */
    private function userAttributesById(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById($userId);

        return $user instanceof User ? $this->attributesFor($user) : null;
    }

    /**
     * Extract the three safe attributes from a user element.
     *
     * @return array{email: ?string, id: int|null, username: ?string}
     */
    private function attributesFor(User $user): array
    {
        return [
            'email' => $user->email,
            'id' => $user->id,
            'username' => $user->username,
        ];
    }
}
