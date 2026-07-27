<?php

namespace anvildev\simpleform\models;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\helpers\FieldQueryHelper;
use yii\base\Model;

class FormModel extends Model
{
    /** @var array<int, FieldModel> */
    private array $fields = [];

    public function __construct(private Form $form)
    {
        parent::__construct();
        // Use the form's own site so labels/help text match the loaded form's language.
        foreach (FieldQueryHelper::fieldsForForm((int)$form->id, $form->siteId) as $rawField) {
            $id = (int) $rawField['id'];
            $this->fields[$id] = new FieldModel(
                $id,
                $rawField['type'],
                $rawField['name'],
                $rawField['label'],
                $rawField['config'], // already decoded array
                $rawField['errorMessage'] ?? null // per-site validation message override
            );
        }
    }

    public function getId(): ?int
    {
        return $this->form->id;
    }

    /**
     * @return array<int, FieldModel>
     */
    public function getFields(): array
    {
        return $this->fields;
    }
}
