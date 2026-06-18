<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\integrations\IntegrationResult;
use fabianhaef\simpleform\jobs\SendIntegrationJob;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;
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

    /**
     * Entry point from the EVENT_AFTER_SUBMISSION_SAVE listener: dispatch every
     * enabled integration on the submission's form. Queued by default; run inline
     * only when `dispatchIntegrationsSynchronously` is on.
     */
    public function dispatchForSubmission(Submission $submission): void
    {
        $integrations = $this->getEnabledIntegrationsForForm((int) $submission->formId);
        if ($integrations === []) {
            return;
        }

        $sync = Plugin::getInstance()->getSettings()->dispatchIntegrationsSynchronously;

        foreach ($integrations as $integration) {
            if ($integration->id === null) {
                continue;
            }
            if ($sync) {
                $this->runOnce($integration, $submission);
                continue;
            }
            Craft::$app->getQueue()->push(new SendIntegrationJob([
                'integrationId' => $integration->id,
                'submissionId' => (int) $submission->id,
            ]));
        }
    }

    /**
     * Perform a single dispatch attempt for one integration + submission, record
     * a log row, and return the result. Shared by the queue job and the sync path.
     */
    public function runOnce(IntegrationModel $integration, Submission $submission): IntegrationResult
    {
        $integrationId = (int) $integration->id;
        $submissionId = (int) $submission->id;
        $attempt = $this->countAttempts($integrationId, $submissionId) + 1;

        $type = Plugin::getInstance()->getIntegrationTypeRegistry()->getType($integration->type);
        if ($type === null) {
            $message = "Unknown integration type: {$integration->type}";
            $this->logDispatch($integrationId, $submissionId, DispatchStatus::FAILED, $attempt, null, $message);
            return IntegrationResult::failure(null, $message);
        }

        try {
            $result = $type->send($submission, $this->parseEnvSettings($integration->settings));
        } catch (\Throwable $e) {
            $this->logDispatch($integrationId, $submissionId, DispatchStatus::FAILED, $attempt, null, $e->getMessage());
            return IntegrationResult::failure(null, $e->getMessage());
        }

        $this->logDispatch(
            $integrationId,
            $submissionId,
            $result->success ? DispatchStatus::SUCCESS : DispatchStatus::FAILED,
            $attempt,
            $result->responseCode,
            $result->message,
        );

        return $result;
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

    private function countAttempts(int $integrationId, int $submissionId): int
    {
        return (int) (new \craft\db\Query())
            ->from(self::LOG_TABLE)
            ->where(['integrationId' => $integrationId, 'submissionId' => $submissionId])
            ->count();
    }

    /**
     * Resolve env references (`$VAR`) in string settings so connectors receive
     * usable secrets/URLs without each having to parse. Walks nested arrays.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function parseEnvSettings(array $settings): array
    {
        $walk = static function($value) use (&$walk) {
            if (is_string($value)) {
                return App::parseEnv($value);
            }
            if (is_array($value)) {
                return array_map($walk, $value);
            }
            return $value;
        };

        /** @var array<string, mixed> $parsed */
        $parsed = array_map($walk, $settings);
        return $parsed;
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
