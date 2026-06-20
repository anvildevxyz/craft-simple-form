<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\enums\PropagationMethod;
use craft\models\Site;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\helpers\SiteHelper;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FieldSyncService;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class FormsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_FORMS;

    public function actionIndex(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteForRequest($request);

        $forms = Form::find()
            ->siteId($site->id)
            ->orderBy(['elements_sites.title' => SORT_ASC])
            ->all();

        // Batch-load every listed form's fields in a bounded number of queries so
        // any per-form field access in the listing stays N+1-free.
        Form::eagerLoadFields($forms);

        $stencils = array_map(
            static fn($stencil): array => [
                'handle' => $stencil->handle,
                'name' => $stencil->name,
                'description' => $stencil->description,
            ],
            array_values(Plugin::getInstance()->getStencilLibrary()->getAll()),
        );

        return $this->renderTemplate('simple-form/forms/index', [
            'forms' => $forms,
            'currentSite' => $site,
            'stencils' => $stencils,
        ]);
    }

    public function actionEdit(?int $formId = null): Response
    {
        /** @var \craft\web\Request $request */
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

        // Fetch fields with this site's translatable label/helpText, in builder shape.
        $fields = $form->id ? $this->getFieldsForForm((int)$form->id, $site->id) : [];

        return $this->renderEdit($form, $site, $this->fieldsToBuilderJson($fields));
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
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
        $form->emailBody = $request->getBodyParam('emailBody');
        $form->allowSaveResume = (bool) $request->getBodyParam('allowSaveResume');
        $form->propagationMethod = PropagationMethod::tryFrom(
            (string)$request->getBodyParam('propagationMethod', 'none')
        ) ?? PropagationMethod::None;

        // The field builder posts its whole set as JSON; sync it after the form saves.
        $items = $this->parseFieldsData((string)$request->getBodyParam('fieldsData', ''));
        $fieldSync = new FieldSyncService();

        // Validate the field set before any DB writes so a bad field never half-saves.
        $fieldErrors = $fieldSync->validate($items);
        if ($fieldErrors) {
            Craft::$app->getSession()->setError(reset($fieldErrors));
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items));
        }

        if (!Craft::$app->getElements()->saveElement($form)) {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Unable to save form'));
            Craft::warning('Form save failed: ' . json_encode($form->getErrors()), 'simple-form');
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items));
        }

        try {
            $fieldSync->sync($form, $items, $site->id);
        } catch (\Throwable $e) {
            Craft::warning('Field sync failed: ' . $e->getMessage(), 'simple-form');
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Form saved, but its fields could not be saved.'));
            return $this->redirect("simple-form/forms/edit/{$form->id}?site={$site->handle}");
        }

        Plugin::getInstance()->getAudit()->log('form.save', 'form', (int) $form->id, (string) ($form->title ?? $form->name));

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Form saved successfully'));
        return $this->redirect("simple-form/forms/edit/{$form->id}?site={$site->handle}");
    }

    /**
     * Deep-copy an existing form (the edit screen's "Save as a new form" button)
     * and redirect to the copy's edit screen.
     *
     * @throws NotFoundHttpException if the source form does not exist
     * @throws \Throwable if the copy cannot be saved
     */
    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');

        // Duplication is element-wide (all sites), so load from any site.
        $source = Form::find()->siteId('*')->id($formId)->status(null)->one();
        if (!$source) {
            throw new NotFoundHttpException('Form not found');
        }

        try {
            $copy = Plugin::getInstance()->getFormClone()->duplicate($source);
        } catch (\Throwable $e) {
            Craft::warning('Form duplicate failed: ' . $e->getMessage(), 'simple-form');
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t duplicate the form.'));
            return $this->redirect("simple-form/forms/edit/{$formId}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Form duplicated.'));
        return $this->redirect("simple-form/forms/edit/{$copy->id}");
    }

    /**
     * Create a new form pre-populated from a built-in stencil and redirect to its
     * edit screen.
     *
     * @throws NotFoundHttpException if the stencil handle is unknown
     * @throws \Throwable if the form cannot be saved
     */
    public function actionNewFromStencil(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $handle = (string) $request->getRequiredBodyParam('stencil');

        $stencil = Plugin::getInstance()->getStencilLibrary()->getByHandle($handle);
        if ($stencil === null) {
            throw new NotFoundHttpException('Stencil not found');
        }

        try {
            $form = Plugin::getInstance()->getFormClone()->createFromStencil($stencil);
        } catch (\Throwable $e) {
            Craft::warning('Stencil create failed: ' . $e->getMessage(), 'simple-form');
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t create the form from the stencil.'));
            return $this->redirect('simple-form/forms');
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Form created.'));
        return $this->redirect("simple-form/forms/edit/{$form->id}");
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');

        // Deletion is element-wide (all sites), so load from any site.
        $form = Form::find()->siteId('*')->id($formId)->status(null)->one();
        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }

        if (!Craft::$app->getElements()->deleteElement($form)) {
            return $this->asJsonErrors($form->getErrors());
        }

        Plugin::getInstance()->getAudit()->log('form.delete', 'form', (int) $formId, (string) ($form->title ?? $form->name));

        return $this->asJsonSuccess();
    }

    /**
     * Render the form edit screen with the field-builder seeded from the given
     * builder JSON (DB fields on load, or the posted set when re-rendering after
     * a validation failure so in-progress field edits aren't lost).
     */
    private function renderEdit(Form $form, Site $site, string $builderDataJson): Response
    {
        $supportedSites = $this->getSupportedSitesForForm($form);

        $volumes = array_map(
            static fn($v): array => ['handle' => $v->handle, 'name' => $v->name],
            Craft::$app->getVolumes()->getAllVolumes(),
        );

        return $this->renderTemplate('simple-form/forms/edit', [
            'form' => $form,
            'currentSite' => $site,
            'supportedSites' => $supportedSites,
            'builderData' => $builderDataJson,
            'volumes' => array_values($volumes),
            // The source site authors canonical option labels; other sites only
            // translate them. Single-site forms are always their own source.
            'isSourceSite' => count($supportedSites) <= 1
                || $site->id === Craft::$app->getSites()->getPrimarySite()->id,
        ]);
    }

    /**
     * Decode the field builder's posted JSON into an ordered array of field items.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseFieldsData(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Map DB field rows to the builder's field shape and encode as JSON.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    private function fieldsToBuilderJson(array $fields): string
    {
        $items = array_map(static fn(array $f): array => [
            'id' => (int)$f['id'],
            'type' => (string)$f['type'],
            'handle' => (string)$f['name'],
            'label' => (string)($f['label'] ?? $f['name']),
            'required' => (bool)$f['required'],
            'helpText' => (string)($f['helpText'] ?? ''),
            'errorMessage' => (string)($f['errorMessage'] ?? ''),
            'config' => self::configWithSiteLabels(
                is_array($f['config'] ?? null) ? $f['config'] : [],
                is_array($f['optionLabels'] ?? null) ? $f['optionLabels'] : []
            ),
        ], $fields);

        return $this->encodeBuilderJson($items);
    }

    /**
     * Annotate each choice option with this site's translated label (`siteLabel`),
     * so the builder can show the per-site translation column alongside the
     * source label. The siteLabel rides with its option, keeping translations
     * aligned to the right value across add/remove/reorder.
     *
     * @param array<string,mixed> $config
     * @param array<string,string> $optionLabels value => localized label
     * @return array<string,mixed>
     */
    private static function configWithSiteLabels(array $config, array $optionLabels): array
    {
        if (!isset($config['options']) || !is_array($config['options'])) {
            return $config;
        }

        foreach ($config['options'] as &$opt) {
            if (!is_array($opt) || !isset($opt['value'])) {
                continue;
            }
            $opt['siteLabel'] = (string)($optionLabels[(string)$opt['value']] ?? '');
        }
        unset($opt);

        return $config;
    }

    /**
     * JSON-encode builder items for safe inline embedding in a <script> block.
     *
     * @param array<int,mixed> $items
     */
    private function encodeBuilderJson(array $items): string
    {
        return json_encode(
            array_values($items),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?: '[]';
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
