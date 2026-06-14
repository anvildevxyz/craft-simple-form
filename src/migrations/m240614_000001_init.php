<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

class m240614_000001_init extends Migration
{
    public function safeUp(): bool
    {
        // Forms table
        $this->createTable('{{%simpleform_forms}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(255)->notNull(),
            'title' => $this->string(255),
            'description' => $this->text(),
            'emailTo' => $this->string(255),
            'emailSubject' => $this->string(255),
            'emailReplyTo' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_forms}}', ['handle', 'siteId'], true);
        $this->addForeignKey(null, '{{%simpleform_forms}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');

        // Fields table
        $this->createTable('{{%simpleform_fields}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'type' => $this->string(50)->notNull(),
            'name' => $this->string(255)->notNull(),
            'label' => $this->string(255),
            'helpText' => $this->text(),
            'config' => $this->json(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_fields}}', ['formId']);
        $this->addForeignKey(null, '{{%simpleform_fields}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');

        // Submissions table
        $this->createTable('{{%simpleform_submissions}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'data' => $this->json(),
            'userId' => $this->integer(),
            'readStatus' => $this->enum('readStatus', ['new', 'read', 'archived'])->defaultValue('new'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_submissions}}', ['formId']);
        $this->createIndex(null, '{{%simpleform_submissions}}', ['siteId']);
        $this->addForeignKey(null, '{{%simpleform_submissions}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_submissions}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_submissions}}', ['userId'], '{{%users}}', ['id'], 'SET NULL', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%simpleform_submissions}}');
        $this->dropTableIfExists('{{%simpleform_fields}}');
        $this->dropTableIfExists('{{%simpleform_forms}}');

        return true;
    }
}
