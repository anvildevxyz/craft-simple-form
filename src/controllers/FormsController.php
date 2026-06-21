<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\enums\PropagationMethod;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\DialCodes;
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

        return $this->renderTemplate('simple-form/forms/index', [
            'forms' => $forms,
            'currentSite' => $site,
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

        // Post-submit behavior (#133). The message/URL/error overrides are
        // per-site translatable content; the action + entry id are shared.
        $form->postSubmitAction = in_array(
            (string) $request->getBodyParam('postSubmitAction', 'message'),
            Form::POST_SUBMIT_ACTIONS,
            true,
        ) ? (string) $request->getBodyParam('postSubmitAction', 'message') : 'message';
        $redirectEntryId = $request->getBodyParam('redirectEntryId');
        if (is_array($redirectEntryId)) {
            $redirectEntryId = reset($redirectEntryId) ?: null;
        }
        $form->redirectEntryId = $redirectEntryId !== null && $redirectEntryId !== ''
            ? (int) $redirectEntryId
            : null;
        $form->submitMessage = $request->getBodyParam('submitMessage');
        $form->errorMessage = $request->getBodyParam('errorMessage');
        $form->redirectUrl = $request->getBodyParam('redirectUrl');

        // Scheduling window + quota. Empty inputs clear the bound (open-ended /
        // unlimited). forms.dateTimeField posts a {date, time, timezone} array,
        // which DateTimeHelper::toDateTime normalises (returns false when blank).
        $openDate = DateTimeHelper::toDateTime($request->getBodyParam('openDate')) ?: null;
        $closeDate = DateTimeHelper::toDateTime($request->getBodyParam('closeDate')) ?: null;
        $form->openDate = $openDate instanceof \DateTime ? $openDate : null;
        $form->closeDate = $closeDate instanceof \DateTime ? $closeDate : null;
        $submissionLimit = $request->getBodyParam('submissionLimit');
        $form->submissionLimit = is_numeric($submissionLimit) && (int) $submissionLimit > 0
            ? (int) $submissionLimit
            : null;
        $form->closedMessage = $request->getBodyParam('closedMessage') ?: null;

        // Login + per-user limit (#135). Shared flags + per-site notice overrides.
        $form->requireLogin = (bool) $request->getBodyParam('requireLogin');
        $form->loginRequiredMessage = $request->getBodyParam('loginRequiredMessage');
        $submissionsPerUser = trim((string) $request->getBodyParam('submissionsPerUser', ''));
        $form->submissionsPerUser = $submissionsPerUser !== '' ? (int) $submissionsPerUser : null;
        $form->guestLimitKey = (string) $request->getBodyParam('guestLimitKey', Form::GUEST_LIMIT_NONE);
        $form->userLimitMessage = $request->getBodyParam('userLimitMessage');

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

        // Editing an HTML layout block's body requires a dedicated permission.
        // Re-checked here (after parsing the posted set) so a forged HTML block
        // cannot slip past the controller's MANAGE_FORMS gate. Reorder/delete of
        // an existing block — and an unchanged body — are not gated.
        $identity = Craft::$app->getUser()->getIdentity();
        if (
            $identity !== null
            && !$identity->admin
            && !$identity->can(SimpleFormPermissions::EDIT_HTML_BLOCKS)
            && $fieldSync->htmlBlockBodyChanged($items, $site->id)
        ) {
            Craft::$app->getSession()->setError(
                Craft::t('simple-form', 'You don’t have permission to edit HTML layout blocks.')
            );
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

        // Resolve the redirect entry (on the edited site) for the element select.
        $redirectEntry = null;
        if ($form->redirectEntryId !== null) {
            $redirectEntry = \craft\elements\Entry::find()
                ->id($form->redirectEntryId)
                ->siteId($site->id)
                ->status(null)
                ->one();
        }

        /** @var list<array{handle: string, name: string}> $volumes */
        $volumes = array_values(array_map(
            static fn($v): array => ['handle' => (string) $v->handle, 'name' => (string) $v->name],
            Craft::$app->getVolumes()->getAllVolumes(),
        ));

        // Curated dial-code list for the Phone field's inspector controls,
        // localized per site. Structural config (not content), so it ships to
        // the builder as a flat list rather than through the translation path.
        $phoneCountries = [];
        foreach (DialCodes::all() as $iso => $meta) {
            $phoneCountries[] = [
                'iso' => $iso,
                'dial' => $meta['dial'],
                'label' => DialCodes::label($iso),
            ];
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $canEditHtmlBlocks = $identity !== null
            && ($identity->admin || $identity->can(SimpleFormPermissions::EDIT_HTML_BLOCKS));

        return $this->renderTemplate('simple-form/forms/edit', [
            'form' => $form,
            'currentSite' => $site,
            'supportedSites' => $supportedSites,
            'builderData' => $builderDataJson,
            'redirectEntry' => $redirectEntry,
            'volumes' => array_values($volumes),
            'phoneCountries' => $phoneCountries,
            'canEditHtmlBlocks' => $canEditHtmlBlocks,
            // Selectable sources per element-relation type (section/group/volume
            // handles), so the field builder can offer a source picker scoped to
            // the chosen element type.
            'relationSources' => $this->relationSources(array_values($volumes)),
            // The source site authors canonical option labels; other sites only
            // translate them. Single-site forms are always their own source.
            'isSourceSite' => count($supportedSites) <= 1
                || $site->id === Craft::$app->getSites()->getPrimarySite()->id,
        ]);
    }

    /**
     * The selectable sources for each element-relation field type — section,
     * category-group, tag-group, user-group, and volume handles + names — so the
     * field builder can render a source picker scoped to the chosen element type.
     * Volumes are reused from the file-field volume list already gathered.
     *
     * @param list<array{handle: string, name: string}> $volumes
     * @return array<string, list<array{handle: string, name: string}>>
     */
    private function relationSources(array $volumes): array
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;

        $map = static fn(array $items): array => array_values(array_map(
            static fn($item): array => ['handle' => (string) $item->handle, 'name' => (string) $item->name],
            $items,
        ));

        return [
            'entry' => $map($app->getEntries()->getAllSections()),
            'category' => $map($app->getCategories()->getAllGroups()),
            'tag' => $map($app->getTags()->getAllTagGroups()),
            'user' => $map($app->getUserGroups()->getAllGroups()),
            'asset' => array_values($volumes),
        ];
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
