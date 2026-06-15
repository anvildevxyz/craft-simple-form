<?php

namespace fabianhaef\simpleform\models;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use yii\base\Model;

class FormModel extends Model
{
    private Form $form;
    /** @var array<int, FieldModel> */
    private array $fields = [];

    public function __construct(Form $form)
    {
        parent::__construct();
        $this->form = $form;
        $this->loadFields();
    }

    private function loadFields(): void
    {
        // Use the form's own site so labels/help text match the loaded form's language.
        $rawFields = FieldQueryHelper::fieldsForForm((int)$this->form->id, $this->form->siteId);

        foreach ($rawFields as $rawField) {
            $this->fields[$rawField['id']] = new FieldModel(
                (int) $rawField['id'],
                $rawField['type'],
                $rawField['name'],
                $rawField['label'],
                $rawField['config'] // already decoded array
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
