<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Add the missing `simpleform_submissions.id` → `elements.id` cascade (#113).
 * Forms already cascade with their element; submissions did not, so hard-deleting
 * a submission element (trash GC or retention purge) orphaned its plugin row.
 * With this FK, soft-delete/restore work natively and a permanent delete cleans
 * up the submission row automatically.
 */
class m260618_000005_submission_element_fk extends Migration
{
    private const TABLE = '{{%simpleform_submissions}}';

    public function safeUp(): bool
    {
        // Drop any pre-existing orphan rows so the FK can be added cleanly.
        $orphanIds = (new Query())
            ->select(['s.id'])
            ->from(['s' => self::TABLE])
            ->leftJoin(['e' => '{{%elements}}'], '[[e.id]] = [[s.id]]')
            ->where(['e.id' => null])
            ->column($this->db);

        if ($orphanIds !== []) {
            $this->delete(self::TABLE, ['id' => array_map('intval', $orphanIds)]);
        }

        $this->addForeignKey(null, self::TABLE, ['id'], '{{%elements}}', ['id'], 'CASCADE', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropForeignKeyIfExists(self::TABLE, ['id']);
        return true;
    }
}
