<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Scaling indexes for `simpleform_submissions` (#337). At millions of rows the
 * CP status views, the dashboard/forms-index/Stats aggregates, the retention
 * sweep and the per-user/per-payment filters all scanned unindexed columns
 * (`readStatus`, the submissions `dateCreated`, `ipHash`, `userId`,
 * `paymentStatus`). These composite + single indexes cover the real query
 * shapes so those paths stop full-scanning.
 *
 * Idempotent (index-existence guarded by column set, not name) because the
 * integration/smoke suites re-run migrations on top of a fresh Install, and
 * because existing production installs may already carry a subset.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260711_000001_add_scaling_indexes extends Migration
{
    private const TABLE = '{{%simpleform_submissions}}';

    /** @var list<list<string>> Column sets to ensure an index exists for. */
    private const INDEXES = [
        ['formId', 'readStatus', 'dateCreated'],
        ['siteId', 'readStatus'],
        ['dateCreated'],
        ['ipHash'],
        ['userId'],
        ['paymentStatus'],
    ];

    public function safeUp(): bool
    {
        $existing = $this->existingIndexColumnSets();
        foreach (self::INDEXES as $columns) {
            if (!in_array($columns, $existing, true)) {
                $this->createIndex(null, self::TABLE, $columns);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Leave the pre-existing baseline indexes (formId, siteId, orderId,
        // workflowStatus) in place; only drop the ones this migration adds.
        //
        // Drops are best-effort per index: the single-column `userId` index this
        // migration adds is, on MySQL, the same index InnoDB uses to back the
        // userId foreign key, so dropping it errors with SQLSTATE 1553 ("needed in
        // a foreign key constraint"). On Postgres the FK has no such requirement
        // and the drop succeeds. Guarding each drop keeps rollback from aborting
        // mid-way on MySQL while still dropping every index that CAN be dropped.
        foreach ($this->matchingIndexNames() as $name) {
            try {
                $this->dropIndex($name, self::TABLE);
            } catch (\Throwable $e) {
                echo "    > skipped dropping index {$name} (still in use, e.g. backing a foreign key): {$e->getMessage()}\n";
            }
        }

        return true;
    }

    /**
     * The column-name lists of every index currently on the table, so we can
     * skip a create when an equivalent index already exists (portable across
     * MySQL and Postgres, name-agnostic).
     *
     * @return list<list<string>>
     */
    private function existingIndexColumnSets(): array
    {
        $sets = [];
        foreach ($this->db->getSchema()->getTableIndexes(self::TABLE) as $index) {
            $sets[] = array_values($index->columnNames);
        }

        return $sets;
    }

    /**
     * Names of the on-disk indexes whose column set matches one this migration
     * created, for a clean rollback.
     *
     * @return list<string>
     */
    private function matchingIndexNames(): array
    {
        $names = [];
        foreach ($this->db->getSchema()->getTableIndexes(self::TABLE) as $index) {
            if ($index->isPrimary || $index->isUnique) {
                continue;
            }
            if (in_array(array_values($index->columnNames), self::INDEXES, true)) {
                $names[] = $index->name;
            }
        }

        return $names;
    }
}
