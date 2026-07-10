<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Notification CC/BCC (#313): per-notification `cc` and `bcc` address lists on
 * `simpleform_notifications`, alongside the existing `recipient`/`replyTo`.
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260709_000001_add_notification_cc_bcc extends Migration
{
    public function safeUp(): bool
    {
        $notifications = '{{%simpleform_notifications}}';
        if (!$this->db->columnExists($notifications, 'cc')) {
            $this->addColumn($notifications, 'cc', $this->string(255)->after('replyTo'));
        }
        if (!$this->db->columnExists($notifications, 'bcc')) {
            $this->addColumn($notifications, 'bcc', $this->string(255)->after('cc'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $notifications = '{{%simpleform_notifications}}';
        if ($this->db->columnExists($notifications, 'bcc')) {
            $this->dropColumn($notifications, 'bcc');
        }
        if ($this->db->columnExists($notifications, 'cc')) {
            $this->dropColumn($notifications, 'cc');
        }

        return true;
    }
}
