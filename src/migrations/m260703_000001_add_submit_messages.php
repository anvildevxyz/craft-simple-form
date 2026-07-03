<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Conditional submit messages (#265): an ordered, condition-gated list of
 * confirmation messages per form. The rule/priority lives in a shared structural
 * table; the message text is per-site translatable in a companion table — the
 * same shared-vs-per-site split used by fields.
 *
 * @author Fabian Haefliger
 */
class m260703_000001_add_submit_messages extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%simpleform_submit_messages}}')) {
            $this->createTable('{{%simpleform_submit_messages}}', [
                'id' => $this->primaryKey(),
                'formId' => $this->integer()->notNull(),
                'conditional' => $this->json(),
                'sortOrder' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%simpleform_submit_messages}}', ['formId']);
            $this->addForeignKey(null, '{{%simpleform_submit_messages}}', ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', 'CASCADE');
        }

        if (!$this->db->tableExists('{{%simpleform_submit_messages_sites}}')) {
            $this->createTable('{{%simpleform_submit_messages_sites}}', [
                'id' => $this->primaryKey(),
                'submitMessageId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'message' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%simpleform_submit_messages_sites}}', ['submitMessageId', 'siteId'], true);
            $this->addForeignKey(null, '{{%simpleform_submit_messages_sites}}', ['submitMessageId'], '{{%simpleform_submit_messages}}', ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%simpleform_submit_messages_sites}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%simpleform_submit_messages_sites}}');
        $this->dropTableIfExists('{{%simpleform_submit_messages}}');

        return true;
    }
}
