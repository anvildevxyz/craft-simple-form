<?php

namespace anvildev\simpleform\migrations;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Migration;
use craft\db\Query;

/**
 * Security hardening: re-key the denormalized privacy/dedupe hash columns so
 * they stop being reversible plain SHA-256 digests.
 *
 * The `ipHash`, `guestEmailHash` and `dedupeHash` columns (#341, #326) were
 * originally stored as unsalted `sha256(value)`. A full IPv4 address (~4.3B
 * possibilities) or a known email is trivially recovered from such a hash by
 * precomputation, so anyone with DB-only read access (a leaked backup, a read
 * replica, or SQLi elsewhere) could reverse `ipHash` back to the exact IP —
 * defeating the `ipCapturePolicy` anonymization guarantee (CWE-759 / CWE-916).
 * {@see \anvildev\simpleform\services\SubmissionService::keyedHash()} now keys
 * these with the site security key.
 *
 * This delta reconciles rows written by the old code:
 *  - `guestEmailHash` / `dedupeHash` are recomputed from each row's still-present
 *    `data`/`formId` via the (now-keyed) service methods, so the indexed
 *    duplicate and guest-email-cap lookups keep matching historical rows.
 *  - `ipHash` cannot be re-keyed (the raw IP was never stored) and its old plain
 *    value is both reversible and inconsistent with newly keyed hashes, so it is
 *    purged (set NULL). IP-key duplicate detection simply rebuilds from new
 *    submissions — a recent-window concern only.
 *
 * On a fresh install the columns were already written by the keyed code (and
 * `ipHash` is NULL), so this is a no-op. Bounded `id`-paginated batches mirror
 * the original backfill in {@see m260711_000002_add_dedupe_hashes}.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class m260711_000004_rekey_privacy_hashes extends Migration
{
    private const TABLE = '{{%simpleform_submissions}}';

    public function safeUp(): bool
    {
        // Nothing to reconcile until all three columns exist.
        foreach (['ipHash', 'guestEmailHash', 'dedupeHash'] as $column) {
            if (!$this->db->columnExists(self::TABLE, $column)) {
                return true;
            }
        }

        $service = Plugin::getInstance()->getSubmissionService();
        $db = Craft::$app->getDb();

        /** @var array<int, Form|null> $forms */
        $forms = [];
        $lastId = 0;
        do {
            $rows = (new Query())
                ->select(['id', 'formId', 'data'])
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
                    // Purge the reversible ipHash even when the form is gone; the
                    // other two hashes can't be recomputed without the form.
                    $db->createCommand()->update(self::TABLE, ['ipHash' => null], ['id' => $row['id']])->execute();
                    continue;
                }

                $data = is_array($row['data']) ? $row['data'] : (json_decode((string) $row['data'], true) ?: []);
                if (!is_array($data)) {
                    $data = [];
                }

                // Recompute the recoverable hashes with the keyed method; the raw
                // IP is gone, so ipHash is purged (passing null also resets the
                // IP-key dedupeHash to null, consistent with a fresh start).
                $db->createCommand()->update(self::TABLE, [
                    'ipHash' => null,
                    'dedupeHash' => $service->computeDedupeHash($form, $data, null),
                    'guestEmailHash' => $service->computeGuestEmailHash($form, $data),
                ], ['id' => $row['id']])->execute();
            }
        } while (count($rows) === 500);

        return true;
    }

    public function safeDown(): bool
    {
        // Irreversible: the raw IPs required to restore the old ipHash values were
        // never stored. Leaving the keyed hashes in place is the safe no-op.
        return true;
    }
}
