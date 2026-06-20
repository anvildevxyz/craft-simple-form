<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Submission PDF + uploaded-file attachments (#143): each notification can now
 * carry a rendered PDF of the submission and/or the submission's uploaded files
 * as email attachments. Two new boolean columns on the notifications table drive
 * the per-notification toggles; existing rows default to off so no current
 * notification changes behaviour.
 */
class m260621_000001_notification_attachments extends Migration
{
    private const TABLE = '{{%simpleform_notifications}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'attachPdf')) {
            $this->addColumn(self::TABLE, 'attachPdf', $this->boolean()->notNull()->defaultValue(false)->after('body'));
        }
        if (!$this->db->columnExists(self::TABLE, 'attachUploads')) {
            $this->addColumn(self::TABLE, 'attachUploads', $this->boolean()->notNull()->defaultValue(false)->after('attachPdf'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'attachUploads')) {
            $this->dropColumn(self::TABLE, 'attachUploads');
        }
        if ($this->db->columnExists(self::TABLE, 'attachPdf')) {
            $this->dropColumn(self::TABLE, 'attachPdf');
        }

        return true;
    }
}
