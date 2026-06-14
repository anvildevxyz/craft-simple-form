<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\enums\PropagationMethod;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\helpers\SiteHelper;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FormsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_FORMS;

    public function actionIndex(): Response
    {
        $site = SiteHelper::getSiteForRequest(Craft::$app->getRequest());

        $forms = Form::find()
            ->siteId($site->id)
            ->orderBy(['elements_sites.title' => SORT_ASC])
            ->all();

        return $this->renderTemplate('simple-form/forms/index', [
            'forms' => $forms,
            'currentSite' => $site,
        ]);
    }

    public function actionEdit(?int $formId = null): Response
    {
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteForRequest($request);

        if ($formId) {
            $form = Form::find()
                ->siteId($site->id)
                ->id($formId)
                ->status(null)
                ->one();

            // Not present on this site — fall back to wherever it exists and redirect there.
            if (!$form) {
                $form = Form::find()->siteId('*')->id($formId)->status(null)->one();
                if (!$form) {
                    throw new NotFoundHttpException('Form not found');
                }
                $existingSite = Craft::$app->getSites()->getSiteById($form->siteId);
                if ($existingSite) {
                    return $this->redirect("simple-form/forms/edit/{$formId}?site={$existingSite->handle}");
                }
            }
        } else {
            $form = new Form();
            $form->siteId = $site->id;
        }

        // Fetch fields with this site's translatable label/helpText.
        $fields = $form->id ? $this->getFieldsForForm((int)$form->id, $site->id) : [];

        return $this->renderTemplate('simple-form/forms/edit', [
            'form' => $form,
            'fields' => $fields,
            'currentSite' => $site,
            'supportedSites' => $this->getSupportedSitesForForm($form),
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteFromPost($request);
        $formId = $request->getBodyParam('formId');

        if ($formId) {
            // Load (or create) the element on the posted site so its per-site content saves there.
            $form = Form::find()->siteId($site->id)->id($formId)->status(null)->one()
                ?? Form::find()->siteId('*')->id($formId)->status(null)->one();
            if (!$form) {
                throw new NotFoundHttpException('Form not found');
            }
            $form->siteId = $site->id;
        } else {
            $form = new Form();
            $form->siteId = $site->id;
        }

        $form->name = $request->getBodyParam('name');
        $form->handle = $request->getBodyParam('handle');
        $form->title = $request->getBodyParam('title');
        $form->description = $request->getBodyParam('description');
        $form->emailTo = $request->getBodyParam('emailTo');
        $form->emailSubject = $request->getBodyParam('emailSubject');
        $form->emailReplyTo = $request->getBodyParam('emailReplyTo');
        $form->propagationMethod = PropagationMethod::tryFrom(
            (string)$request->getBodyParam('propagationMethod', 'none')
        ) ?? PropagationMethod::None;

        if (!Craft::$app->getElements()->saveElement($form)) {
            Craft::$app->getSession()->setError('Unable to save form');
            Craft::warning('Form save failed: ' . json_encode($form->getErrors()), 'simple-form');
            Craft::$app->getUrlManager()->setRouteParams(['form' => $form]);
            return $this->redirect($request->getReferrer() ?? 'simple-form/forms');
        }

        Craft::$app->getSession()->setNotice('Form saved successfully');
        return $this->redirect("simple-form/forms/edit/{$form->id}?site={$site->handle}");
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');

        // Deletion is element-wide (all sites), so load from any site.
        $form = Form::find()->siteId('*')->id($formId)->status(null)->one();
        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }

        if (!Craft::$app->getElements()->deleteElement($form)) {
            return $this->asJson([
                'success' => false,
                'errors' => $form->getErrors(),
            ]);
        }

        return $this->asJson(['success' => true]);
    }

    /**
     * Load a form's fields joined to the given site's translatable label/helpText.
     *
     * @return array<int,array<string,mixed>>
     */
    private function getFieldsForForm(int $formId, int $siteId): array
    {
        return FieldQueryHelper::fieldsForForm($formId, $siteId);
    }

    /**
     * The sites this form is (or would be) saved to AND the user may edit, for
     * the native CP header site selector.
     *
     * @return \craft\models\Site[]
     */
    private function getSupportedSitesForForm(Form $form): array
    {
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        $sites = [];
        foreach ($form->getSupportedSites() as $id) {
            $siteId = is_array($id) ? $id['siteId'] : $id;
            if (!in_array($siteId, $editableSiteIds, true)) {
                continue;
            }
            $site = Craft::$app->getSites()->getSiteById($siteId);
            if ($site) {
                $sites[] = $site;
            }
        }
        return $sites;
    }
}
