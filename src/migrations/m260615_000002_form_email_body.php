<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Per-site translatable notification email body template.
 *
 * Lives in the per-site forms table alongside the existing per-site subject and
 * reply-to, so an editor can author the notification body in each site's
 * language. A null/empty value falls back to the shared default template, so the
 * email is never blank.
 */
class m260615_000002_form_email_body extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%simpleform_forms_sites}}';

        if (!$this->db->columnExists($table, 'emailBody')) {
            $this->addColumn($table, 'emailBody', $this->text()->after('emailSubject'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%simpleform_forms_sites}}';

        if ($this->db->columnExists($table, 'emailBody')) {
            $this->dropColumn($table, 'emailBody');
        }

        return true;
    }
}
