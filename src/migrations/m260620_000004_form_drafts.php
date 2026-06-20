<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Partial form submissions for save-&-resume. A draft holds the values entered
 * so far, keyed by a hash of the resume token (the token itself only ever lives
 * in the resume URL, never in the DB), with an expiry for GC.
 */
class m260620_000004_form_drafts extends Migration
{
    private const TABLE = '{{%simpleform_form_drafts}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            // SHA-256 of the resume token; we look a draft up by this, never store
            // the plaintext token.
            'tokenHash' => $this->char(64)->notNull(),
            'data' => $this->json(),
            'dateExpires' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, self::TABLE, ['tokenHash'], true);
        $this->createIndex(null, self::TABLE, ['dateExpires'], false);
        $this->createIndex(null, self::TABLE, ['formId'], false);

        // A draft belongs to a form; drop it when the form is deleted.
        $this->addForeignKey(null, self::TABLE, ['formId'], '{{%simpleform_forms}}', ['id'], 'CASCADE', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
