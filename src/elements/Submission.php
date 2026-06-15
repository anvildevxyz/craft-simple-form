<?php

namespace fabianhaef\simpleform\elements;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\db\SubmissionQuery;

class Submission extends Element
{
    public ?int $formId = null;
    /** @var array<string, mixed>|null */
    public ?array $data = null;
    public ?int $userId = null;
    public string $readStatus = SubmissionStatus::NEW;

    public static function displayName(): string
    {
        return 'Submission';
    }

    public static function tableName(): string
    {
        return 'simpleform_submissions';
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function find(): SubmissionQuery
    {
        return new SubmissionQuery(static::class);
    }

    public function __toString(): string
    {
        return "Submission #{$this->id}";
    }

    public function getForm(): ?Form
    {
        if ($this->formId === null || $this->formId <= 0) {
            return null;
        }

        // Serve the eager-loaded form when the query was run with `.with(['form'])`,
        // avoiding a per-submission query.
        if ($this->hasEagerLoadedElements('form')) {
            $eager = $this->getEagerLoadedElements('form')?->first();
            return $eager instanceof Form ? $eager : null;
        }

        try {
            return Form::find()->id($this->formId)->one();
        } catch (\Throwable $e) {
            Craft::warning(sprintf('Error loading form %d: %s', $this->formId, $e->getMessage()), 'simple-form');
            return null;
        }
    }

    /**
     * Map the submission→form relationship so `Submission::find()->with(['form'])`
     * batch-loads parent forms in a bounded number of queries (Craft's standard
     * eager-loading mechanism).
     *
     * @param self[] $sourceElements
     * @return array<string,mixed>|null|false
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle === 'form') {
            $map = [];
            foreach ($sourceElements as $submission) {
                if ($submission->formId !== null && $submission->formId > 0) {
                    $map[] = ['source' => $submission->id, 'target' => $submission->formId];
                }
            }

            return [
                'elementType' => Form::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    /**
     * Persist the submission's custom columns. A Craft element only writes its
     * `elements`/`elements_sites` rows automatically; the plugin-owned row in
     * `simpleform_submissions` (formId, siteId, data, userId, readStatus) must be
     * written here, mirroring the pattern used by the Form element. Without this,
     * saveElement() creates an element row but no submission row, so the
     * SubmissionQuery (which INNER-joins simpleform_submissions) returns nothing.
     */
    public function afterSave(bool $isNew): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $row = [
            'formId' => $this->formId,
            'siteId' => $this->siteId,
            // Craft's json() column type encodes the array exactly once; pass the array.
            'data' => $this->data,
            'userId' => $this->userId,
            'readStatus' => $this->readStatus,
            'dateUpdated' => $now,
        ];

        $exists = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $this->id])
            ->exists();

        if (!$exists) {
            $db->createCommand()->insert('{{%simpleform_submissions}}', $row + [
                'id' => $this->id,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } else {
            $db->createCommand()->update('{{%simpleform_submissions}}', $row, ['id' => $this->id])->execute();
        }

        parent::afterSave($isNew);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'form' => ['label' => 'Form'],
            'dateCreated' => ['label' => 'Date'],
            'readStatus' => ['label' => 'Status'],
            'userId' => ['label' => 'User'],
        ];
    }

    /**
     * @return list<string>
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['form', 'dateCreated', 'readStatus'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => 'All Submissions',
            ],
        ];

        $forms = Form::find()->all();
        foreach ($forms as $form) {
            $sources[] = [
                'key' => 'form:' . $form->id,
                'label' => $form->title ?? $form->name,
                'criteria' => ['formId' => $form->id],
            ];
        }

        return $sources;
    }
}
