<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Plugin;
use craft\db\Query;
use SmokeTester;

/**
 * Integration secrets are encrypted at rest, and the edit screen never echoes a
 * stored literal secret back into an input — a blanked field left untouched on
 * save keeps its value instead of wiping it, while environment references stay
 * visible so they can be edited (#429).
 *
 * @author Anvil Dev
 * @since 2.17.0
 */
class IntegrationSecretDisplayCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * A literal secret is stored encrypted, decrypts for use, but is blanked by
     * the display redaction so it is never re-shown on the edit form.
     */
    public function testLiteralSecretIsEncryptedAndBlankedForDisplay(SmokeTester $I): void
    {
        $svc = Plugin::getInstance()->getIntegrations();
        $secret = 'literal-shhh-' . uniqid();
        $model = $this->createIntegration('webhook', 'SecretTest ' . uniqid(), [
            'url' => 'https://example.test/hook',
            'secret' => $secret,
        ]);

        // At rest: encrypted, never the literal.
        $raw = (string) (new Query())
            ->select(['settings'])->from('{{%simpleform_integrations}}')
            ->where(['id' => $model->id])->scalar();
        $I->assertStringContainsString('sfenc:', $raw, 'the secret is stored encrypted');
        $I->assertStringNotContainsString($secret, $raw, 'the literal secret is not stored in cleartext');

        // On read: decrypted so the integration can dispatch.
        $reloaded = $svc->getIntegrationById((int) $model->id);
        $I->assertNotNull($reloaded);
        $I->assertSame($secret, $reloaded->settings['secret'], 'the secret decrypts for use');

        // For display: the literal is blanked; non-secret settings untouched.
        $display = $svc->redactSecretsForDisplay($reloaded->settings);
        $I->assertSame('', $display['secret'], 'the stored literal secret is blanked for the edit form');
        $I->assertSame('https://example.test/hook', $display['url'], 'non-secret settings are left as-is');
    }

    /**
     * An environment reference is safe and editable, so it is kept visible.
     */
    public function testEnvReferenceSecretStaysVisible(SmokeTester $I): void
    {
        $display = Plugin::getInstance()->getIntegrations()
            ->redactSecretsForDisplay(['secret' => '$WEBHOOK_SECRET', 'url' => 'https://x.test']);

        $I->assertSame('$WEBHOOK_SECRET', $display['secret'], 'env references are kept so they can be edited');
    }

    /**
     * Keep-on-blank: a blanked secret field keeps its stored value; a re-entered
     * one replaces it.
     */
    public function testBlankSecretOnSaveKeepsStoredValue(SmokeTester $I): void
    {
        $svc = Plugin::getInstance()->getIntegrations();

        $kept = $svc->preserveBlankSecrets(
            ['secret' => '', 'url' => 'https://new.test'],
            ['secret' => 'kept-secret', 'url' => 'https://old.test'],
        );
        $I->assertSame('kept-secret', $kept['secret'], 'a blanked secret keeps its stored value');
        $I->assertSame('https://new.test', $kept['url'], 'non-secret fields take the posted value');

        $replaced = $svc->preserveBlankSecrets(['secret' => 'new-secret'], ['secret' => 'old-secret']);
        $I->assertSame('new-secret', $replaced['secret'], 'a re-entered secret replaces the stored one');
    }

    /**
     * End-to-end: an edit that changes another field but leaves the masked secret
     * blank must not wipe the secret when re-saved.
     */
    public function testResavingWithBlankSecretDoesNotWipeIt(SmokeTester $I): void
    {
        $svc = Plugin::getInstance()->getIntegrations();
        $model = $this->createIntegration('webhook', 'KeepSecret ' . uniqid(), [
            'url' => 'https://k.test',
            'secret' => 'orig-secret-xyz',
        ]);

        $stored = $svc->getIntegrationById((int) $model->id)->settings;
        $model->settings = $svc->preserveBlankSecrets(['url' => 'https://k2.test', 'secret' => ''], $stored);
        $svc->saveIntegration($model);

        $after = $svc->getIntegrationById((int) $model->id)->settings;
        $I->assertSame('orig-secret-xyz', $after['secret'], 'the secret survives an edit that left it blank');
        $I->assertSame('https://k2.test', $after['url'], 'the changed URL is saved');
    }
}
