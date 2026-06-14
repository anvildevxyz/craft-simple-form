<?php

namespace fabianhaef\simpleform\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class FormQuery extends ElementQuery
{
    public ?string $handle = null;
    public ?string $name = null;

    public function handle(string $value = null): static
    {
        $this->handle = $value;
        return $this;
    }

    public function name(string $value = null): static
    {
        $this->name = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('simpleform_forms');

        $this->query->select([
            'simpleform_forms.name',
            'simpleform_forms.handle',
            'simpleform_forms.title',
            'simpleform_forms.description',
            'simpleform_forms.emailTo',
            'simpleform_forms.emailSubject',
            'simpleform_forms.emailReplyTo',
        ]);

        if ($this->handle !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_forms.handle', $this->handle)
            );
        }

        if ($this->name !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_forms.name', $this->name)
            );
        }

        return parent::beforePrepare();
    }
}
