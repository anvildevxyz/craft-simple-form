<?php

namespace fabianhaef\simpleform\elements;

use craft\base\Element;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\db\SubmissionQuery;

class Submission extends Element
{
    public ?int $formId = null;
    public ?array $data = null;
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
        if ($this->formId === null) {
            return null;
        }
        return Form::find()->id($this->formId)->one();
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'form' => ['label' => 'Form'],
            'dateCreated' => ['label' => 'Date'],
            'readStatus' => ['label' => 'Status'],
            'userId' => ['label' => 'User'],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['form', 'dateCreated', 'readStatus'];
    }

    protected static function defineSources(string $context = null): array
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
