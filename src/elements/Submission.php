<?php

namespace fabianhaef\simpleform\elements;

use craft\base\Element;

class Submission extends Element
{
    public ?int $formId = null;
    public ?string $data = null;
    public ?int $userId = null;
    public string $readStatus = 'new';

    public static function displayName(): string
    {
        return 'Submission';
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return "Submission #{$this->id}";
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'dateCreated' => ['label' => 'Date'],
            'readStatus' => ['label' => 'Status'],
            'userId' => ['label' => 'User'],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['dateCreated', 'readStatus', 'userId'];
    }
}
