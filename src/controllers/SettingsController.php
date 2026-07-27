<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\Editions;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * The plugin Settings screens: renders each settings tab and persists a per-tab
 * save without clobbering the other tabs' stored fields.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class SettingsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_SETTINGS;

    /** Settings fields grouped by tab. Drives both rendering and the per-tab save. */
    private const TAB_FIELDS = [
        'general' => ['submitMessage', 'errorMessage', 'storageLocation', 'uploadVolume', 'templatePath', 'addressAutocompleteProvider', 'addressAutocompleteEndpoint', 'addressAutocompleteApiKey'],
        'workflow' => ['enableWorkflow'],
        'email' => ['defaultEmailSender', 'defaultEmailSenderName', 'pdfStorageVolume', 'maxAttachmentSizeMb'],
        'spam' => [
            'enableHoneypot',
            'enableCaptcha',
            'selectedCaptchaProvider',
            'captchaType',
            'recaptchaV3MinScore',
            'recaptchaV3SiteKey',
            'recaptchaV3SecretKey',
            'recaptchaV2SiteKey',
            'recaptchaV2SecretKey',
            'turnstileSiteKey',
            'turnstileSecretKey',
            'hcaptchaSiteKey',
            'hcaptchaSecretKey',
            'enableAkismet',
            'akismetApiKey',
            'akismetMode',
            'enableDenylists',
            'denylistMode',
            'blockedKeywords',
            'blockedEmails',
            'blockedIps',
            'duplicateMode',
            'submitRateLimitPerMinute',
            'allowGraphqlCaptchaBypass',
        ],
        'privacy' => ['ipCapturePolicy', 'retainSubmissionsDays', 'retainIntegrationLogsDays', 'retainNotificationLogsDays', 'retainAuditLogDays', 'anonymizeInsteadOfDelete', 'partialRetentionDays'],
        // The MCP tab persists only the enable toggle through the generic save;
        // tokens are created/revoked via dedicated actions (one-time secret).
        'mcp' => ['enableMcp'],
    ];

    /** Secret settings that should be env references, not literals (CWE-312). */
    private const SECRET_FIELDS = ['recaptchaV3SecretKey', 'recaptchaV2SecretKey', 'turnstileSecretKey', 'hcaptchaSecretKey', 'akismetApiKey'];

    private const BOOL_FIELDS = ['enableHoneypot', 'enableCaptcha', 'enableMcp', 'enableAkismet', 'enableDenylists', 'anonymizeInsteadOfDelete', 'allowGraphqlCaptchaBypass', 'enableWorkflow', 'collectIpAddresses'];
    private const FLOAT_FIELDS = ['recaptchaV3MinScore'];
    private const INT_FIELDS = ['retainSubmissionsDays', 'retainIntegrationLogsDays', 'retainNotificationLogsDays', 'retainAuditLogDays', 'partialRetentionDays', 'submitRateLimitPerMinute', 'maxAttachmentSizeMb'];

    public function actionIndex(): Response
    {
        return $this->renderTab('general');
    }

    public function actionSection(string $tab): Response
    {
        return $this->renderTab($this->normalizeTab($tab));
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $tab = $this->normalizeTab($request->getBodyParam('tab'));

        // The Spam Protection tab persists third-party secret keys (captcha
        // provider secrets, the Akismet API key). Re-verify the password so a
        // hijacked-but-authenticated CP session can't silently rewrite them and
        // disable spam protection (CWE-306). Elevation lasts for Craft's
        // elevated-session window, so this doesn't re-prompt on every tweak.
        if ($tab === 'spam') {
            $this->requireElevatedSession();
        }

        // Start from the existing values so saving one tab never wipes another
        // tab's fields (e.g. the required defaultEmailSender on the Email tab).
        $values = $settings->getAttributes();

        $bool = array_flip(self::BOOL_FIELDS);
        $float = array_flip(self::FLOAT_FIELDS);
        $int = array_flip(self::INT_FIELDS);
        // On Solo the Standard features' companion config is frozen (can't reconfigure a
        // still-running Standard feature); their "off switches" stay operable but only
        // accept off/unchanged (see below).
        $frozen = Editions::isStandard() ? [] : array_flip(Editions::STANDARD_CONFIG_SETTINGS);
        $blockedEnables = [];
        foreach (self::TAB_FIELDS[$tab] as $field) {
            $stored = $values[$field] ?? null;

            // Frozen Standard config on Solo: keep the stored value untouched.
            if (isset($frozen[$field])) {
                continue;
            }

            if (isset($bool[$field])) {
                $new = (bool) $request->getBodyParam($field);
            } elseif (isset($float[$field])) {
                $new = (float) $request->getBodyParam($field, $stored ?? 0.5);
            } elseif (isset($int[$field])) {
                $new = (int) $request->getBodyParam($field, $stored ?? 0);
            } else {
                $value = $request->getBodyParam($field, $stored);
                // F19: trim string settings (API keys, secrets, sender) so a
                // stray copy-paste space doesn't silently break verification.
                $new = is_string($value) ? trim($value) : $value;
            }

            // Authoring gate: Solo may turn a Standard feature off or leave it, but not
            // newly enable it or change a still-on value. Keeping the off-switch
            // operable means a downgraded site can still stop a running Standard feature
            // (the runtime itself stays edition-blind).
            if (Editions::blocksStandardSettingChange($field, $stored, $new)) {
                $blockedEnables[] = $field;
                continue;
            }

            $values[$field] = $new;
        }

        // Keep the legacy boolean in lockstep with the three-state IP policy so
        // pre-#315 readers of collectIpAddresses stay correct (#315). init()
        // does the same on load, but savePluginSettings() writes the raw values
        // array, so the derivation has to happen here on the save path too.
        if ($tab === 'privacy' && isset($values['ipCapturePolicy'])) {
            $values['collectIpAddresses'] = $values['ipCapturePolicy'] !== Settings::IP_CAPTURE_OFF;
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            $settings = $plugin->getSettings();
            Craft::$app->getSession()->setError(
                $this->saveErrorSummary($settings->getFirstErrors(), $tab),
            );

            // Re-render the same tab with the invalid model so errors show inline.
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
                'selectedSettingsSubnavItem' => $tab,
            ]);
            return $this->renderTab($tab);
        }

        if ($blockedEnables !== []) {
            $labels = array_values(array_unique(array_map(
                fn(string $field): string => $this->settingLabel($field),
                $blockedEnables,
            )));
            Craft::$app->getSession()->setError(Craft::t(
                'simple-form',
                'Settings saved. These are Standard-only, so the changes that would enable or expand them were left unchanged: {features}',
                ['features' => implode(', ', $labels)],
            ));
        } elseif ($tab === 'spam' && $this->hasLiteralSecret($values)) {
            // Non-blocking nudge: a literal secret is stored plaintext in the DB
            // and (commonly git-committed) project config. Env references keep it
            // out of both (CWE-312). Save still succeeds.
            Craft::$app->getSession()->setNotice(Craft::t(
                'simple-form',
                'Settings saved. Tip: store CAPTCHA/Akismet secrets as environment references (e.g. $RECAPTCHA_SECRET) so the literal value is kept out of the database and project config.',
            ));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Settings saved.'));
        }
        return $this->redirect('simple-form/settings/' . $tab);
    }

    /**
     * Whether any secret setting in the saved values holds a literal (non-empty,
     * non-env-reference) value — i.e. a raw secret that will sit plaintext in the
     * DB and project config rather than being resolved from the environment.
     *
     * @param array<string, mixed> $values
     */
    private function hasLiteralSecret(array $values): bool
    {
        foreach (self::SECRET_FIELDS as $field) {
            $value = trim((string) ($values[$field] ?? ''));
            if ($value !== '' && !str_starts_with($value, '$')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a new MCP token. The plaintext secret is generated, the hash
     * persisted, and the secret flashed back to the operator exactly once via a
     * session notice — it is never stored or shown again.
     *
     * Restricted to admins with an elevated session: an MCP token grants
     * out-of-band API access to submission data whose scopes are enforced
     * independently of the minter's CP permissions, so a non-admin holding only
     * `manageSettings` must not be able to mint one and read/export submissions
     * they lack `viewSubmissions` for (CWE-269). Mirrors Craft's admin-only
     * treatment of GraphQL tokens.
     *
     * @throws \yii\web\ForbiddenHttpException if the user is not an admin or the
     *   session is not elevated
     */
    public function actionCreateMcpToken(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        $this->requireElevatedSession();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $label = trim((string) $request->getBodyParam('label', ''));
        /** @var list<string> $scopes */
        $scopes = array_values(array_filter(
            (array) $request->getBodyParam('scopes', []),
            static fn($s): bool => is_string($s),
        ));

        if ($scopes === []) {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Select at least one scope for the token.'));
            return $this->redirect('simple-form/settings/mcp');
        }

        $expiresInDays = (int) $request->getBodyParam('expiresInDays', 0);
        $expiresInDays = $expiresInDays > 0 ? $expiresInDays : null;

        $result = Plugin::getInstance()->getMcpTokenManager()->createToken($label, $scopes, $expiresInDays);

        // One-time display of the plaintext secret. Stored in the flash so the
        // redirected page can render it once, then it is gone.
        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Token created. Copy it now — it will not be shown again.'));
        Craft::$app->getSession()->setFlash('mcpNewSecret', $result['secret']);

        return $this->redirect('simple-form/settings/mcp');
    }

    /**
     * Revoke (delete) an MCP token by id. Admin-only, matching token creation.
     *
     * @throws \yii\web\ForbiddenHttpException if the user is not an admin
     */
    public function actionRevokeMcpToken(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $id = (string) $request->getBodyParam('id', '');

        if ($id !== '' && Plugin::getInstance()->getMcpTokenManager()->revokeToken($id)) {
            Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Token revoked.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Token not found.'));
        }

        return $this->redirect('simple-form/settings/mcp');
    }

    /**
     * Append a workflow stage (#248). Handle is slugified + de-duplicated; the
     * first stage added becomes the one new submissions enter.
     */
    public function actionAddWorkflowStatus(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $label = trim((string) $request->getBodyParam('label', ''));
        $handle = StringHelper::toCamelCase((string) ($request->getBodyParam('handle') ?: $label));
        $color = trim((string) $request->getBodyParam('color', 'blue')) ?: 'blue';

        if ($handle === '' || $label === '') {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'A stage needs a name.'));
            return $this->redirect('simple-form/settings/workflow');
        }

        $statuses = Plugin::getInstance()->getWorkflow()->getStatuses();
        foreach ($statuses as $s) {
            if ($s['handle'] === $handle) {
                Craft::$app->getSession()->setError(Craft::t('simple-form', 'A stage with that handle already exists.'));
                return $this->redirect('simple-form/settings/workflow');
            }
        }
        $statuses[] = ['handle' => $handle, 'label' => $label, 'color' => $color];

        return $this->saveWorkflow(['workflowStatuses' => $statuses]);
    }

    /**
     * Remove a workflow stage by handle, plus any transitions referencing it.
     */
    public function actionDeleteWorkflowStatus(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $handle = (string) $request->getRequiredBodyParam('handle');

        $workflow = Plugin::getInstance()->getWorkflow();
        $statuses = array_values(array_filter(
            $workflow->getStatuses(),
            static fn(array $s): bool => $s['handle'] !== $handle,
        ));
        $transitions = array_values(array_filter(
            $workflow->getTransitions(),
            static fn(array $t): bool => $t['from'] !== $handle && $t['to'] !== $handle,
        ));

        return $this->saveWorkflow(['workflowStatuses' => $statuses, 'workflowTransitions' => $transitions]);
    }

    /**
     * Append an allowed transition between two stages (#248), optionally gated to
     * specific user groups.
     */
    public function actionAddWorkflowTransition(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $from = (string) $request->getBodyParam('from', '');
        $to = (string) $request->getBodyParam('to', '');
        $label = trim((string) $request->getBodyParam('label', ''));
        /** @var list<string> $groups */
        $groups = array_values(array_filter(
            (array) $request->getBodyParam('groups', []),
            static fn($g): bool => is_string($g) && $g !== '',
        ));

        if ($from === '' || $to === '' || $from === $to) {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Pick two different stages.'));
            return $this->redirect('simple-form/settings/workflow');
        }

        $transitions = Plugin::getInstance()->getWorkflow()->getTransitions();
        $transitions[] = ['from' => $from, 'to' => $to, 'label' => $label ?: $to, 'groups' => $groups];

        return $this->saveWorkflow(['workflowTransitions' => $transitions]);
    }

    /**
     * Remove a transition, identified by its from/to/label rather than an ordinal
     * index, so a concurrent edit between render and submit can't delete the wrong
     * row. Removes the first exact match.
     */
    public function actionDeleteWorkflowTransition(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $from = (string) $request->getRequiredBodyParam('from');
        $to = (string) $request->getRequiredBodyParam('to');
        $label = (string) $request->getBodyParam('label', '');

        $removed = false;
        $transitions = array_values(array_filter(
            Plugin::getInstance()->getWorkflow()->getTransitions(),
            static function(array $t) use ($from, $to, $label, &$removed): bool {
                if (!$removed && $t['from'] === $from && $t['to'] === $to && $t['label'] === $label) {
                    $removed = true;
                    return false;
                }
                return true;
            },
        ));

        return $this->saveWorkflow(['workflowTransitions' => $transitions]);
    }

    /**
     * Persist a partial workflow-settings change, preserving every other setting,
     * then return to the Workflow tab.
     *
     * @param array<string, mixed> $changes
     */
    private function saveWorkflow(array $changes): Response
    {
        $plugin = Plugin::getInstance();
        $values = array_merge($plugin->getSettings()->getAttributes(), $changes);

        if (Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Workflow updated.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t update the workflow.'));
        }

        return $this->redirect('simple-form/settings/workflow');
    }

    private function renderTab(string $tab): Response
    {
        $vars = [
            'settings' => Plugin::getInstance()->getSettings(),
            'selectedSettingsSubnavItem' => $tab,
            // Standard companion-config fields the templates must render read-only on
            // Solo (empty on Standard). Single source: Editions::STANDARD_CONFIG_SETTINGS.
            'proLockedFields' => Editions::isStandard() ? [] : Editions::STANDARD_CONFIG_SETTINGS,
        ];

        if ($tab === 'spam') {
            $vars['captchaProviders'] = Plugin::getInstance()->getCaptchaProviderRegistry()->all();
        }

        if ($tab === 'workflow') {
            $workflow = Plugin::getInstance()->getWorkflow();
            $vars['workflowStatuses'] = $workflow->getStatuses();
            $vars['workflowTransitions'] = $workflow->getTransitions();
            $vars['userGroups'] = Craft::$app->getUserGroups()->getAllGroups();
        }

        if ($tab === 'mcp') {
            $vars['mcpTokens'] = Plugin::getInstance()->getMcpTokenManager()->allTokens();
            $scopes = Scopes::all();
            $vars['mcpScopes'] = $scopes;
            // Single source of truth for scope labels (was duplicated + stale in
            // an inline template macro and the translation catalog).
            $vars['mcpScopeLabels'] = array_combine($scopes, array_map(Scopes::label(...), $scopes));
            // Plaintext secret is only ever surfaced once, immediately after
            // creation, via the flash set in actionCreateMcpToken().
            $vars['mcpNewSecret'] = Craft::$app->getSession()->getFlash('mcpNewSecret');
        }

        return $this->renderTemplate('simple-form/settings/index', $vars);
    }

    private function normalizeTab(string|int|float|bool|null $raw): string
    {
        $tab = strtolower(trim((string) $raw));
        return isset(self::TAB_FIELDS[$tab]) ? $tab : 'general';
    }

    /**
     * Human-readable label for a gated Standard setting, for the blocked-on-Solo flash
     * message (so it never leaks raw camelCase handles). Modes map to their
     * feature's name since that's what the operator recognises.
     */
    private function settingLabel(string $field): string
    {
        return match ($field) {
            'enableAkismet', 'akismetMode' => Craft::t('simple-form', 'Akismet'),
            'enableDenylists', 'denylistMode' => Craft::t('simple-form', 'Denylists'),
            'retainSubmissionsDays' => Craft::t('simple-form', 'Automatic submission deletion'),
            'retainAuditLogDays' => Craft::t('simple-form', 'Audit log retention'),
            default => $field,
        };
    }

    /**
     * Flash summary for a failed save. Settings validate as a whole model, so a
     * save on one tab can fail on a field the author can't see (#280) — each
     * error from another tab is prefixed with that tab's name so the author
     * knows where to go, and every first-error is included (not just the first).
     *
     * @param array<string, string> $firstErrors attribute => first error message
     */
    private function saveErrorSummary(array $firstErrors, string $currentTab): string
    {
        if ($firstErrors === []) {
            return Craft::t('simple-form', 'Couldn’t save settings.');
        }

        $parts = [];
        foreach ($firstErrors as $attribute => $error) {
            $tab = $this->tabForField($attribute);
            $parts[] = ($tab !== null && $tab !== $currentTab)
                ? Craft::t('simple-form', '{error} (on the {tab} tab)', [
                    'error' => rtrim($error, '.'),
                    'tab' => $this->tabLabel($tab),
                ])
                : $error;
        }

        return implode(' ', $parts);
    }

    /** The settings tab a model attribute is edited on, if any. */
    private function tabForField(string $attribute): ?string
    {
        foreach (self::TAB_FIELDS as $tab => $fields) {
            if (in_array($attribute, $fields, true)) {
                return $tab;
            }
        }
        return null;
    }

    /** Human-readable tab name, matching the labels in settings/index.twig. */
    private function tabLabel(string $tab): string
    {
        return match ($tab) {
            'general' => Craft::t('simple-form', 'General'),
            'email' => Craft::t('simple-form', 'Email'),
            'spam' => Craft::t('simple-form', 'Spam Protection'),
            'privacy' => Craft::t('simple-form', 'Privacy'),
            'workflow' => Craft::t('simple-form', 'Workflow'),
            'mcp' => Craft::t('simple-form', 'MCP Server'),
            default => $tab,
        };
    }
}
