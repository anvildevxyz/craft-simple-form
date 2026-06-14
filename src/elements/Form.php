<?php

namespace fabianhaef\simpleform\elements;

use Craft;
use craft\base\Element;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\db\FormQuery;
use fabianhaef\simpleform\traits\HasPropagation;

class Form extends Element
{
    use HasPropagation;

    // Shared across sites
    public ?string $name = null;
    public ?string $handle = null;

    // Per-site (translatable). title is stored in elements_sites via hasTitles().
    public ?string $title = null;
    public ?string $description = null;
    public ?string $emailTo = null;
    public ?string $emailSubject = null;
    public ?string $emailReplyTo = null;

    public static function displayName(): string
    {
        return 'Form';
    }

    public static function tableName(): string
    {
        return 'simpleform_forms';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function find(): FormQuery
    {
        return new FormQuery(static::class);
    }

    public function __toString(): string
    {
        return $this->title ?? $this->name ?? '';
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['name', 'handle', 'title', 'description'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => 'Title'],
            'handle' => ['label' => 'Handle'],
            'emailTo' => ['label' => 'Email To'],
            'dateCreated' => ['label' => 'Date Created'],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'handle', 'emailTo', 'dateCreated'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function defineSources(?string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => 'All Forms',
            ],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['name', 'handle'], 'string', 'max' => 255];
        $rules[] = [['title', 'description'], 'string'];
        $rules[] = [['emailTo', 'emailSubject', 'emailReplyTo'], 'string', 'max' => 255];

        // handle is shared across sites, so it must be globally unique
        $rules[] = [['handle'], 'validateHandleUnique'];

        return $rules;
    }

    public function validateHandleUnique(string $attribute): void
    {
        if (empty($this->handle)) {
            return;
        }

        $query = (new \craft\db\Query())
            ->from('{{%simpleform_forms}}')
            ->where(['handle' => $this->handle]);

        if ($this->id) {
            $query->andWhere(['not', ['id' => $this->id]]);
        }

        if ($query->exists()) {
            $this->addError($attribute, Craft::t('simple-form', 'This handle is already in use.'));
        }
    }

    public function afterSave(bool $isNew): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        // (a) SHARED row in simpleform_forms — keyed by element id, written once (canonical save only)
        if (!$this->propagating) {
            $shared = [
                'handle' => $this->handle,
                'name' => $this->name,
                'propagationMethod' => $this->propagationMethod->value,
                'dateUpdated' => $now,
            ];

            $exists = (new \craft\db\Query())
                ->from('{{%simpleform_forms}}')
                ->where(['id' => $this->id])
                ->exists();

            if (!$exists) {
                $db->createCommand()->insert('{{%simpleform_forms}}', $shared + [
                    'id' => $this->id,
                    'dateCreated' => $now,
                    'uid' => StringHelper::UUID(),
                ])->execute();
            } else {
                $db->createCommand()->update('{{%simpleform_forms}}', $shared, ['id' => $this->id])->execute();
            }
        }

        // (b) PER-SITE row in simpleform_forms_sites — translatable content (title lives in
        // elements_sites). The content is per-site, so we must NOT let propagation clobber a
        // sibling site's existing translation:
        //   - canonical save (the edited site): upsert this site's values.
        //   - propagating save (Craft copying to sibling sites): only SEED a row if one is
        //     missing; preserve any existing translation.
        $siteRow = [
            'description' => $this->description,
            'emailTo' => $this->emailTo,
            'emailSubject' => $this->emailSubject,
            'emailReplyTo' => $this->emailReplyTo,
        ];

        $rowExists = (new \craft\db\Query())
            ->from('{{%simpleform_forms_sites}}')
            ->where(['formId' => $this->id, 'siteId' => $this->siteId])
            ->exists();

        if (!$rowExists) {
            $db->createCommand()->insert('{{%simpleform_forms_sites}}', $siteRow + [
                'formId' => $this->id,
                'siteId' => $this->siteId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } elseif (!$this->propagating) {
            // Only the directly-edited site updates an existing row.
            $db->createCommand()->update('{{%simpleform_forms_sites}}', $siteRow + [
                'dateUpdated' => $now,
            ], ['formId' => $this->id, 'siteId' => $this->siteId])->execute();
        }

        parent::afterSave($isNew);
    }

    // simpleform_forms (and its cascades) is removed automatically when the element row is deleted
    // via the id -> elements.id foreign key, so no explicit beforeDelete cleanup is required.
}
