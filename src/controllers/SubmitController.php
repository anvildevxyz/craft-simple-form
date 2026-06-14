<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

class SubmitController extends Controller
{
    public bool $enableCsrfValidation = true;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $formHandle = $request->getBodyParam('formHandle');
        $honeypot = $request->getBodyParam('__honeypot');

        // Check honeypot
        if (!empty($honeypot)) {
            // Silently fail honeypot attempts
            return $this->redirect($request->getReferrer() ?? '/');
        }

        // Get form
        $form = Form::find()
            ->handle($formHandle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        if (!$form) {
            throw new \yii\web\BadRequestHttpException('Form not found');
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

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return $this->asJson([
                'success' => false,
                'errors' => ['general' => ['Failed to save submission']],
            ]);
        }

        // Send email if configured
        if ($form->emailTo) {
            $this->sendEmail($form, $submission, $data);
        }

        return $this->asJson([
            'success' => true,
            'message' => 'Form submitted successfully',
        ]);
    }

    private function getFormFields(int $formId): array
    {
        $db = Craft::$app->getDb();
        $fields = $db->createCommand(
            'SELECT id, formId, type, name, label, config FROM {{%simpleform_fields}} WHERE formId = :formId ORDER BY sortOrder ASC'
        )
            ->bindValues([':formId' => $formId])
            ->queryAll();

        foreach ($fields as &$field) {
            $field['config'] = $field['config'] ? json_decode($field['config'], true) : [];
        }

        return $fields;
    }

    private function sendEmail(Form $form, Submission $submission, array $data): void
    {
        try {
            $emailBody = $this->buildEmailBody($data);
            $mail = Craft::$app->getMailer()
                ->compose()
                ->setTo($form->emailTo)
                ->setFrom(Craft::$app->getSystemSettings()->getEmailFromName() . ' <' . Craft::$app->getSystemSettings()->getEmailFromEmail() . '>')
                ->setSubject($form->emailSubject ?: 'New Form Submission')
                ->setHtmlBody($emailBody);

            if ($form->emailReplyTo) {
                $mail->setReplyTo($form->emailReplyTo);
            }

            $mail->send();
        } catch (\Exception $e) {
            Craft::warning('Failed to send form submission email: ' . $e->getMessage(), 'simple-form');
        }
    }

    private function buildEmailBody(array $data): string
    {
        $html = '<h2>New Form Submission</h2>';
        $html .= '<p>Submitted at: ' . date('Y-m-d H:i:s') . '</p>';
        $html .= '<hr>';

        foreach ($data as $fieldKey => $fieldData) {
            $label = $fieldData['label'] ?? $fieldKey;
            $value = $fieldData['value'];

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $html .= sprintf(
                '<p><strong>%s:</strong> %s</p>',
                htmlspecialchars($label),
                htmlspecialchars((string) $value)
            );
        }

        return $html;
    }
}
