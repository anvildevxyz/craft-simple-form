<?php

namespace fabianhaef\simpleform\events;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use yii\base\Event;

class SubmissionEvent extends Event
{
    public Submission $submission;
    public Form $form;
    public ?array $data = null;
    public bool $isNew = false;

    public function __construct(Submission $submission, Form $form, ?array $data = null, bool $isNew = false, array $config = [])
    {
        parent::__construct($config);
        $this->submission = $submission;
        $this->form = $form;
        $this->data = $data;
        $this->isNew = $isNew;
    }
}
