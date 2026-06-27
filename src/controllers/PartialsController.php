<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

/**
 * CP listing of a form's passively-captured partials (#242): the abandoned
 * in-progress attempts, with manual delete. Read-only over draft data; gated by
 * manageForms like the form's other sibling screens.
 */
class PartialsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_FORMS;

    public function actionIndex(int $formId): Response
    {
        $form = $this->getFormOrFail($formId);
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // field_<id> => label, so the listing names the captured fields rather
        // than showing opaque keys.
        $fieldLabels = [];
        foreach (Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, $siteId) as $field) {
            $fieldLabels['field_' . $field['id']] = (string) $field['label'];
        }

        return $this->renderTemplate('simple-form/forms/partials/index', [
            'form' => $form,
            'partials' => Plugin::getInstance()->getDrafts()->listPassive((int) $form->id, $siteId),
            'fieldLabels' => $fieldLabels,
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $formId = (int) $request->getRequiredBodyParam('formId');
        $partialId = (int) $request->getRequiredBodyParam('partialId');
        $this->getFormOrFail($formId);

        Plugin::getInstance()->getDrafts()->deletePassiveById($partialId, $formId);
        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Partial deleted.'));

        return $this->redirectToPostedUrl();
    }
}
