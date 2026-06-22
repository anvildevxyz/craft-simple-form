<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Partial-submission drafts for save-&-resume. A draft stores the values entered
 * so far, addressed by a high-entropy resume token. Only a SHA-256 hash of the
 * token is persisted — the token itself lives only in the resume URL handed to
 * the visitor — so a database read alone can't resurrect a resumable session.
 */
class DraftService extends Component
{
    private const TABLE = '{{%simpleform_form_drafts}}';

    /** Default lifetime (days) when no setting is configured. */
    private const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Persist a draft and return its plaintext resume token. When $existingToken
     * is supplied and matches a draft, that draft is updated in place (and its
     * expiry refreshed) so re-saving keeps the same resume URL.
     *
     * @param array<string, mixed> $data field_<id> => value map entered so far
     */
    public function save(int $formId, int $siteId, array $data, ?string $existingToken = null): string
    {
        $token = ($existingToken !== null && $existingToken !== '') ? $existingToken : bin2hex(random_bytes(32));

        $now = Db::prepareDateForDb(new \DateTime());
        $expires = Db::prepareDateForDb((new \DateTime())->modify('+' . $this->retentionDays() . ' days'));
        $hash = $this->hash($token);
        $db = Craft::$app->getDb();

        $existingId = (new Query())
            ->select(['id'])
            ->from(self::TABLE)
            ->where(['tokenHash' => $hash])
            ->scalar();

        $row = [
            'formId' => $formId,
            'siteId' => $siteId,
            // Craft's json() column encodes the array exactly once — pass the
            // array, not a pre-encoded string (that would double-encode).
            'data' => $data,
            'dateExpires' => $expires,
            'dateUpdated' => $now,
        ];

        if ($existingId !== false && $existingId !== null) {
            $db->createCommand()->update(self::TABLE, $row, ['id' => $existingId])->execute();
        } else {
            $db->createCommand()->insert(self::TABLE, $row + [
                'tokenHash' => $hash,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        return $token;
    }

    /**
     * The saved values for a resume token, scoped to a form and not yet expired.
     * Returns null when the token is unknown, for a different form, or expired.
     *
     * @return array<string, mixed>|null
     */
    public function getData(string $token, int $formId): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = (new Query())
            ->select(['data'])
            ->from(self::TABLE)
            ->where([
                'tokenHash' => $this->hash($token),
                'formId' => $formId,
            ])
            ->andWhere(['>', 'dateExpires', Db::prepareDateForDb(new \DateTime())])
            ->one();

        if ($row === null) {
            return null;
        }

        $data = is_array($row['data']) ? $row['data'] : Json::decodeIfJson((string) $row['data']);
        return is_array($data) ? $data : [];
    }

    /**
     * Delete the draft for a resume token (e.g. once it has been submitted).
     */
    public function delete(string $token): void
    {
        if ($token === '') {
            return;
        }
        Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['tokenHash' => $this->hash($token)])
            ->execute();
    }

    /**
     * Delete every expired draft. Returns the number removed.
     */
    public function gcExpired(): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['<', 'dateExpires', Db::prepareDateForDb(new \DateTime())])
            ->execute();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function retentionDays(): int
    {
        $days = (int) Plugin::getInstance()->getSettings()->draftRetentionDays;
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }
}
