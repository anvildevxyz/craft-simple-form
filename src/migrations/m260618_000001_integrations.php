<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Outbound integrations framework (#76): per-form integration configs and a
 * per-attempt dispatch log.
 */
class m260618_000001_integrations extends Migration
{
    public function safeUp(): bool
    {
        // One row per configured integration instance on a form.
        $this->createTable('{{%simpleform_integrations}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'type' => $this->string(50)->notNull(),
            'name' => $this->string(255)->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'settings' => $this->json(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_integrations}}', ['formId']);
        // Deleting a form removes its integrations.
        $this->addForeignKey(null, '{{%simpleform_integrations}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');

        // One row per dispatch attempt.
        $this->createTable('{{%simpleform_integration_logs}}', [
            'id' => $this->primaryKey(),
            'integrationId' => $this->integer()->notNull(),
            'submissionId' => $this->integer(),
            'status' => $this->enum('status', ['pending', 'success', 'failed'])->notNull()->defaultValue('pending'),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'responseCode' => $this->integer(),
            'message' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_integration_logs}}', ['integrationId']);
        $this->createIndex(null, '{{%simpleform_integration_logs}}', ['submissionId']);
        // Deleting an integration removes its logs; deleting a submission keeps the
        // log but nulls the reference (logs are diagnostic history).
        $this->addForeignKey(null, '{{%simpleform_integration_logs}}', ['integrationId'], '{{%simpleform_integrations}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_integration_logs}}', ['submissionId'], '{{%simpleform_submissions}}', ['id'], 'SET NULL', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        // Drop children before parents.
        $this->dropTableIfExists('{{%simpleform_integration_logs}}');
        $this->dropTableIfExists('{{%simpleform_integrations}}');

        return true;
    }
}
