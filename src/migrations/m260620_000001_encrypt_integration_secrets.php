<?php

namespace fabianhaef\simpleform\migrations;

use Craft;
use craft\db\Migration;
use fabianhaef\simpleform\Plugin;

/**
 * Backfill for encryption-at-rest of integration secrets (audit finding F4).
 *
 * Encryption was added on the write path, so integrations saved before the
 * upgrade still hold plaintext apiKey/apiToken/secret values in the database.
 * This migration re-encrypts them in place. It is data-only (no schema change)
 * and idempotent: already-encrypted values, env references and empty values are
 * left untouched, and rows are only rewritten when something actually changed.
 */
class m260620_000001_encrypt_integration_secrets extends Migration
{
    public function safeUp(): bool
    {
        // Without a securityKey the backfill can't encrypt anything, so it would
        // "succeed" while leaving plaintext secrets in place and never re-run.
        // Fail loudly instead — Craft requires a securityKey anyway, so this only
        // bites a genuinely misconfigured install, and the migration re-runs once
        // the key is set.
        if ((string) Craft::$app->getConfig()->getGeneral()->securityKey === '') {
            throw new \RuntimeException(
                'Cannot encrypt integration secrets without a securityKey. ' .
                'Set CRAFT_SECURITY_KEY (or the securityKey config) and re-run migrations.',
            );
        }

        $count = Plugin::getInstance()->getIntegrations()->encryptStoredSecrets();
        echo "    > encrypted secrets in {$count} integration(s)\n";

        return true;
    }

    public function safeDown(): bool
    {
        // Irreversible: we never decrypt secrets back to plaintext.
        echo "m260620_000001_encrypt_integration_secrets cannot be reverted.\n";
        return false;
    }
}
