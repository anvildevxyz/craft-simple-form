<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Email config now lives solely on a form's Notifications screen; the form's
 * built-in Email Settings block (the legacy emailTo/emailSubject/emailReplyTo/
 * emailBody columns) is no longer editable in the CP.
 *
 * The original notifications migration ({@see m260618_000004_notifications})
 * already folded each form's legacy email into a "Default notification". This
 * catch-up migration does the same for any form created via the old Email
 * Settings block since then — i.e. a form that still has a legacy emailTo but
 * no notification rows — so its email keeps sending. Forms that already own a
 * notification are left untouched. The legacy columns and send path are kept as
 * a dormant fallback, so this migration is purely additive (no data is dropped).
 */
class m260622_000001_merge_default_notification extends Migration
{
    private const TABLE = '{{%simpleform_notifications}}';
    private const FORMS_SITES = '{{%simpleform_forms_sites}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE) || !$this->db->columnExists(self::FORMS_SITES, 'emailTo')) {
            return true;
        }

        $hasBody = $this->db->columnExists(self::FORMS_SITES, 'emailBody');
        $cols = ['formId', 'emailTo', 'emailSubject', 'emailReplyTo'];
        if ($hasBody) {
            $cols[] = 'emailBody';
        }

        // Forms that already own at least one notification are skipped.
        $existing = (new Query())
            ->select(['formId'])
            ->from(self::TABLE)
            ->column($this->db);
        $existing = array_flip(array_map('intval', $existing));

        // The legacy email lives per-site on simpleform_forms_sites; take the
        // first site that has a recipient configured (one default per form).
        $rows = (new Query())->select($cols)->from(self::FORMS_SITES)->all($this->db);

        $seen = [];
        foreach ($rows as $row) {
            $formId = (int) $row['formId'];
            if (isset($seen[$formId]) || isset($existing[$formId])) {
                continue;
            }
            $to = trim((string) ($row['emailTo'] ?? ''));
            if ($to === '') {
                continue;
            }
            $seen[$formId] = true;
            // craft\db\Migration::insert() auto-populates dateCreated/dateUpdated/uid.
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

        return true;
    }

    public function safeDown(): bool
    {
        // Additive only — the migrated notifications are real data, kept in place.
        return true;
    }
}
