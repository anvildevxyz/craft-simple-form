<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Add a `spam` value to the submissions `readStatus` enum (#88), so Akismet can
 * flag a submission as spam without dropping it.
 */
class m260618_000002_submission_spam_status extends Migration
{
    public function safeUp(): bool
    {
        $this->alterColumn(
            '{{%simpleform_submissions}}',
            'readStatus',
            $this->enum('readStatus', ['new', 'read', 'archived', 'spam'])->defaultValue('new'),
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->alterColumn(
            '{{%simpleform_submissions}}',
            'readStatus',
            $this->enum('readStatus', ['new', 'read', 'archived'])->defaultValue('new'),
        );

        return true;
    }
}
