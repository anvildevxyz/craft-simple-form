<?php

namespace anvildev\simpleform\migrations;

use anvildev\simpleform\mcp\McpToken;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

/**
 * Move MCP access tokens out of plugin settings (project config) into their own
 * table (security review L3). The stored value is a keyed hash, not the secret,
 * but keeping it in project config still syncs it into git and across
 * environments; a dedicated table keeps it in the database only.
 *
 * Existing tokens are copied into the new table and then cleared from settings,
 * so no token hash is left in project config.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260724_000001_add_mcp_tokens_table extends Migration
{
    private const TABLE = '{{%simpleform_mcp_tokens}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            $this->createTable(self::TABLE, [
                'id' => $this->primaryKey(),
                'tokenId' => $this->char(36)->notNull(),
                'label' => $this->string()->notNull(),
                'hash' => $this->string(128)->notNull(),
                'scopes' => $this->text()->notNull(),
                'lastUsed' => $this->dateTime(),
                'expiresAt' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TABLE, ['tokenId'], true);
            $this->createIndex(null, self::TABLE, ['hash']);
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        /** @var list<array<string, mixed>> $legacy */
        $legacy = array_values(array_filter($settings->mcpTokens, 'is_array'));

        foreach ($legacy as $entry) {
            $token = McpToken::fromArray($entry);
            if ($token->hash === '') {
                continue;
            }
            $now = Db::prepareDateForDb(new \DateTime());
            $this->insert(self::TABLE, [
                'tokenId' => $token->id !== '' ? $token->id : StringHelper::UUID(),
                'label' => $token->label !== '' ? $token->label : 'Unnamed token',
                'hash' => $token->hash,
                'scopes' => Json::encode($token->scopes),
                'lastUsed' => $this->toDbDate($token->lastUsed),
                'expiresAt' => $this->toDbDate($token->expiresAt),
                'dateCreated' => $this->toDbDate($token->dateCreated) ?? $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        // Clear the hashes from settings (project config), where they no longer
        // belong. A read-only project config can't be written here — surface a
        // note so the operator removes the key by hand.
        if ($legacy !== []) {
            try {
                $values = $settings->getAttributes();
                $values['mcpTokens'] = [];
                Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
            } catch (\Throwable $e) {
                echo "    > migrated MCP tokens to a table but could not clear them from settings ({$e->getMessage()}); remove `mcpTokens` from project config manually.\n";
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }

    private function toDbDate(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return Db::prepareDateForDb(new \DateTime($iso));
        } catch (\Exception) {
            return null;
        }
    }
}
