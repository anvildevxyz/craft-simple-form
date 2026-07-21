<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\events\BeforeIntegrationDispatchEvent;
use anvildev\simpleform\integrations\DispatchStatus;
use anvildev\simpleform\integrations\IntegrationResult;
use anvildev\simpleform\jobs\SendIntegrationJob;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
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
     * Settings keys holding third-party secrets, encrypted at rest (F4) and
     * redacted on portability export (#139). Public so the portability service
     * shares this single source of truth rather than re-listing secret keys.
     *
     * @var list<string>
     */
    public const SECRET_KEYS = ['apiKey', 'apiToken', 'secret', 'token', 'serviceAccountKey', 'refreshToken', 'clientSecret'];

    /** Placeholder written in place of a secret in a portability export (#139). */
    public const REDACTED = '__REDACTED__';

    /** Marks a settings value as ciphertext produced by {@see encryptSettings()}. */
    private const ENC_PREFIX = 'sfenc:';

    /**
     * Every integration definition (the global Settings index), ordered by
     * sortOrder then id.
     *
     * @return list<IntegrationModel>
     */
    public function getAllIntegrations(): array
    {
        return array_map($this->rowToModel(...), (new \craft\db\Query())
            ->from(self::TABLE)
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all());
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
        return array_map($this->rowToModel(...), (new \craft\db\Query())
            ->select(['i.*'])
            ->from(['i' => self::TABLE])
            ->innerJoin(['fi' => self::PIVOT_TABLE], '[[fi.integrationId]] = [[i.id]]')
            ->where(['fi.formId' => $formId])
            ->orderBy(['i.sortOrder' => SORT_ASC, 'i.id' => SORT_ASC])
            ->all());
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
            // fields' `config` is stored). Secret keys are encrypted at rest (F4).
            'settings' => $this->encryptSettings($integration->settings),
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
            $isNew ? AuditService::ACTION_INTEGRATION_CREATE : AuditService::ACTION_INTEGRATION_SAVE,
            AuditService::TARGET_INTEGRATION,
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
        // Withhold dispatch while the submission is awaiting payment; it fires
        // again from PaymentsService::markPaid() once the order completes.
        if ($submission->isAwaitingPayment()) {
            return;
        }

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

        if (($type = Plugin::getInstance()->getIntegrationTypeRegistry()->getType($integration->type)) === null) {
            $message = "Unknown integration type: {$integration->type}";
            $this->logDispatch($integrationId, $submissionId, DispatchStatus::FAILED, $attempt, null, $message);
            return IntegrationResult::failure(null, $message);
        }

        $settings = $this->parseEnvSettings($integration->settings);
        // Expose the dispatching integration id to connectors that need it (e.g.
        // the element connector's resend-idempotency lookup). Underscore-prefixed
        // so it never collides with a real setting.
        $settings['__integrationId'] = $integrationId;

        // Let third parties adjust settings or skip this dispatch. A skip is
        // logged as a successful no-op so it is not retried.
        $plugin = Plugin::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(Plugin::EVENT_BEFORE_INTEGRATION_DISPATCH)) {
            $event = new BeforeIntegrationDispatchEvent([
                'integration' => $integration,
                'submission' => $submission,
                'settings' => $settings,
            ]);
            $plugin->trigger(Plugin::EVENT_BEFORE_INTEGRATION_DISPATCH, $event);
            if (!$event->send) {
                $message = 'Skipped by EVENT_BEFORE_INTEGRATION_DISPATCH';
                $this->logDispatch($integrationId, $submissionId, DispatchStatus::SUCCESS, $attempt, null, $message);
                return IntegrationResult::success(null, $message);
            }
            $settings = $event->settings;
        }

        try {
            $result = $type->send($submission, $settings);
        } catch (\Throwable $e) {
            $this->logDispatch($integrationId, $submissionId, DispatchStatus::FAILED, $attempt, null, $this->scrubSecrets($e->getMessage(), $settings));
            return IntegrationResult::failure(null, $e->getMessage());
        }

        $this->logDispatch(
            $integrationId,
            $submissionId,
            $result->success ? DispatchStatus::SUCCESS : DispatchStatus::FAILED,
            $attempt,
            $result->responseCode,
            // F7: a remote error body may echo our own secret — redact it before
            // it is written to the diagnostic log.
            $this->scrubSecrets($result->message, $settings),
            $result->elementId,
            $result->elementType,
        );

        return $result;
    }

    /**
     * The element a previous successful dispatch of this integration created for
     * this submission, if any. Used by the element connector for resend
     * idempotency so a re-queued dispatch links the existing element rather than
     * silently creating a duplicate (#142).
     *
     * @return array{id: int, type: string}|null
     */
    public function getLinkedElement(int $integrationId, int $submissionId): ?array
    {
        $row = (new \craft\db\Query())
            ->select(['elementId', 'elementType'])
            ->from(self::LOG_TABLE)
            ->where([
                'integrationId' => $integrationId,
                'submissionId' => $submissionId,
                'status' => DispatchStatus::SUCCESS,
            ])
            ->andWhere(['not', ['elementId' => null]])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if ($row === null || $row['elementId'] === null || $row['elementType'] === null) {
            return null;
        }

        return ['id' => (int) $row['elementId'], 'type' => (string) $row['elementType']];
    }

    /**
     * Validate a settings array against a connector's own rules.
     *
     * @param array<string, mixed> $settings
     * @return array<string, array<int, string>> attribute => errors (empty if valid)
     */
    public function validateSettings(\anvildev\simpleform\integrations\IntegrationTypeInterface $type, array $settings): array
    {
        $rules = $type->defineSettingsRules();

        // DynamicModel must know every attribute referenced by a rule.
        $attributes = $settings;
        foreach ($rules as $rule) {
            foreach ((array) ($rule[0] ?? []) as $attr) {
                $attributes[$attr] ??= null;
            }
        }

        $model = new \yii\base\DynamicModel($attributes);
        foreach ($rules as $rule) {
            // Named options only: the positional 0/1 entries are the attributes + validator.
            $options = array_filter($rule, static fn($key): bool => !is_int($key), ARRAY_FILTER_USE_KEY);
            $model->addRule((array) ($rule[0] ?? []), $rule[1] ?? 'safe', $options);
        }

        return $model->validate() ? [] : $model->getErrors();
    }

    public function deleteIntegration(int $id): bool
    {
        $count = Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id])
            ->execute();
        if ($count > 0) {
            Plugin::getInstance()->getAudit()->log(AuditService::ACTION_INTEGRATION_DELETE, AuditService::TARGET_INTEGRATION, $id);
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
        ?int $elementId = null,
        ?string $elementType = null,
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
            // The local Craft element this attempt created, if any (#142).
            'elementId' => $elementId,
            'elementType' => $elementType,
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
     * Encrypt secret settings keys before they are persisted (F4, CWE-312).
     * Without this, API keys / tokens / signing secrets sit in the database as
     * cleartext, so any DB read or stray backup hands an attacker every
     * third-party credential. Values that are empty, environment references
     * ($VAR), or already encrypted are left untouched. Encryption is skipped
     * only when no securityKey is configured (in which case behaviour is
     * unchanged from before — production always has a securityKey).
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function encryptSettings(array $settings): array
    {
        $key = (string) Craft::$app->getConfig()->getGeneral()->securityKey;
        if ($key === '') {
            return $settings;
        }

        $security = Craft::$app->getSecurity();
        foreach (self::SECRET_KEYS as $secretKey) {
            $value = $settings[$secretKey] ?? null;
            if (is_string($value) && $value !== ''
                && !str_starts_with($value, '$')
                && !str_starts_with($value, self::ENC_PREFIX)) {
                $settings[$secretKey] = self::ENC_PREFIX . base64_encode($security->encryptByKey($value, $key));
            }
        }

        return $settings;
    }

    /**
     * Redact secret settings for display on the edit screen: a stored literal
     * secret is blanked so it is never echoed back into an input, while an
     * environment reference ($VAR) is kept — it's safe and the operator needs to
     * see and edit it. Pairs with {@see preserveBlankSecrets()} so a blanked
     * field left untouched on save keeps its stored value rather than wiping it
     * (#429).
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function redactSecretsForDisplay(array $settings): array
    {
        foreach (self::SECRET_KEYS as $secretKey) {
            $value = $settings[$secretKey] ?? null;
            if (is_string($value) && $value !== '' && !str_starts_with($value, '$')) {
                $settings[$secretKey] = '';
            }
        }

        return $settings;
    }

    /**
     * Write-only counterpart to {@see redactSecretsForDisplay()}: a secret field
     * left blank on save keeps its previously-stored value instead of wiping it,
     * so editing other fields doesn't clear a masked secret. A non-blank posted
     * secret replaces the stored one as usual (#429).
     *
     * @param array<string, mixed> $posted
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    public function preserveBlankSecrets(array $posted, array $existing): array
    {
        foreach (self::SECRET_KEYS as $secretKey) {
            $postedValue = $posted[$secretKey] ?? null;
            $storedValue = $existing[$secretKey] ?? null;
            if ((!is_string($postedValue) || trim($postedValue) === '')
                && is_string($storedValue) && $storedValue !== '') {
                $posted[$secretKey] = $storedValue;
            }
        }

        return $posted;
    }

    /**
     * Normalise a raw `settings` column value into an array. Craft's json column
     * returns a JSON string on MySQL/MariaDB and may return an already-decoded
     * array on Postgres; both (and empty/null) collapse to an array here.
     *
     * @return array<string, mixed>
     */
    private function normalizeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '' && is_array($decoded = Json::decodeIfJson($raw))) {
            return $decoded;
        }
        return [];
    }

    /**
     * Inverse of {@see encryptSettings()}. Marked values are decrypted; legacy
     * plaintext values (no marker) pass through unchanged for backward
     * compatibility. A value that cannot be decrypted (e.g. after a key change)
     * is logged and blanked rather than leaked or fatally thrown.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function decryptSettings(array $settings): array
    {
        $key = (string) Craft::$app->getConfig()->getGeneral()->securityKey;
        $security = Craft::$app->getSecurity();

        foreach (self::SECRET_KEYS as $secretKey) {
            $value = $settings[$secretKey] ?? null;
            if (!is_string($value) || !str_starts_with($value, self::ENC_PREFIX)) {
                continue;
            }

            $cipher = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
            if ($cipher === false || $key === '') {
                $settings[$secretKey] = '';
                continue;
            }

            try {
                $settings[$secretKey] = $security->decryptByKey($cipher, $key);
            } catch (\Throwable $e) {
                Craft::warning('Failed to decrypt integration secret "' . $secretKey . '": ' . $e->getMessage(), 'simple-form');
                $settings[$secretKey] = '';
            }
        }

        return $settings;
    }

    /**
     * Redact the integration's own resolved secret values from a log/error
     * message (F7, CWE-532) so a remote response that echoes a key/token never
     * lands in the dispatch log.
     *
     * @param array<string, mixed> $settings env-resolved settings
     */
    private function scrubSecrets(string $message, array $settings): string
    {
        foreach (self::SECRET_KEYS as $key) {
            $value = $settings[$key] ?? null;
            // Redact from 4 chars up: short signing secrets are still secrets, and
            // a 4-char over-redaction in a log line is preferable to leaking one.
            if (is_string($value) && strlen($value) >= 4) {
                $message = str_replace($value, '[redacted]', $message);
            }
        }

        return $message;
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
            if (isset($counts[$status = (string) $row['status']])) {
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
     * The dead-letter queue: every integration+submission pair whose MOST RECENT
     * dispatch attempt failed. Self-clearing — a later successful attempt (e.g. a
     * resend) makes the latest row a success, so the pair drops off the list.
     * Newest first. Rows are enriched with the integration + form names for
     * display; a missing integration/submission (deleted, GC'd) is tolerated.
     *
     * @return list<array<string, mixed>>
     */
    public function getFailedDispatches(int $limit = 200): array
    {
        $latestIds = $this->latestLogIdsQuery();

        $rows = (new \craft\db\Query())
            ->from(['l' => self::LOG_TABLE])
            ->where(['l.id' => $latestIds])
            ->andWhere(['l.status' => DispatchStatus::FAILED])
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit($limit)
            ->all();

        if ($rows === []) {
            return [];
        }

        // Resolve integration names once (small set).
        $integrationNames = (new \craft\db\Query())
            ->select(['id', 'name', 'type'])
            ->from(self::TABLE)
            ->indexBy('id')
            ->all();

        // Batch-load the referenced submissions in one query instead of one per row.
        $submissionIds = [];
        foreach ($rows as $row) {
            if ($row['submissionId'] !== null) {
                $submissionIds[(int) $row['submissionId']] = true;
            }
        }
        $submissions = $submissionIds === []
            ? []
            : Submission::find()->id(array_keys($submissionIds))->indexBy('id')->all();

        $out = [];
        foreach ($rows as $row) {
            $submission = $row['submissionId'] !== null
                ? ($submissions[(int) $row['submissionId']] ?? null)
                : null;
            $form = $submission?->getForm();
            $integration = $integrationNames[$row['integrationId']] ?? null;

            $out[] = [
                'integrationId' => (int) $row['integrationId'],
                'integrationName' => $integration['name'] ?? Craft::t('simple-form', '(deleted integration)'),
                'integrationType' => $integration['type'] ?? null,
                'submissionId' => $row['submissionId'] !== null ? (int) $row['submissionId'] : null,
                'formName' => $form?->title ?? $form?->name,
                'attempts' => (int) $row['attempts'],
                'responseCode' => $row['responseCode'] !== null ? (int) $row['responseCode'] : null,
                'message' => (string) $row['message'],
                'dateCreated' => $row['dateCreated'],
            ];
        }

        return $out;
    }

    /**
     * How many integration+submission pairs currently have a failed latest
     * dispatch (the dead-letter count). See {@see getFailedDispatches()}.
     */
    public function countFailedDispatches(): int
    {
        $latestIds = $this->latestLogIdsQuery();

        return (int) (new \craft\db\Query())
            ->from(['l' => self::LOG_TABLE])
            ->where(['l.id' => $latestIds])
            ->andWhere(['l.status' => DispatchStatus::FAILED])
            ->count();
    }

    /**
     * The latest log id per (integrationId, submissionId).
     *
     * @return \craft\db\Query<int, array<string, mixed>>
     */
    private function latestLogIdsQuery(): \craft\db\Query
    {
        return (new \craft\db\Query())
            ->select(['mid' => 'MAX(id)'])
            ->from(self::LOG_TABLE)
            ->groupBy(['integrationId', 'submissionId']);
    }

    /**
     * Replace every secret-key value in a settings array with {@see self::REDACTED}
     * so an integration reference can travel in a portability export (#139) without
     * carrying any third-party credential. Non-secret keys pass through untouched;
     * empty secrets are still redacted so their presence/shape never leaks.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function redactSecrets(array $settings): array
    {
        foreach (self::SECRET_KEYS as $secretKey) {
            if (array_key_exists($secretKey, $settings)) {
                $settings[$secretKey] = self::REDACTED;
            }
        }
        return $settings;
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
        $model->settings = $this->decryptSettings($this->normalizeSettings($row['settings'] ?? null));
        $model->sortOrder = $row['sortOrder'] !== null ? (int) $row['sortOrder'] : null;
        $model->uid = $row['uid'] ?? null;
        return $model;
    }
}
