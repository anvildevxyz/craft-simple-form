<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Audit log (#114): an append-only trail of form / field / integration /
 * notification / submission-status changes (actor, action, target, summary).
 * Pruned by the retention GC.
 */
class m260618_000006_audit_log extends Migration
{
    private const TABLE = '{{%simpleform_audit_log}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'userId' => $this->integer(),
            'action' => $this->string(60)->notNull(),
            'targetType' => $this->string(60)->notNull(),
            'targetId' => $this->integer(),
            'summary' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE, ['dateCreated']);
        // Keep the actor reference but survive user deletion.
        $this->addForeignKey(null, self::TABLE, ['userId'], '{{%users}}', ['id'], 'SET NULL', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
