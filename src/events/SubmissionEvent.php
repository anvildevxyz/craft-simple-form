<?php

namespace fabianhaef\simpleform\events;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use yii\base\Event;

class SubmissionEvent extends Event
{
    public Submission $submission;
    public Form $form;
    /** @var array<string, mixed>|null */
    public $data = null;
    public bool $isNew = false;

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed> $config
     */
    public function __construct(Submission $submission, Form $form, ?array $data = null, bool $isNew = false, array $config = [])
    {
        parent::__construct($config);
        $this->submission = $submission;
        $this->form = $form;
        $this->data = $data;
        $this->isNew = $isNew;
    }
}
