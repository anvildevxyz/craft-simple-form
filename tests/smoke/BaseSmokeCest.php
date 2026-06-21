<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * Shared seeding + request helpers for the functional smoke Cests.
 *
 * The smoke suite boots a real Craft via the `\craft\test\Craft` module inside a
 * per-test DB transaction (configured in the root codeception.yml). The
 * `\Helper\Integration` module pins a front-end web request so the public
 * render/submit paths run as they would on the site under the CLI SAPI.
 *
 * These Cests deliberately seed forms + fields through the data layer (mirroring
 * how FieldsController persists a field) rather than clicking the JS form
 * builder, then exercise the public submit path through
 * {@see SubmissionService::createFromRequest()} — genuinely new HTTP/Twig/
 * controller coverage versus the service-only integration suite.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
abstract class BaseSmokeCest
{
    // =========================================================================
    // PROTECTED METHODS
    // =========================================================================

    /**
     * Create and persist a Form element for the current (or given) site.
     */
    protected function createForm(
        string $name,
        string $handle,
        ?string $emailTo = null,
        ?int $siteId = null,
    ): Form {
        $form = new Form();
        $form->name = $name;
        $form->handle = $handle;
        $form->title = $name;
        $form->emailTo = $emailTo;
        $form->siteId = $siteId ?? Craft::$app->getSites()->getPrimarySite()->id;

        Craft::$app->getElements()->saveElement($form);

        return $form;
    }

    /**
     * Insert a field for a form (structural row + per-site label/helpText rows),
     * mirroring how FieldsController persists a field. Returns the new field id.
     *
     * @param array<string, mixed> $config
     */
    protected function createField(
        int $formId,
        string $type,
        string $name,
        string $label,
        bool $required = false,
        array $config = [],
        string $helpText = '',
    ): int {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $formId,
            'type' => $type,
            'name' => $name,
            'required' => $required,
            'config' => $config,
            'sortOrder' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $fieldId = (int)$db->getLastInsertID();

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
                'fieldId' => $fieldId,
                'siteId' => $site->id,
                'label' => $label,
                'helpText' => $helpText ?: null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        return $fieldId;
    }

    /**
     * Submit a form through the shared upload-aware request path, exactly like
     * the public SubmitController does. Field values post under `field_<id>`.
     *
     * @param array<string, mixed> $bodyParams
     * @return array{submission: \fabianhaef\simpleform\elements\Submission|null, errors: array<string, mixed>|null, data?: array<string, mixed>}
     */
    protected function submitRequest(string $formHandle, array $bodyParams): array
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(array_merge(['formHandle' => $formHandle], $bodyParams));

        $form = Form::find()
            ->handle($formHandle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        return $this->service()->createFromRequest($form, $request);
    }

    /**
     * Render a form's public HTML through the shared FormRender service — the
     * exact entry point the `simpleForm()` Twig function and the
     * `craft.simpleForm.form()` variable both delegate to. The Twig function
     * itself isn't registered under the console-booted test app, so this calls
     * the service directly for identical output.
     *
     * @param array<string, mixed> $options
     */
    protected function renderForm(string $handle, array $options = []): string
    {
        return Plugin::getInstance()->getFormRender()->renderForm($handle, $options);
    }

    /**
     * The shared submission service.
     */
    protected function service(): SubmissionService
    {
        return Plugin::getInstance()->getSubmissionService();
    }
}
