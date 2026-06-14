<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\events\SubmissionEvent;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\EmailService;
use yii\web\Response;

class SubmitController extends Controller
{
    public bool $enableCsrfValidation = true;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $formHandle = (string) $request->getBodyParam('formHandle', '');
        if (empty($formHandle)) {
            return $this->asJson([
                'success' => false,
                'errors' => ['form' => ['Form handle is required']],
            ]);
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->enableHoneypot) {
            $honeypot = (string) $request->getBodyParam('__honeypot', '');
            if ($honeypot !== '') {
                // Silently accept honeypot hits: report success so bots get no
                // signal, but never persist the submission.
                return $this->asJson([
                    'success' => true,
                    'message' => $settings->submitMessage,
                ]);
            }
        }

        if (!Plugin::getInstance()->getCaptchaService()->verify()) {
            return $this->asJson([
                'success' => false,
                'errors' => ['captcha' => [Craft::t('simple-form', 'Captcha verification failed. Please try again.')]],
            ]);
        }

        // Get form
        $form = Form::find()
            ->handle($formHandle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        if (!$form) {
            return $this->asJson([
                'success' => false,
                'errors' => ['form' => ['Form not found']],
            ]);
        }

        // Get field registry
        $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();

        // Parse and validate field data
        $data = [];
        $errors = [];

        // Get all fields for this form
        $fields = $this->getFormFields($form->id);

        foreach ($fields as $field) {
            $fieldType = $fieldTypeRegistry->getFieldType($field['type'], $field['config'] ?? []);
            if (!$fieldType) {
                continue;
            }

            $value = $request->getBodyParam('field_' . $field['id']);
            $fieldErrors = $fieldType->validate($value);

            if (!empty($fieldErrors)) {
                $errors['field_' . $field['id']] = $fieldErrors;
            }

            $data['field_' . $field['id']] = [
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $value,
            ];
        }

        // If there are validation errors, return them as JSON
        if (!empty($errors)) {
            return $this->asJson([
                'success' => false,
                'errors' => $errors,
            ]);
        }

        // Create submission
        $submission = new Submission();
        $submission->formId = $form->id;
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->data = json_encode($data);
        $submission->userId = Craft::$app->getUser()->getId();
        $submission->readStatus = 'new';

        // Fire BEFORE_SUBMISSION_SAVE event
        $event = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_BEFORE_SUBMISSION_SAVE, $event);

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return $this->asJson([
                'success' => false,
                'errors' => ['general' => [$settings->errorMessage]],
            ]);
        }

        // Fire AFTER_SUBMISSION_SAVE event
        $event = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $event);

        // Send email if configured
        if ($form->emailTo) {
            $emailService = new EmailService();
            $emailService->sendSubmissionEmail($form, $submission, $data);
        }

        return $this->asJson([
            'success' => true,
            'message' => $settings->submitMessage,
        ]);
    }

    private function getFormFields(int $formId): array
    {
        // Use the current site so the captured label matches the visitor's language.
        return FieldQueryHelper::fieldsForForm($formId);
    }

}
