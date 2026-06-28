<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Outbound notification send log — records every email Simple Form dispatches
 * for a submission so operators can review delivery history in the CP.
 *
 * @author Fabian Haefliger
 */
class m260628_000002_add_notification_logs extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%simpleform_notification_logs}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'submissionId' => $this->integer(),
            'notificationId' => $this->integer(),
            'notificationName' => $this->string(255),
            'status' => $this->enum('status', ['success', 'failed'])->notNull()->defaultValue('success'),
            'recipients' => $this->text(),
            'subject' => $this->string(255),
            'message' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%simpleform_notification_logs}}', ['formId']);
        $this->createIndex(null, '{{%simpleform_notification_logs}}', ['submissionId']);
        $this->createIndex(null, '{{%simpleform_notification_logs}}', ['dateCreated']);
        $this->addForeignKey(null, '{{%simpleform_notification_logs}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_notification_logs}}', ['submissionId'], '{{%simpleform_submissions}}', ['id'], 'SET NULL', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_notification_logs}}', ['notificationId'], '{{%simpleform_notifications}}', ['id'], 'SET NULL', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%simpleform_notification_logs}}');

        return true;
    }
}
