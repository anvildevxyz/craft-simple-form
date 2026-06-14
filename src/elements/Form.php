<?php

namespace fabianhaef\simpleform\elements;

use craft\base\Element;
use craft\elements\db\ElementQuery;

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

    public function __toString(): string
    {
        return $this->title ?? $this->name ?? '';
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['name', 'handle', 'title'];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => 'Title'],
            'handle' => ['label' => 'Handle'],
            'emailTo' => ['label' => 'Email To'],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'handle', 'emailTo'];
    }
}
