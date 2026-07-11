<?php

namespace anvildev\simpleform\migrations;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Migration;
use craft\db\Query;

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
 * Existing rows ARE backfilled in bounded batches from their stored `data`,
 * `formId` and `ipHash`, so duplicate detection and the guest-email cap keep
 * matching pre-migration submissions (they'd otherwise carry NULL hashes and
 * become invisible to both lookups). Rows whose form was deleted, or for which
 * no fingerprint/email is derivable, keep NULL — the same result the live
 * computation would produce.
 *
 * Idempotent (column/index-existence guarded; the backfill only touches rows
 * that don't yet have the columns' values because it runs right after the
 * columns are added).
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

        $this->backfillHashes();

        return true;
    }

    /**
     * Populate `dedupeHash`/`guestEmailHash` for pre-existing rows, in bounded
     * `id`-paginated batches (forward `->all()` pages, updates issued after each
     * page so no unbuffered cursor is open across an UPDATE). Forms are memoized
     * per id. `id`-pagination — not a "hash IS NULL" predicate — drains the table
     * because NULL is also a legitimate final value for rows with no
     * fingerprint/email.
     */
    private function backfillHashes(): void
    {
        $service = Plugin::getInstance()->getSubmissionService();
        $db = Craft::$app->getDb();

        /** @var array<int, Form|null> $forms */
        $forms = [];
        $lastId = 0;
        do {
            $rows = (new Query())
                ->select(['id', 'formId', 'data', 'ipHash'])
                ->from(self::TABLE)
                ->where(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(500)
                ->all();

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $formId = (int) $row['formId'];
                if (!array_key_exists($formId, $forms)) {
                    $forms[$formId] = Form::find()->id($formId)->status(null)->one();
                }
                $form = $forms[$formId];
                if ($form === null) {
                    continue;
                }

                $data = is_array($row['data']) ? $row['data'] : (json_decode((string) $row['data'], true) ?: []);
                if (!is_array($data)) {
                    $data = [];
                }

                $db->createCommand()->update(self::TABLE, [
                    'dedupeHash' => $service->computeDedupeHash($form, $data, $row['ipHash']),
                    'guestEmailHash' => $service->computeGuestEmailHash($form, $data),
                ], ['id' => $row['id']])->execute();
            }
        } while (count($rows) === 500);
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
