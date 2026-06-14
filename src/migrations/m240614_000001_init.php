<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

class m240614_000001_init extends Migration
{
    public function safeUp(): bool
    {
        // Forms table — SHARED data, keyed by the element id (one row per element, not per site)
        $this->createTable('{{%simpleform_forms}}', [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string(255)->notNull(),
            'name' => $this->string(255)->notNull(),
            'propagationMethod' => $this->string(50)->notNull()->defaultValue('none'),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addPrimaryKey(null, '{{%simpleform_forms}}', ['id']);
        // Handle is shared across sites now, so it is globally unique.
        $this->createIndex(null, '{{%simpleform_forms}}', ['handle'], true);
        // The plugin row IS the element; deleting the element cascades here.
        $this->addForeignKey(null, '{{%simpleform_forms}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', 'CASCADE');

        // Per-site form content — translatable columns, one row per (form, site)
        $this->createTable('{{%simpleform_forms_sites}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'description' => $this->text(),
            'emailTo' => $this->string(255),
            'emailSubject' => $this->string(255),
            'emailReplyTo' => $this->string(255),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_forms_sites}}', ['formId', 'siteId'], true);
        $this->addForeignKey(null, '{{%simpleform_forms_sites}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_forms_sites}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');

        // Fields table — SHARED structural data, keyed by id
        $this->createTable('{{%simpleform_fields}}', [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'type' => $this->string(50)->notNull(),
            'name' => $this->string(255)->notNull(),
            'required' => $this->boolean()->notNull()->defaultValue(false),
            'config' => $this->json(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_fields}}', ['formId']);
        $this->addForeignKey(null, '{{%simpleform_fields}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');

        // Per-site field content — translatable label/help text, one row per (field, site)
        $this->createTable('{{%simpleform_fields_sites}}', [
            'id' => $this->primaryKey(),
            'fieldId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'label' => $this->string(255),
            'helpText' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%simpleform_fields_sites}}', ['fieldId', 'siteId'], true);
        $this->addForeignKey(null, '{{%simpleform_fields_sites}}', ['fieldId'], '{{%simpleform_fields}}', ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%simpleform_fields_sites}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');

        // Submissions table — unchanged; formId still resolves to simpleform_forms.id (the element id)
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
        // Drop in FK-safe order (children before parents)
        $this->dropTableIfExists('{{%simpleform_submissions}}');
        $this->dropTableIfExists('{{%simpleform_fields_sites}}');
        $this->dropTableIfExists('{{%simpleform_fields}}');
        $this->dropTableIfExists('{{%simpleform_forms_sites}}');
        $this->dropTableIfExists('{{%simpleform_forms}}');

        return true;
    }
}
