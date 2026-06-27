<?php

namespace anvildev\simpleform\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Make coupon-code uniqueness case-insensitive on Postgres (#251). The original
 * coupons migration created a plain unique index on `code`; on Postgres that is
 * case-sensitive, so `SAVE10` and `save10` could coexist even though the service
 * looks codes up case-insensitively (`LOWER(code)`). This swaps the plain index
 * for a functional unique index on `LOWER(code)` so the DB guarantee matches the
 * lookup. MySQL is left untouched — its default `_ci` collation already makes the
 * plain unique index case-insensitive.
 *
 * Idempotent: re-running detects the functional index and the already-dropped
 * plain one and does nothing.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260627_000007_coupon_code_ci_unique_pg extends Migration
{
    private const FUNCTIONAL_INDEX = 'simpleform_coupons_code_lower_unq';

    public function safeUp(): bool
    {
        if (!$this->db->getIsPgsql()) {
            // MySQL's case-insensitive collation already enforces this.
            return true;
        }

        $rawTable = $this->db->getSchema()->getRawTableName('{{%simpleform_coupons}}');

        // Drop the original plain unique index(es) on (code) — auto-named, and
        // not the new functional one (which contains `lower`).
        $plainIndexes = (new Query())
            ->select(['indexname'])
            ->from('pg_indexes')
            ->where(['tablename' => $rawTable])
            ->andWhere(['like', 'indexdef', '%(code)%'])
            ->andWhere(['not like', 'lower(indexdef)', '%lower%'])
            ->column($this->db);

        foreach ($plainIndexes as $name) {
            $this->execute('DROP INDEX IF EXISTS ' . $this->db->quoteTableName((string) $name));
        }

        $hasFunctional = (new Query())
            ->from('pg_indexes')
            ->where(['tablename' => $rawTable, 'indexname' => self::FUNCTIONAL_INDEX])
            ->exists($this->db);

        if (!$hasFunctional) {
            $this->execute(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (LOWER(code))',
                self::FUNCTIONAL_INDEX,
                $this->db->quoteTableName('{{%simpleform_coupons}}'),
            ));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->getIsPgsql()) {
            return true;
        }

        $this->execute('DROP INDEX IF EXISTS ' . $this->db->quoteTableName(self::FUNCTIONAL_INDEX));
        // Restore a plain unique index so the column stays unique.
        $this->createIndex(null, '{{%simpleform_coupons}}', ['code'], true);

        return true;
    }
}
