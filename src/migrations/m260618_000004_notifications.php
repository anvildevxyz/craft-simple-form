<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Per-form email notifications (#112): a form can now own any number of
 * notifications (admin alerts + autoresponders), each with its own recipient,
 * subject, body, reply-to and optional send condition. The legacy single
 * emailTo/emailSubject/emailReplyTo/emailBody columns on the form are migrated
 * into one "Default notification" per form so existing behaviour is preserved.
 */
class m260618_000004_notifications extends Migration
{
    private const TABLE = '{{%simpleform_notifications}}';
    private const FORMS = '{{%simpleform_forms}}';
    private const FORMS_SITES = '{{%simpleform_forms_sites}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE)) {
            $this->createTable(self::TABLE, [
                'id' => $this->primaryKey(),
                'formId' => $this->integer()->notNull(),
                'name' => $this->string()->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                // 'fixed' = literal address(es); 'field' = a form field whose
                // submitted value holds the recipient (autoresponder).
                'recipientType' => $this->string(20)->notNull()->defaultValue('fixed'),
                'recipient' => $this->string()->notNull()->defaultValue(''),
                'subject' => $this->string(),
                'replyTo' => $this->string(),
                'body' => $this->text(),
                'conditional' => $this->json(),
                'sortOrder' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TABLE, ['formId']);
            $this->addForeignKey(null, self::TABLE, ['formId'], self::FORMS, ['id'], 'CASCADE', 'CASCADE');
        }

        // Carry each form's legacy (per-site) email config across as a single
        // default notification. The email columns live on simpleform_forms_sites;
        // take the first site that has a recipient configured.
        if ($this->db->columnExists(self::FORMS_SITES, 'emailTo')) {
            $hasBody = $this->db->columnExists(self::FORMS_SITES, 'emailBody');
            $cols = ['formId', 'emailTo', 'emailSubject', 'emailReplyTo'];
            if ($hasBody) {
                $cols[] = 'emailBody';
            }

            $rows = (new Query())->select($cols)->from(self::FORMS_SITES)->all($this->db);

            $seen = [];
            foreach ($rows as $row) {
                $formId = (int) $row['formId'];
                if (isset($seen[$formId])) {
                    continue;
                }
                $to = trim((string) ($row['emailTo'] ?? ''));
                if ($to === '') {
                    continue;
                }
                $seen[$formId] = true;
                $this->insert(self::TABLE, [
                    'formId' => $formId,
                    'name' => 'Default notification',
                    'enabled' => true,
                    'recipientType' => 'fixed',
                    'recipient' => $to,
                    'subject' => $row['emailSubject'] ?: null,
                    'replyTo' => $row['emailReplyTo'] ?: null,
                    'body' => ($hasBody ? ($row['emailBody'] ?? null) : null) ?: null,
                    'sortOrder' => 1,
                ]);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        return true;
    }
}
