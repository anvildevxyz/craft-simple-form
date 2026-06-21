<?php

namespace fabianhaef\simpleform\migrations;

use craft\db\Migration;

/**
 * Element-integration link (#142): records the Craft element a dispatch created
 * on its log row, so the submission detail view can deep-link to the created
 * Entry/User and a resend can skip a pair that already produced one.
 */
class m260621_000001_integration_log_element_link extends Migration
{
    private const LOG_TABLE = '{{%simpleform_integration_logs}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::LOG_TABLE, 'elementId')) {
            $this->addColumn(self::LOG_TABLE, 'elementId', $this->integer()->after('responseCode'));
        }
        if (!$this->db->columnExists(self::LOG_TABLE, 'elementType')) {
            $this->addColumn(self::LOG_TABLE, 'elementType', $this->string(255)->after('elementId'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::LOG_TABLE, 'elementType')) {
            $this->dropColumn(self::LOG_TABLE, 'elementType');
        }
        if ($this->db->columnExists(self::LOG_TABLE, 'elementId')) {
            $this->dropColumn(self::LOG_TABLE, 'elementId');
        }

        return true;
    }
}
