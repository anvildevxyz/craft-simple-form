<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\models\IntegrationModel;
use yii\base\Component;

/**
 * Owns per-form integration configs and the dispatch log. Slice 1 provides the
 * CRUD + logging surface; async dispatch off EVENT_AFTER_SUBMISSION_SAVE is
 * wired in slice 2 (#77).
 */
class IntegrationsService extends Component
{
    private const TABLE = '{{%simpleform_integrations}}';
    private const LOG_TABLE = '{{%simpleform_integration_logs}}';

    /**
     * All integrations configured on a form, ordered by sortOrder.
     *
     * @return list<IntegrationModel>
     */
    public function getIntegrationsForForm(int $formId): array
    {
        $rows = (new \craft\db\Query())
            ->from(self::TABLE)
            ->where(['formId' => $formId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map([$this, 'rowToModel'], $rows);
    }

    /**
     * Only the enabled integrations on a form — the dispatch set.
     *
     * @return list<IntegrationModel>
     */
    public function getEnabledIntegrationsForForm(int $formId): array
    {
        return array_values(array_filter(
            $this->getIntegrationsForForm($formId),
            static fn(IntegrationModel $i): bool => $i->enabled,
        ));
    }

    public function getIntegrationById(int $id): ?IntegrationModel
    {
        $row = (new \craft\db\Query())
            ->from(self::TABLE)
            ->where(['id' => $id])
            ->one();

        return $row ? $this->rowToModel($row) : null;
    }

    /**
     * Insert or update an integration config. Populates `id`/`uid` on insert.
     */
    public function saveIntegration(IntegrationModel $integration): bool
    {
        if (!$integration->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        $attrs = [
            'formId' => $integration->formId,
            'type' => $integration->type,
            'name' => $integration->name,
            'enabled' => $integration->enabled,
            // Craft encodes the array for the json column on write (mirrors how
            // fields' `config` is stored).
            'settings' => $integration->settings,
            'sortOrder' => $integration->sortOrder,
            'dateUpdated' => $now,
        ];

        if ($integration->id === null) {
            $integration->uid = StringHelper::UUID();
            $db->createCommand()->insert(self::TABLE, $attrs + [
                'dateCreated' => $now,
                'uid' => $integration->uid,
            ])->execute();
            $integration->id = (int) $db->getLastInsertID();
            return true;
        }

        $db->createCommand()->update(self::TABLE, $attrs, ['id' => $integration->id])->execute();
        return true;
    }

    public function deleteIntegration(int $id): bool
    {
        $count = Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id])
            ->execute();
        return $count > 0;
    }

    /**
     * Record (or upsert) a dispatch attempt. Returns the log row id.
     */
    public function logDispatch(
        int $integrationId,
        ?int $submissionId,
        string $status,
        int $attempts = 1,
        ?int $responseCode = null,
        string $message = '',
    ): int {
        $status = DispatchStatus::isValid($status) ? $status : DispatchStatus::PENDING;
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->createCommand()->insert(self::LOG_TABLE, [
            'integrationId' => $integrationId,
            'submissionId' => $submissionId,
            'status' => $status,
            'attempts' => $attempts,
            'responseCode' => $responseCode,
            // Keep the stored response bounded — log rows are diagnostic, not archival.
            'message' => StringHelper::safeTruncate($message, 1000),
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int) $db->getLastInsertID();
    }

    /**
     * The dispatch-log rows for a submission, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogsForSubmission(int $submissionId): array
    {
        return (new \craft\db\Query())
            ->from(self::LOG_TABLE)
            ->where(['submissionId' => $submissionId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->all();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToModel(array $row): IntegrationModel
    {
        $model = new IntegrationModel();
        $model->id = (int) $row['id'];
        $model->formId = (int) $row['formId'];
        $model->type = (string) $row['type'];
        $model->name = (string) $row['name'];
        $model->enabled = (bool) $row['enabled'];
        // The json column comes back as a string (MySQL) or already decoded
        // (Postgres / some drivers); normalise to an array either way.
        $settings = $row['settings'] ?? null;
        if (is_array($settings)) {
            $model->settings = $settings;
        } elseif (is_string($settings) && $settings !== '') {
            $decoded = Json::decodeIfJson($settings);
            $model->settings = is_array($decoded) ? $decoded : [];
        } else {
            $model->settings = [];
        }
        $model->sortOrder = $row['sortOrder'] !== null ? (int) $row['sortOrder'] : null;
        $model->uid = $row['uid'] ?? null;
        return $model;
    }
}
