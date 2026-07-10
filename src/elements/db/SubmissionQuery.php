<?php

namespace anvildev\simpleform\elements\db;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;

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
    public mixed $workflowStatus = null;
    public mixed $userId = null;
    public mixed $paymentStatus = null;
    public mixed $orderId = null;

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
     * Filter submissions by approval-workflow stage handle (#248). Accepts any
     * value {@see Db::parseParam()} understands.
     */
    public function workflowStatus(mixed $value = null): static
    {
        $this->workflowStatus = $value;
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

    /**
     * Filter submissions by Commerce payment status. Accepts any value
     * {@see Db::parseParam()} understands (string, list, ':empty:', etc.).
     */
    public function paymentStatus(mixed $value = null): static
    {
        $this->paymentStatus = $value;
        return $this;
    }

    /**
     * Filter submissions by the linked Commerce order id. Accepts any value
     * {@see Db::parseParam()} understands (int, list, ':notempty:', etc.).
     */
    public function orderId(mixed $value = null): static
    {
        $this->orderId = $value;
        return $this;
    }

    public function status(array|string|null $value = null): static
    {
        return $this->readStatus(is_array($value) ? ($value[0] ?? null) : $value);
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('simpleform_submissions');

        $this->query->select([
            'simpleform_submissions.formId',
            'simpleform_submissions.data',
            'simpleform_submissions.fieldSnapshot',
            'simpleform_submissions.userId',
            'simpleform_submissions.readStatus',
            'simpleform_submissions.workflowStatus',
            'simpleform_submissions.spamReason',
            'simpleform_submissions.sourceIp',
            'simpleform_submissions.ipHash',
            'simpleform_submissions.paymentStatus',
            'simpleform_submissions.paymentAmount',
            'simpleform_submissions.orderId',
            'simpleform_submissions.couponCode',
            'simpleform_submissions.discountAmount',
            'simpleform_submissions.quizScore',
            'simpleform_submissions.quizMaxScore',
            'simpleform_submissions.quizPercentage',
            'simpleform_submissions.quizGrade',
            'simpleform_submissions.attribution',
            'simpleform_submissions.editTokenHash',
            'simpleform_submissions.editTokenExpires',
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

        if ($this->workflowStatus !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_submissions.workflowStatus', $this->workflowStatus)
            );
        }

        if ($this->userId !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_submissions.userId', $this->userId)
            );
        }

        if ($this->paymentStatus !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_submissions.paymentStatus', $this->paymentStatus)
            );
        }

        if ($this->orderId !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_submissions.orderId', $this->orderId)
            );
        }

        return parent::beforePrepare();
    }
}
