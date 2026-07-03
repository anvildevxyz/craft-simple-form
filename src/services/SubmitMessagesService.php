<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\Editions;
use anvildev\simpleform\models\SubmitMessageModel;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * Conditional submit messages (#265): CRUD plus ordering against the two-table
 * submit-message schema — a shared structural row in
 * `{{%simpleform_submit_messages}}` (condition + priority) plus per-site
 * translatable message text in `{{%simpleform_submit_messages_sites}}`.
 *
 * Mirrors {@see NotificationsService} (multiple condition-gated rows attached to
 * a form, evaluated in order at submit time) and {@see FieldsService} (the
 * shared-vs-per-site split and reorder write).
 *
 * Adding a new row is a Pro capability gated by
 * {@see Editions::CAP_CONDITIONAL_LOGIC}, applying the no-new-escalation rule:
 * a downgraded Solo install keeps and can still edit/delete its stored rows, but
 * cannot create additional ones (an insert increases the count). Evaluation is
 * skipped entirely on Solo (see {@see SubmissionService::resolvePostSubmit()}),
 * so stored rows never error — they simply fall back to the default message.
 *
 * @author Fabian Haefliger
 * @since 2.14.0
 */
class SubmitMessagesService extends Component
{
    // Const Properties
    // =========================================================================

    private const TABLE = '{{%simpleform_submit_messages}}';
    private const SITES_TABLE = '{{%simpleform_submit_messages_sites}}';

    // Public Methods
    // =========================================================================

    /**
     * A form's submit messages in evaluation order, each with its full per-site
     * message map populated.
     *
     * @return list<SubmitMessageModel>
     */
    public function getForForm(int $formId): array
    {
        $rows = (new Query())
            ->from(self::TABLE)
            ->where(['formId' => $formId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $models = array_map($this->rowToModel(...), $rows);
        foreach ($models as $model) {
            $model->messages = $this->messagesFor((int) $model->id);
        }

        return $models;
    }

    /**
     * A form's submit messages in evaluation order, each with its {@see SubmitMessageModel::$message}
     * resolved to the given site (null when that site has no translation) — the
     * shape {@see SubmissionService::resolvePostSubmit()} evaluates.
     *
     * @return list<SubmitMessageModel>
     */
    public function getForFormAndSite(int $formId, int $siteId): array
    {
        $rows = (new Query())
            ->select(['m.*', 'ms.message'])
            ->from(['m' => self::TABLE])
            ->leftJoin(
                ['ms' => self::SITES_TABLE],
                ['and', '[[ms.submitMessageId]] = [[m.id]]', ['ms.siteId' => $siteId]],
            )
            ->where(['m.formId' => $formId])
            ->orderBy(['m.sortOrder' => SORT_ASC, 'm.id' => SORT_ASC])
            ->all();

        return array_map(function(array $row): SubmitMessageModel {
            $model = $this->rowToModel($row);
            $model->message = isset($row['message']) ? (string) $row['message'] : null;
            return $model;
        }, $rows);
    }

    public function getById(int $id): ?SubmitMessageModel
    {
        $row = (new Query())->from(self::TABLE)->where(['id' => $id])->one();
        if ($row === null) {
            return null;
        }

        $model = $this->rowToModel($row);
        $model->messages = $this->messagesFor($id);

        return $model;
    }

    /**
     * Persist a submit message: the shared structural row plus the per-site
     * message rows from {@see SubmitMessageModel::$messages}. Creating a new row
     * requires the Pro conditional-logic capability; editing an existing row is
     * always allowed so a downgraded install stays operable.
     */
    public function save(SubmitMessageModel $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $isNew = $model->id === null;

        // No-new-escalation: an insert increases the form's row count, which is a
        // Pro capability; an update to an existing row does not.
        if ($isNew && !Editions::can(Editions::CAP_CONDITIONAL_LOGIC)) {
            $model->addError('conditional', Craft::t(
                'simple-form',
                'Conditional submit messages are a Pro feature.',
            ));
            return false;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->transaction(function() use ($db, $model, $isNew, $now): void {
            $attrs = [
                'formId' => $model->formId,
                'conditional' => $model->conditional,
                'sortOrder' => $model->sortOrder ?? $this->nextSortOrder((int) $model->formId),
                'dateUpdated' => $now,
            ];

            if ($isNew) {
                $model->uid = StringHelper::UUID();
                $db->createCommand()->insert(self::TABLE, $attrs + [
                    'dateCreated' => $now,
                    'uid' => $model->uid,
                ])->execute();
                $model->id = (int) $db->getLastInsertID();
                $model->sortOrder = (int) $attrs['sortOrder'];
            } else {
                $db->createCommand()->update(self::TABLE, $attrs, ['id' => $model->id])->execute();
            }

            foreach ($model->messages as $siteId => $message) {
                $db->createCommand()->upsert(self::SITES_TABLE, [
                    'submitMessageId' => $model->id,
                    'siteId' => (int) $siteId,
                    'message' => $message,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ], [
                    'message' => $message,
                    'dateUpdated' => $now,
                ])->execute();
            }
        });

        return true;
    }

    /**
     * Delete a submit message's structural row; the per-site rows cascade via FK.
     */
    public function delete(int $id): bool
    {
        return (int) Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id])
            ->execute() > 0;
    }

    /**
     * Transactionally rewrite submit-message sort order to match the given id
     * order (1-based by position), constrained to the form so a stray id from
     * another form can't be moved.
     *
     * @param list<int> $orderedIds the submit-message ids in their new order
     */
    public function reorder(array $orderedIds, int $formId): void
    {
        $orderedIds = array_values($orderedIds);
        if ($orderedIds === []) {
            return;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->transaction(function() use ($db, $orderedIds, $formId, $now): void {
            foreach ($orderedIds as $index => $id) {
                $db->createCommand()->update(self::TABLE, [
                    'sortOrder' => $index + 1,
                    'dateUpdated' => $now,
                ], ['id' => $id, 'formId' => $formId])->execute();
            }
        });
    }

    // Private Methods
    // =========================================================================

    /**
     * The per-site message map for a submit message, keyed by site id.
     *
     * @return array<int, string>
     */
    private function messagesFor(int $submitMessageId): array
    {
        $rows = (new Query())
            ->select(['siteId', 'message'])
            ->from(self::SITES_TABLE)
            ->where(['submitMessageId' => $submitMessageId])
            ->all();

        $messages = [];
        foreach ($rows as $row) {
            $messages[(int) $row['siteId']] = (string) ($row['message'] ?? '');
        }

        return $messages;
    }

    /**
     * The next sort-order value for a form (one past the current max).
     */
    private function nextSortOrder(int $formId): int
    {
        // [[...]]-quote the column: Yii interpolates the max() argument raw, so an
        // unquoted camelCase identifier is case-folded to "sortorder" on Postgres.
        $max = (new Query())
            ->from(self::TABLE)
            ->where(['formId' => $formId])
            ->max('[[sortOrder]]');

        return (int) $max + 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToModel(array $row): SubmitMessageModel
    {
        $model = new SubmitMessageModel();
        $model->id = (int) $row['id'];
        $model->formId = (int) $row['formId'];
        $conditional = $row['conditional'] ?? null;
        if (is_array($conditional)) {
            $model->conditional = $conditional;
        } elseif (is_string($conditional) && $conditional !== '') {
            $decoded = Json::decodeIfJson($conditional);
            $model->conditional = is_array($decoded) ? $decoded : null;
        } else {
            $model->conditional = null;
        }
        $model->sortOrder = isset($row['sortOrder']) ? (int) $row['sortOrder'] : null;
        $model->uid = $row['uid'] ?? null;

        return $model;
    }
}
