<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\web\Request;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\FormModel;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

class SubmissionService extends Component
{
    /**
     * Create a submission from a request
     *
     * @param FormModel|Form|string $form Form instance, element, or handle
     * @param Request|null $request Request object (uses Craft request if null)
     * @return array{submission: Submission|null, errors: array<string, mixed>|null}
     */
    public function createFromRequest($form, ?Request $request = null): array
    {
        if ($request === null) {
            /** @var Request $request */
            $request = Craft::$app->getRequest();
        }

        // Load form if string (handle) provided
        if (is_string($form)) {
            $formElement = Form::find()
                ->handle($form)
                ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
                ->one();
            if (!$formElement) {
                return ['submission' => null, 'errors' => ['form' => 'Form not found']];
            }
            $form = new FormModel($formElement);
        } elseif ($form instanceof Form) {
            $form = new FormModel($form);
        }

        $settings = Plugin::getInstance()->getSettings();

        // Check honeypot when enabled
        if ($settings->enableHoneypot && !empty($request->getBodyParam('__honeypot'))) {
            // Silently fail
            return ['submission' => null, 'errors' => null];
        }

        // Verify captcha when enabled
        if (!Plugin::getInstance()->getCaptchaService()->verify()) {
            return ['submission' => null, 'errors' => ['captcha' => ['Captcha verification failed']]];
        }

        // Validate all fields
        $data = [];
        $errors = [];
        $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();

        foreach ($form->getFields() as $fieldId => $field) {
            $fieldName = 'field_' . $fieldId;
            $value = $request->getBodyParam($fieldName);

            $fieldErrors = $field->validateValue($value);
            if (!empty($fieldErrors)) {
                $errors[$fieldName] = $fieldErrors;
            }

            $data[$fieldName] = [
                'label' => $field->getLabel() ?? $field->getName(),
                'type' => $field->getType(),
                'value' => $value,
            ];
        }

        // Return errors if validation failed
        if (!empty($errors)) {
            return ['submission' => null, 'errors' => $errors];
        }

        // Create submission
        $formElement = $form->form ?? Form::find()
            ->id($form->getId())
            ->one();

        $submission = new Submission();
        $submission->formId = $form->getId();
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->data = $data;
        $userId = Craft::$app->getUser()->getId();
        $submission->userId = $userId !== null ? (int) $userId : null;
        $submission->readStatus = 'new';

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return ['submission' => null, 'errors' => ['submission' => ['Failed to save submission']]];
        }

        return ['submission' => $submission, 'errors' => null];
    }

    public function getSubmission(int $submissionId): ?Submission
    {
        return Submission::find()->id($submissionId)->one();
    }

    public function updateStatus(int $submissionId, string $status): bool
    {
        $validStatuses = ['new', 'read', 'archived'];
        if (!in_array($status, $validStatuses)) {
            return false;
        }

        $submission = $this->getSubmission($submissionId);
        if (!$submission) {
            return false;
        }

        $submission->readStatus = $status;
        return Craft::$app->getElements()->saveElement($submission);
    }
}
