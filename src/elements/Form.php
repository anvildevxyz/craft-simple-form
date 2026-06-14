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

    protected static function defineSources(string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => 'All Forms',
            ],
        ];
    }
}
