<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_SETTINGS;

    /** Settings fields grouped by tab. Drives both rendering and the per-tab save. */
    private const TAB_FIELDS = [
        'general' => ['submitMessage', 'errorMessage', 'storageLocation'],
        'email' => ['defaultEmailSender', 'defaultEmailSenderName'],
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
        ],
        // The MCP tab persists only the enable toggle through the generic save;
        // tokens are created/revoked via dedicated actions (one-time secret).
        'mcp' => ['enableMcp'],
    ];

    private const BOOL_FIELDS = ['enableHoneypot', 'enableCaptcha', 'enableMcp'];
    private const FLOAT_FIELDS = ['recaptchaV3MinScore'];

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

        // Start from the existing values so saving one tab never wipes another
        // tab's fields (e.g. the required defaultEmailSender on the Email tab).
        $values = $settings->getAttributes();

        foreach (self::TAB_FIELDS[$tab] as $field) {
            if (in_array($field, self::BOOL_FIELDS, true)) {
                $values[$field] = (bool) $request->getBodyParam($field);
            } elseif (in_array($field, self::FLOAT_FIELDS, true)) {
                $values[$field] = (float) $request->getBodyParam($field, $values[$field] ?? 0.5);
            } else {
                $values[$field] = $request->getBodyParam($field, $values[$field] ?? null);
            }
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            $settings = $plugin->getSettings();
            $firstErrors = $settings->getFirstErrors();
            $error = $firstErrors
                ? reset($firstErrors)
                : Craft::t('simple-form', 'Couldn’t save settings.');
            Craft::$app->getSession()->setError($error);

            // Re-render the same tab with the invalid model so errors show inline.
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
                'selectedSettingsSubnavItem' => $tab,
            ]);
            return $this->renderTab($tab);
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Settings saved.'));
        return $this->redirect('simple-form/settings/' . $tab);
    }

    /**
     * Create a new MCP token. The plaintext secret is generated, the hash
     * persisted, and the secret flashed back to the operator exactly once via a
     * session notice — it is never stored or shown again.
     */
    public function actionCreateMcpToken(): Response
    {
        $this->requirePostRequest();
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

        $result = Plugin::getInstance()->getMcpTokenManager()->createToken($label, $scopes);

        // One-time display of the plaintext secret. Stored in the flash so the
        // redirected page can render it once, then it is gone.
        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Token created. Copy it now — it will not be shown again.'));
        Craft::$app->getSession()->setFlash('mcpNewSecret', $result['secret']);

        return $this->redirect('simple-form/settings/mcp');
    }

    /**
     * Revoke (delete) an MCP token by id.
     */
    public function actionRevokeMcpToken(): Response
    {
        $this->requirePostRequest();
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

    private function renderTab(string $tab): Response
    {
        $vars = [
            'settings' => Plugin::getInstance()->getSettings(),
            'selectedSettingsSubnavItem' => $tab,
        ];

        if ($tab === 'spam') {
            $vars['captchaProviders'] = Plugin::getInstance()->getCaptchaProviderRegistry()->all();
        }

        if ($tab === 'mcp') {
            $vars['mcpTokens'] = Plugin::getInstance()->getMcpTokenManager()->allTokens();
            $vars['mcpScopes'] = Scopes::all();
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
}
