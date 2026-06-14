<?php

namespace fabianhaef\simpleform\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class SubmissionQuery extends ElementQuery
{
    public ?int $formId = null;
    public ?string $readStatus = null;

    public function form($value = null): static
    {
        if ($value instanceof \fabianhaef\simpleform\elements\Form) {
            $this->formId = $value->id;
        } else {
            // Try to get form by handle
            $form = \fabianhaef\simpleform\elements\Form::find()
                ->handle($value)
                ->one();
            if ($form) {
                $this->formId = $form->id;
            }
        }
        return $this;
    }

    public function formId($value = null): static
    {
        $this->formId = $value;
        return $this;
    }

    public function readStatus($value = null): static
    {
        $this->readStatus = $value;
        return $this;
    }

    public function status($value = null): static
    {
        return $this->readStatus($value);
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('simpleform_submissions');

        $this->query->select([
            'simpleform_submissions.formId',
            'simpleform_submissions.data',
            'simpleform_submissions.userId',
            'simpleform_submissions.readStatus',
        ]);

        if ($this->formId !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_submissions.formId', $this->formId)
            );
        }

        if ($this->readStatus !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_submissions.readStatus', $this->readStatus)
            );
        }

        return parent::beforePrepare();
    }
}
