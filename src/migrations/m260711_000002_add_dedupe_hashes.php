<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;

/**
 * Indexed duplicate-detection + guest-email limiting (#341). Adds two
 * denormalized, non-reversible hash columns to `simpleform_submissions`,
 * populated on save, so the two hot-path lookups stop full-scanning:
 *
 *  - `dedupeHash` — SHA-256 of the submission's dedupe fingerprint (per the
 *    form's `duplicateKey`), so `preventDuplicates` becomes an indexed
 *    `(formId, dedupeHash)` existence check instead of hydrating the whole
 *    form history and rehashing in PHP.
 *  - `guestEmailHash` — SHA-256 of the normalized first-email-field value, so
 *    the per-guest email cap becomes an indexed `(formId, guestEmailHash)`
 *    count instead of a `LIKE` over the JSON `data` blob.
 *
 * Historical rows are not backfilled (the raw values needed to hash them the
 * same way aren't recoverable cheaply); dedup/limit correctness is guaranteed
 * for submissions created from this migration forward.
 *
 * Idempotent (column/index-existence guarded).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260711_000002_add_dedupe_hashes extends Migration
{
    private const TABLE = '{{%simpleform_submissions}}';

    public function safeUp(): bool
    {
        if (!$this->db->columnExists(self::TABLE, 'dedupeHash')) {
            $this->addColumn(self::TABLE, 'dedupeHash', $this->char(64)->after('ipHash'));
        }
        if (!$this->db->columnExists(self::TABLE, 'guestEmailHash')) {
            $this->addColumn(self::TABLE, 'guestEmailHash', $this->char(64)->after('dedupeHash'));
        }

        $existing = $this->existingIndexColumnSets();
        foreach ([['formId', 'dedupeHash'], ['formId', 'guestEmailHash']] as $columns) {
            if (!in_array($columns, $existing, true)) {
                $this->createIndex(null, self::TABLE, $columns);
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(self::TABLE, 'dedupeHash')) {
            $this->dropColumn(self::TABLE, 'dedupeHash');
        }
        if ($this->db->columnExists(self::TABLE, 'guestEmailHash')) {
            $this->dropColumn(self::TABLE, 'guestEmailHash');
        }

        return true;
    }

    /**
     * Column-name lists of every index currently on the table, so a create is
     * skipped when an equivalent index already exists (name-agnostic, portable).
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
}
