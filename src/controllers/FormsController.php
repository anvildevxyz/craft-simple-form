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
        $forms = Form::find()->all();
        return $this->renderTemplate('simple-form/forms/index', [
            'forms' => $forms,
        ]);
    }

    public function actionEdit(?int $formId = null): Response
    {
        if ($formId) {
            $form = Form::find()->id($formId)->one();
            if (!$form) {
                throw new \yii\web\NotFoundHttpException('Form not found');
            }
        } else {
            $form = new Form();
        }

        return $this->renderTemplate('simple-form/forms/edit', [
            'form' => $form,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $formId = $request->getBodyParam('formId');

        if ($formId) {
            $form = Form::find()->id($formId)->one();
            if (!$form) {
                throw new \yii\web\NotFoundHttpException('Form not found');
            }
        } else {
            $form = new Form();
        }

        // Set attributes from request
        $form->name = $request->getBodyParam('name');
        $form->handle = $request->getBodyParam('handle');
        $form->title = $request->getBodyParam('title');
        $form->description = $request->getBodyParam('description');
        $form->emailTo = $request->getBodyParam('emailTo');
        $form->emailSubject = $request->getBodyParam('emailSubject');
        $form->emailReplyTo = $request->getBodyParam('emailReplyTo');

        if (!Craft::$app->getElements()->saveElement($form)) {
            return $this->asJson([
                'success' => false,
                'errors' => $form->getErrors(),
            ]);
        }

        return $this->redirect('simple-form/forms/edit/' . $form->id);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');

        $form = Form::find()->id($formId)->one();
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
