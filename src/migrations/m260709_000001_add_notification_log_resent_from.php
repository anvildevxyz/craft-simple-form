<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Adds a `resentFromId` self-reference to the notification send log (#318) so a
 * manual resend writes a fresh, auditable row that points back at the original
 * send it was retried from.
 *
 * @author Fabian Haefliger
 * @since 2.14.0
 */
class m260709_000001_add_notification_log_resent_from extends Migration
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function safeUp(): bool
    {
        $table = '{{%simpleform_notification_logs}}';

        if (!$this->db->columnExists($table, 'resentFromId')) {
            $this->addColumn($table, 'resentFromId', $this->integer()->after('notificationName'));
            $this->createIndex(null, $table, ['resentFromId']);
            $this->addForeignKey(null, $table, ['resentFromId'], $table, ['id'], 'SET NULL', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%simpleform_notification_logs}}';

        if ($this->db->columnExists($table, 'resentFromId')) {
            $this->dropColumn($table, 'resentFromId');
        }

        return true;
    }
}
