<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use yii\web\Response;

class FormsController extends Controller
{
    public function actionIndex(): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $forms = Form::find()
            ->siteId($siteId)
            ->orderBy(['title' => SORT_ASC])
            ->all();

        return $this->renderTemplate('simple-form/forms/index', [
            'forms' => $forms,
        ]);
    }

    public function actionEdit(?int $formId = null): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        if ($formId) {
            $form = Form::find()
                ->siteId($siteId)
                ->id($formId)
                ->one();
            if (!$form) {
                throw new \yii\web\NotFoundHttpException('Form not found');
            }
        } else {
            $form = new Form();
            $form->siteId = $siteId;
        }

        // Fetch fields for this form
        $fields = [];
        if ($form->id) {
            $db = Craft::$app->getDb();
            $fields = $db->createCommand(
                'SELECT id, formId, type, name, label, helpText, config, sortOrder FROM {{%simpleform_fields}} WHERE formId = :formId ORDER BY sortOrder ASC',
                [':formId' => $form->id]
            )->queryAll();

            // Decode config JSON for each field
            foreach ($fields as &$field) {
                $field['config'] = json_decode($field['config'], true) ?? [];
            }
        }

        return $this->renderTemplate('simple-form/forms/edit', [
            'form' => $form,
            'fields' => $fields,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $formId = $request->getBodyParam('formId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        if ($formId) {
            $form = Form::find()
                ->siteId($siteId)
                ->id($formId)
                ->one();
            if (!$form) {
                throw new \yii\web\NotFoundHttpException('Form not found');
            }
        } else {
            $form = new Form();
            $form->siteId = $siteId;
        }

        // Set attributes from request
        $form->name = $request->getBodyParam('name');
        $form->handle = $request->getBodyParam('handle');
        $form->title = $request->getBodyParam('title');
        $form->description = $request->getBodyParam('description');
        $form->emailTo = $request->getBodyParam('emailTo');
        $form->emailSubject = $request->getBodyParam('emailSubject');
        $form->emailReplyTo = $request->getBodyParam('emailReplyTo');

        // Validate before saving
        if (!$form->validate()) {
            Craft::$app->getSession()->setError('Form validation failed');
            return $this->redirect($request->getReferrer() ?? 'simple-form/forms');
        }

        // Save element
        if (!Craft::$app->getElements()->saveElement($form)) {
            Craft::$app->getSession()->setError('Unable to save form');
            Craft::warning('Form save failed: ' . json_encode($form->getErrors()), 'simple-form');
            return $this->redirect($request->getReferrer() ?? 'simple-form/forms');
        }

        Craft::$app->getSession()->setNotice('Form saved successfully');
        return $this->redirect('simple-form/forms/edit/' . $form->id);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = Form::find()
            ->siteId($siteId)
            ->id($formId)
            ->one();
        if (!$form) {
            throw new \yii\web\NotFoundHttpException('Form not found');
        }

        if (!Craft::$app->getElements()->deleteElement($form)) {
            return $this->asJson([
                'success' => false,
                'errors' => $form->getErrors(),
            ]);
        }

        return $this->asJson(['success' => true]);
    }
}
