<?php

namespace fabianhaef\simpleform\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;

/**
 * @extends ElementQuery<int, Submission>
 *
 * @method Submission[] all($db = null)
 * @method Submission|null one($db = null)
 * @method Submission|null nth(int $n, $db = null)
 */
class SubmissionQuery extends ElementQuery
{
    public ?int $formId = null;
    public ?string $readStatus = null;
    public mixed $userId = null;

    public function form(Form|string|null $value = null): static
    {
        if ($value instanceof Form) {
            $this->formId = $value->id;
        } elseif ($value !== null) {
            // Try to get form by handle
            $form = Form::find()
                ->handle($value)
                ->one();
            if ($form) {
                $this->formId = $form->id;
            }
        }
        return $this;
    }

    public function formId(?int $value = null): static
    {
        $this->formId = $value;
        return $this;
    }

    public function readStatus(?string $value = null): static
    {
        $this->readStatus = $value;
        return $this;
    }

    /**
     * Filter submissions by the associated user id. Accepts any value
     * {@see Db::parseParam()} understands (int, list, ':empty:', etc.).
     */
    public function userId(mixed $value = null): static
    {
        $this->userId = $value;
        return $this;
    }

    public function status($value = null): static
    {
        return $this->readStatus(is_array($value) ? ($value[0] ?? null) : $value);
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('simpleform_submissions');

        $this->query->select([
            'simpleform_submissions.formId',
            'simpleform_submissions.data',
            'simpleform_submissions.userId',
            'simpleform_submissions.readStatus',
            'simpleform_submissions.spamReason',
            'simpleform_submissions.paymentStatus',
            'simpleform_submissions.paymentAmount',
            'simpleform_submissions.orderId',
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

        if ($this->userId !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_submissions.userId', $this->userId)
            );
        }

        return parent::beforePrepare();
    }
}
