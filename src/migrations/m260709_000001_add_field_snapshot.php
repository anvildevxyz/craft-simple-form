<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Field snapshots (#312): a compact JSON `fieldSnapshot` column on
 * `simpleform_submissions` capturing the form's input-field structure — handle,
 * label, type, option labels, and display order — as it stood at submit time.
 * The CP submission detail view and every CSV export render from the snapshot
 * when present, so renaming, reordering, or deleting a field later never
 * corrupts existing submissions. Pre-existing rows have a null snapshot and fall
 * back to the values stored inline on `data`; no backfill.
 *
 * Idempotent (column-existence guarded) because the integration/smoke suites
 * re-run it on top of a fresh Install.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260709_000001_add_field_snapshot extends Migration
{
    public function safeUp(): bool
    {
        $submissions = '{{%simpleform_submissions}}';
        if (!$this->db->columnExists($submissions, 'fieldSnapshot')) {
            $this->addColumn($submissions, 'fieldSnapshot', $this->json()->after('data'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $submissions = '{{%simpleform_submissions}}';
        if ($this->db->columnExists($submissions, 'fieldSnapshot')) {
            $this->dropColumn($submissions, 'fieldSnapshot');
        }

        return true;
    }
}
