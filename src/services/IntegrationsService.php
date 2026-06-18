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
 * Owns the global integration definitions, their per-form attachments, and the
 * dispatch log. Integrations are defined once and attached to forms through
 * `simpleform_form_integrations`; dispatch off EVENT_AFTER_SUBMISSION_SAVE
 * targets the attached + globally-enabled set for the submission's form.
 */
class IntegrationsService extends Component
{
    private const TABLE = '{{%simpleform_integrations}}';
    private const LOG_TABLE = '{{%simpleform_integration_logs}}';
    private const PIVOT_TABLE = '{{%simpleform_form_integrations}}';

    /**
     * Every integration definition (the global Settings index), ordered by
     * sortOrder then id.
     *
     * @return list<IntegrationModel>
     */
    public function getAllIntegrations(): array
    {
        $rows = (new \craft\db\Query())
            ->from(self::TABLE)
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map([$this, 'rowToModel'], $rows);
    }

    /**
     * The integrations attached to a form (regardless of their global enabled
     * flag), ordered by sortOrder. Used by the read-only exposure surfaces
     * (MCP / GraphQL) and the per-form management screen.
     *
     * @return list<IntegrationModel>
     */
    public function getIntegrationsForForm(int $formId): array
    {
        $rows = (new \craft\db\Query())
            ->select(['i.*'])
            ->from(['i' => self::TABLE])
            ->innerJoin(['fi' => self::PIVOT_TABLE], '[[fi.integrationId]] = [[i.id]]')
            ->where(['fi.formId' => $formId])
            ->orderBy(['i.sortOrder' => SORT_ASC, 'i.id' => SORT_ASC])
            ->all();

        return array_map([$this, 'rowToModel'], $rows);
    }

    /**
     * Only the integrations attached to a form *and* globally enabled — the
     * dispatch set.
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

    /**
     * The ids of integrations attached to a form, for rendering per-form toggles.
     *
     * @return list<int>
     */
    public function getAttachedIntegrationIds(int $formId): array
    {
        return array_map('intval', (new \craft\db\Query())
            ->select(['integrationId'])
            ->from(self::PIVOT_TABLE)
            ->where(['formId' => $formId])
            ->column());
    }

    /**
     * Attach or detach a single integration from a form. Returns the resulting
     * attached state (true = now attached).
     */
    public function toggleFormIntegration(int $formId, int $integrationId): bool
    {
        $db = Craft::$app->getDb();
        $exists = (new \craft\db\Query())
            ->from(self::PIVOT_TABLE)
            ->where(['formId' => $formId, 'integrationId' => $integrationId])
            ->exists();

        if ($exists) {
            $db->createCommand()
                ->delete(self::PIVOT_TABLE, ['formId' => $formId, 'integrationId' => $integrationId])
                ->execute();
            return false;
        }

        $now = Db::prepareDateForDb(new \DateTime());
        $db->createCommand()->insert(self::PIVOT_TABLE, [
            'formId' => $formId,
            'integrationId' => $integrationId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();
        return true;
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
            'type' => $integration->type,
            'name' => $integration->name,
            'enabled' => $integration->enabled,
            // Craft encodes the array for the json column on write (mirrors how
            // fields' `config` is stored).
            'settings' => $integration->settings,
            'sortOrder' => $integration->sortOrder,
            'dateUpdated' => $now,
        ];

        $isNew = $integration->id === null;
        if ($isNew) {
            $integration->uid = StringHelper::UUID();
            $db->createCommand()->insert(self::TABLE, $attrs + [
                'dateCreated' => $now,
                'uid' => $integration->uid,
            ])->execute();
            $integration->id = (int) $db->getLastInsertID();
        } else {
            $db->createCommand()->update(self::TABLE, $attrs, ['id' => $integration->id])->execute();
        }

        Plugin::getInstance()->getAudit()->log(
            $isNew ? 'integration.create' : 'integration.save',
            'integration',
            $integration->id,
            sprintf('%s (%s)', $integration->name, $integration->type),
        );

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

    /**
     * Validate a settings array against a connector's own rules.
     *
     * @param array<string, mixed> $settings
     * @return array<string, array<int, string>> attribute => errors (empty if valid)
     */
    public function validateSettings(\fabianhaef\simpleform\integrations\IntegrationTypeInterface $type, array $settings): array
    {
        $rules = $type->defineSettingsRules();

        // DynamicModel must know every attribute referenced by a rule.
        $attributes = $settings;
        foreach ($rules as $rule) {
            foreach ((array) ($rule[0] ?? []) as $attr) {
                if (!array_key_exists($attr, $attributes)) {
                    $attributes[$attr] = null;
                }
            }
        }

        $model = new \yii\base\DynamicModel($attributes);
        foreach ($rules as $rule) {
            $validator = $rule[1] ?? 'safe';
            $options = [];
            foreach ($rule as $key => $value) {
                if (!is_int($key)) {
                    $options[$key] = $value;
                }
            }
            $model->addRule((array) ($rule[0] ?? []), $validator, $options);
        }

        return $model->validate() ? [] : $model->getErrors();
    }

    public function deleteIntegration(int $id): bool
    {
        $count = Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id])
            ->execute();
        if ($count > 0) {
            Plugin::getInstance()->getAudit()->log('integration.delete', 'integration', $id);
        }
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
     * Recent dispatch health for one integration: attempt counts by status plus
     * the latest attempt's status/time/code. Diagnostic only — no payloads.
     *
     * @return array{total: int, success: int, failed: int, pending: int, lastStatus: ?string, lastDispatchedAt: ?string, lastResponseCode: ?int}
     */
    public function getDispatchHealth(int $integrationId): array
    {
        $rows = (new \craft\db\Query())
            ->from(self::LOG_TABLE)
            ->where(['integrationId' => $integrationId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        $counts = [DispatchStatus::SUCCESS => 0, DispatchStatus::FAILED => 0, DispatchStatus::PENDING => 0];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $last = $rows[0] ?? null;

        return [
            'total' => count($rows),
            'success' => $counts[DispatchStatus::SUCCESS],
            'failed' => $counts[DispatchStatus::FAILED],
            'pending' => $counts[DispatchStatus::PENDING],
            'lastStatus' => $last['status'] ?? null,
            'lastDispatchedAt' => $last['dateCreated'] ?? null,
            'lastResponseCode' => ($last !== null && $last['responseCode'] !== null) ? (int) $last['responseCode'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToModel(array $row): IntegrationModel
    {
        $model = new IntegrationModel();
        $model->id = (int) $row['id'];
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
