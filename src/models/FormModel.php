<?php

namespace fabianhaef\simpleform\models;

use Craft;
use fabianhaef\simpleform\elements\Form;
use yii\base\Model;

class FormModel extends Model
{
    private Form $form;
    private array $fields = [];

    public function __construct(Form $form)
    {
        parent::__construct();
        $this->form = $form;
        $this->loadFields();
    }

    private function loadFields(): void
    {
        $db = Craft::$app->getDb();
        $rawFields = $db->createCommand(
            'SELECT id, type, name, label, helpText, config FROM {{%simpleform_fields}} WHERE formId = :formId ORDER BY sortOrder ASC'
        )
            ->bindValues([':formId' => $this->form->id])
            ->queryAll();

        foreach ($rawFields as $rawField) {
            $this->fields[$rawField['id']] = new FieldModel(
                (int) $rawField['id'],
                $rawField['type'],
                $rawField['name'],
                $rawField['label'],
                $rawField['helpText'] ?? '',
                $rawField['config'] ? json_decode($rawField['config'], true) : []
            );
        }
    }

    public function getId(): ?int
    {
        return $this->form->id;
    }

    public function getTitle(): ?string
    {
        return $this->form->title;
    }

    public function getDescription(): ?string
    {
        return $this->form->description;
    }

    public function getName(): ?string
    {
        return $this->form->name;
    }

    public function getHandle(): ?string
    {
        return $this->form->handle;
    }

    public function getEmailTo(): ?string
    {
        return $this->form->emailTo;
    }

    public function getEmailSubject(): ?string
    {
        return $this->form->emailSubject;
    }

    public function getConfig(): array
    {
        return [
            'id' => $this->form->id,
            'name' => $this->form->name,
            'handle' => $this->form->handle,
            'title' => $this->form->title,
            'description' => $this->form->description,
            'emailTo' => $this->form->emailTo,
            'emailSubject' => $this->form->emailSubject,
        ];
    }

    public function getFields(): array
    {
        return $this->fields;
    }

    public function getField(int $id): ?FieldModel
    {
        return $this->fields[$id] ?? null;
    }
}
