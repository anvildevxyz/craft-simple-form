<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Re-model integrations from per-form rows to global definitions enabled per
 * form. An integration now lives once in `simpleform_integrations` and is
 * attached to any number of forms through `simpleform_form_integrations`; the
 * old form-scoped `formId` column is removed.
 */
class m260618_000003_global_form_integrations extends Migration
{
    private const INTEGRATIONS = '{{%simpleform_integrations}}';
    private const PIVOT = '{{%simpleform_form_integrations}}';
    private const FORMS = '{{%simpleform_forms}}';

    public function safeUp(): bool
    {
        // One row per (form, integration) attachment. Presence = the integration
        // is active on that form; dispatch additionally requires the integration
        // itself to be globally enabled.
        if (!$this->db->tableExists(self::PIVOT)) {
            $this->createTable(self::PIVOT, [
                'id' => $this->primaryKey(),
                'formId' => $this->integer()->notNull(),
                'integrationId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::PIVOT, ['formId', 'integrationId'], true);
            $this->createIndex(null, self::PIVOT, ['integrationId']);
            $this->addForeignKey(null, self::PIVOT, ['formId'], self::FORMS, ['id'], 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, self::PIVOT, ['integrationId'], self::INTEGRATIONS, ['id'], 'CASCADE', 'CASCADE');
        }

        // Carry existing per-form integrations across as attachments so nothing
        // silently stops dispatching.
        if ($this->db->columnExists(self::INTEGRATIONS, 'formId')) {
            $rows = (new Query())
                ->select(['id', 'formId'])
                ->from(self::INTEGRATIONS)
                ->all($this->db);

            foreach ($rows as $row) {
                // $this->insert() stamps dateCreated/dateUpdated/uid for us.
                $this->insert(self::PIVOT, [
                    'formId' => (int) $row['formId'],
                    'integrationId' => (int) $row['id'],
                ]);
            }

            $this->dropForeignKeyIfExists(self::INTEGRATIONS, ['formId']);
            $this->dropIndexIfExists(self::INTEGRATIONS, ['formId']);
            $this->dropColumn(self::INTEGRATIONS, 'formId');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->columnExists(self::INTEGRATIONS, 'formId')) {
            $this->addColumn(self::INTEGRATIONS, 'formId', $this->integer());

            // Best-effort restore: pick the first form each integration was
            // attached to.
            $rows = (new Query())
                ->select(['integrationId', 'formId'])
                ->from(self::PIVOT)
                ->orderBy(['id' => SORT_ASC])
                ->all($this->db);

            $seen = [];
            foreach ($rows as $row) {
                $iid = (int) $row['integrationId'];
                if (isset($seen[$iid])) {
                    continue;
                }
                $seen[$iid] = true;
                $this->update(self::INTEGRATIONS, ['formId' => (int) $row['formId']], ['id' => $iid]);
            }

            $this->createIndex(null, self::INTEGRATIONS, ['formId']);
            $this->addForeignKey(null, self::INTEGRATIONS, ['formId'], self::FORMS, ['id'], 'CASCADE', 'CASCADE');
        }

        $this->dropTableIfExists(self::PIVOT);

        return true;
    }
}
