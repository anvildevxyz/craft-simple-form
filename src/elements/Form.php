<?php

namespace fabianhaef\simpleform\elements;

use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use fabianhaef\simpleform\elements\db\FormQuery;
use yii\db\Expression;

class Form extends Element
{
    public ?string $name = null;
    public ?string $handle = null;
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

    protected static function defineSources(?string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => 'All Forms',
            ],
        ];
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['name', 'handle'], 'string', 'max' => 255];
        $rules[] = [['title', 'description'], 'string'];
        $rules[] = [['emailTo', 'emailSubject', 'emailReplyTo'], 'string', 'max' => 255];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $db = \Craft::$app->getDb();

            if ($isNew) {
                $db->createCommand()->insert('simpleform_forms', [
                    'id' => $this->id,
                    'siteId' => $this->siteId,
                    'name' => $this->name,
                    'handle' => $this->handle,
                    'title' => $this->title,
                    'description' => $this->description,
                    'emailTo' => $this->emailTo,
                    'emailSubject' => $this->emailSubject,
                    'emailReplyTo' => $this->emailReplyTo,
                ])->execute();
            } else {
                $db->createCommand()->update('simpleform_forms', [
                    'name' => $this->name,
                    'handle' => $this->handle,
                    'title' => $this->title,
                    'description' => $this->description,
                    'emailTo' => $this->emailTo,
                    'emailSubject' => $this->emailSubject,
                    'emailReplyTo' => $this->emailReplyTo,
                ], ['id' => $this->id])->execute();
            }
        }

        parent::afterSave($isNew);
    }

    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        \Craft::$app->getDb()->createCommand()
            ->delete('simpleform_forms', ['id' => $this->id])
            ->execute();

        return true;
    }
}
