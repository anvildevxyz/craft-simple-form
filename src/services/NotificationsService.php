<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\ConditionalEvaluator;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Per-form email notifications (#112): CRUD plus resolution of which
 * notifications fire for a given submission (condition gating + recipient
 * resolution, including autoresponders that read the submitter's email field).
 */
class NotificationsService extends Component
{
    private const TABLE = '{{%simpleform_notifications}}';

    /**
     * @return list<NotificationModel>
     */
    public function getForForm(int $formId): array
    {
        $rows = (new Query())
            ->from(self::TABLE)
            ->where(['formId' => $formId])
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map([$this, 'rowToModel'], $rows);
    }

    public function getById(int $id): ?NotificationModel
    {
        $row = (new Query())->from(self::TABLE)->where(['id' => $id])->one();
        return $row ? $this->rowToModel($row) : null;
    }

    public function save(NotificationModel $notification): bool
    {
        if (!$notification->validate()) {
            return false;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        $attrs = [
            'formId' => $notification->formId,
            'name' => $notification->name,
            'enabled' => $notification->enabled,
            'recipientType' => $notification->recipientType,
            'recipient' => $notification->recipient,
            'subject' => $notification->subject,
            'replyTo' => $notification->replyTo,
            'body' => $notification->body,
            'conditional' => $notification->conditional,
            'sortOrder' => $notification->sortOrder,
            'dateUpdated' => $now,
        ];

        $isNew = $notification->id === null;
        if ($isNew) {
            $notification->uid = StringHelper::UUID();
            $db->createCommand()->insert(self::TABLE, $attrs + [
                'dateCreated' => $now,
                'uid' => $notification->uid,
            ])->execute();
            $notification->id = (int) $db->getLastInsertID();
        } else {
            $db->createCommand()->update(self::TABLE, $attrs, ['id' => $notification->id])->execute();
        }

        Plugin::getInstance()->getAudit()->log(
            $isNew ? 'notification.create' : 'notification.save',
            'notification',
            $notification->id,
            sprintf('%s → %s', $notification->name, $notification->recipient),
        );

        return true;
    }

    public function delete(int $id): bool
    {
        $deleted = (int) Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id])
            ->execute() > 0;
        if ($deleted) {
            Plugin::getInstance()->getAudit()->log('notification.delete', 'notification', $id);
        }
        return $deleted;
    }

    public function toggle(int $id): ?bool
    {
        $notification = $this->getById($id);
        if ($notification === null) {
            return null;
        }
        $notification->enabled = !$notification->enabled;
        $this->save($notification);
        return $notification->enabled;
    }

    /**
     * The notifications that should fire for a submission: enabled, condition
     * satisfied, and resolving to at least one recipient. Returns each model
     * paired with its resolved recipient list.
     *
     * @param array<string, mixed> $data submission data keyed by field_<id>
     * @return list<array{notification: NotificationModel, recipients: list<string>}>
     */
    public function resolveForSubmission(Form $form, Submission $submission, array $data): array
    {
        $valuesByHandle = $this->valuesByHandle((int) $form->id, (int) $submission->siteId, $data);
        $resolved = [];

        foreach ($this->getForForm((int) $form->id) as $notification) {
            if (!$notification->enabled) {
                continue;
            }
            if (!$this->conditionPasses($notification, $valuesByHandle)) {
                continue;
            }
            $recipients = $this->resolveRecipients($notification, $valuesByHandle);
            if ($recipients === []) {
                continue;
            }
            $resolved[] = ['notification' => $notification, 'recipients' => $recipients];
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $valuesByHandle
     */
    private function conditionPasses(NotificationModel $notification, array $valuesByHandle): bool
    {
        if (!is_array($notification->conditional) || empty($notification->conditional['enabled'])) {
            return true;
        }

        return ConditionalEvaluator::isVisible(['conditional' => $notification->conditional], $valuesByHandle);
    }

    /**
     * @param array<string, mixed> $valuesByHandle
     * @return list<string>
     */
    private function resolveRecipients(NotificationModel $notification, array $valuesByHandle): array
    {
        if ($notification->recipientType === NotificationModel::RECIPIENT_FIELD) {
            $value = $valuesByHandle[$notification->recipient] ?? null;
            $email = is_array($value) ? reset($value) : $value;
            $email = is_string($email) ? trim($email) : '';
            return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? [$email] : [];
        }

        // Fixed: split on comma/semicolon/whitespace, keep valid addresses.
        $parts = preg_split('/[\s,;]+/', $notification->recipient) ?: [];
        $addresses = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $addresses[] = $part;
            }
        }
        return array_values(array_unique($addresses));
    }

    /**
     * Build a field-handle => value map from the submission data, so notification
     * conditions (which reference field handles) and field-based recipients can
     * be resolved.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function valuesByHandle(int $formId, int $siteId, array $data): array
    {
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet($formId, $siteId);
        $handleById = [];
        foreach ($fields as $field) {
            $handleById['field_' . $field['id']] = (string) $field['name'];
        }

        $values = [];
        foreach ($data as $key => $entry) {
            $handle = $handleById[$key] ?? null;
            if ($handle !== null) {
                $values[$handle] = is_array($entry) ? ($entry['value'] ?? null) : $entry;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToModel(array $row): NotificationModel
    {
        $model = new NotificationModel();
        $model->id = (int) $row['id'];
        $model->formId = (int) $row['formId'];
        $model->name = (string) $row['name'];
        $model->enabled = (bool) $row['enabled'];
        $model->recipientType = (string) $row['recipientType'];
        $model->recipient = (string) $row['recipient'];
        $model->subject = $row['subject'] !== null ? (string) $row['subject'] : null;
        $model->replyTo = $row['replyTo'] !== null ? (string) $row['replyTo'] : null;
        $model->body = $row['body'] !== null ? (string) $row['body'] : null;
        $conditional = $row['conditional'] ?? null;
        if (is_array($conditional)) {
            $model->conditional = $conditional;
        } elseif (is_string($conditional) && $conditional !== '') {
            $decoded = Json::decodeIfJson($conditional);
            $model->conditional = is_array($decoded) ? $decoded : null;
        } else {
            $model->conditional = null;
        }
        $model->sortOrder = $row['sortOrder'] !== null ? (int) $row['sortOrder'] : null;
        $model->uid = $row['uid'] ?? null;
        return $model;
    }
}
