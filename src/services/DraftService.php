<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\events\PartialCaptureEvent;
use anvildev\simpleform\fields\ConsentFieldType;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
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
     * A `$passive` draft (#242) is an auto-captured partial — distinct from a
     * user-initiated save-and-continue draft — so the CP can list and govern the
     * two differently.
     *
     * @param array<string, mixed> $data field_<id> => value map entered so far
     */
    public function save(int $formId, int $siteId, array $data, ?string $existingToken = null, bool $passive = false): string
    {
        $token = ($existingToken !== null && $existingToken !== '') ? $existingToken : bin2hex(random_bytes(32));

        // A passive partial governs on its own (conservative) retention window,
        // independent of the save-&-resume draft window.
        $days = $passive ? $this->partialRetentionDays() : $this->retentionDays();
        $now = Db::prepareDateForDb(new \DateTime());
        $expires = Db::prepareDateForDb((new \DateTime())->modify('+' . $days . ' days'));
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
            'passive' => $passive,
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
     * Capture a passive partial for a form (#242/#244): gated on consent (a
     * consent field present on the form must be granted in the captured values),
     * stored on the conservative partial-retention window, and announced via
     * {@see Plugin::EVENT_PARTIAL_CAPTURED} so integrators can build abandonment
     * follow-up. Returns the token, or null when the capture is blocked (consent
     * not granted) or empty — the caller treats null as a quiet no-op.
     *
     * @param array<string, mixed> $values field_<id> => posted value
     */
    public function capturePartial(Form $form, array $values, int $siteId, ?string $existingToken = null): ?string
    {
        if ($values === [] || !$this->consentGranted($form, $values, $siteId)) {
            return null;
        }

        $token = $this->save((int) $form->id, $siteId, $values, $existingToken, true);

        Plugin::getInstance()->trigger(
            Plugin::EVENT_PARTIAL_CAPTURED,
            new PartialCaptureEvent($form, $values, $siteId, $token),
        );

        return $token;
    }

    /**
     * Whether the form's consent fields (if any) are all granted in the captured
     * values — the consent gate (#244). A consent checkbox posts "1" only when
     * ticked; an unticked/absent one means the visitor hasn't consented, so the
     * capture must not store their data. No consent field → nothing to gate.
     *
     * @param array<string, mixed> $values
     */
    private function consentGranted(Form $form, array $values, int $siteId): bool
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, $siteId);
        foreach ($fields as $field) {
            if ($field['type'] !== ConsentFieldType::getType()) {
                continue;
            }
            $value = $values['field_' . $field['id']] ?? null;
            if ($value !== '1' && $value !== 1 && $value !== true) {
                return false;
            }
        }

        return true;
    }

    private function partialRetentionDays(): int
    {
        $days = (int) Plugin::getInstance()->getSettings()->partialRetentionDays;
        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * The passive partials (#242) captured for a form on a site, newest first,
     * with their decoded data and timestamps — for the CP abandoned listing.
     * The raw token never leaves the table (only its hash is stored), so a
     * partial can be viewed and deleted by its row id but not resumed from here.
     *
     * @return list<array{id: int, data: array<string, mixed>, fieldCount: int, dateCreated: mixed, dateUpdated: mixed}>
     */
    public function listPassive(int $formId, int $siteId): array
    {
        $rows = (new Query())
            ->select(['id', 'data', 'dateCreated', 'dateUpdated'])
            ->from(self::TABLE)
            ->where(['formId' => $formId, 'siteId' => $siteId, 'passive' => true])
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->all();

        $partials = [];
        foreach ($rows as $row) {
            $data = is_array($row['data']) ? $row['data'] : Json::decodeIfJson((string) $row['data']);
            $data = is_array($data) ? $data : [];
            // Count only fields with a non-empty value as "captured".
            $fieldCount = 0;
            foreach ($data as $value) {
                if ($value !== null && $value !== '' && $value !== []) {
                    $fieldCount++;
                }
            }
            $partials[] = [
                'id' => (int) $row['id'],
                'data' => $data,
                'fieldCount' => $fieldCount,
                'dateCreated' => $row['dateCreated'],
                'dateUpdated' => $row['dateUpdated'],
            ];
        }

        return $partials;
    }

    /**
     * Delete a passive partial by its row id (the CP manual-delete action).
     * Scoped to the form so a forged id can't reach another form's drafts.
     */
    public function deletePassiveById(int $id, int $formId): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id, 'formId' => $formId, 'passive' => true])
            ->execute();
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
