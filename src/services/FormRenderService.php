<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\events\ModifyRenderContextEvent;
use anvildev\simpleform\fields\FileFieldType;
use anvildev\simpleform\helpers\FieldQueryHelper;
use anvildev\simpleform\helpers\FormRows;
use anvildev\simpleform\helpers\FormScreens;
use anvildev\simpleform\helpers\FormSteps;
use anvildev\simpleform\helpers\JumpResolver;
use anvildev\simpleform\helpers\QueryPrefillResolver;
use anvildev\simpleform\integrations\support\SubmissionValues;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\web\assets\form\FormAsset;
use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craft\web\View;
use Twig\Markup;
use yii\base\Component;

/**
 * Front-end form rendering (#137).
 *
 * Resolves a form's render theme and renders its markup through overridable Twig
 * partials instead of a hard-coded PHP string. Security-sensitive, per-request
 * values (CSRF input, honeypot, captcha widget, resume token) are built here and
 * passed into the partials as pre-rendered {@see Markup}, so a theme can place
 * but never tamper with them.
 *
 * ## Template resolution order (most specific first), per partial:
 *
 *   1. Runtime `theme` option — overrides everything for that single render.
 *   2. Per-form path — {@see Form::$templatePath}, only when the form opted in
 *      via {@see Form::$useCustomTemplate}; e.g. `_simple-form/landing`.
 *   3. Global default — {@see Settings::$templatePath} (same opt-in gate),
 *      e.g. `_simple-form`.
 *   4. Plugin built-in — the `simple-form/<partial>` site template root, mapped
 *      to `src/templates/_form/` in {@see Plugin::init()}. Also the result when
 *      a form has not opted into custom templating.
 *
 * Resolution is per partial, not all-or-nothing: a theme that ships only
 * `field.twig` falls through to the built-in `form.twig`, which includes the
 * resolved (overridden) `field` partial. An unset/invalid path logs a
 * `simple-form` warning and falls back — never a hard error on a public page.
 *
 * @phpstan-type ResumePrefill array{values: array<string, mixed>, token: string}
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FormRenderService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * The overridable partials a theme may ship, keyed by the variable name each
     * is exposed under inside `form.twig` (so the built-in form template includes
     * the *resolved* partial paths).
     *
     * @var list<string>
     */
    public const PARTIALS = ['form', 'field', 'input', 'errors', 'step-nav', 'captcha', 'assets'];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Render a whole form to HTML, resolving its theme.
     *
     * @param array<string, mixed> $options caller render options (submitText,
     *        class, id, attributes, theme)
     * @return string the form markup, or an HTML comment when the handle is
     *         empty/unknown
     */
    public function renderForm(string $handle, array $options = []): string
    {
        $form = $this->_resolveForm($handle);
        if ($form === null) {
            return $this->_missing($handle);
        }

        // Scheduling/quota (#134): a closed form (out of window or over quota)
        // shows its per-site closed message instead of the form. The server-side
        // guard in SubmissionService still rejects a stale cached/crafted POST.
        if (!$form->isAcceptingSubmissions()) {
            return '<div class="simple-form simple-form--closed" role="status">'
                . htmlspecialchars($form->getResolvedClosedMessage()) . '</div>';
        }

        // Access gates (#135): a guest sees the login-required message + link, a
        // capped user sees the limit-reached message — both instead of the form.
        // SubmissionService re-checks every gate on submit, so this is render-time
        // convenience only.
        $accessNotice = $this->_renderAccessNotice($form);
        if ($accessNotice !== null) {
            return $accessNotice;
        }

        $context = $this->buildContext($form, $options);

        return $this->_render($this->_resolvePartial('form', $form, $options), $context);
    }

    /**
     * Render just the opening `<form …>` tag plus CSRF, honeypot and the hidden
     * `formHandle` — for hand-authored single-step forms (#137).
     *
     * @param array<string, mixed> $options
     * @throws \Throwable from the underlying View render
     */
    public function renderFormStart(string $handle, array $options = []): Markup
    {
        $form = $this->_resolveForm($handle);
        if ($form === null) {
            return Template::raw($this->_missing($handle));
        }

        $context = $this->buildContext($form, $options);

        return Template::raw($this->_render($this->_resolvePartial('form-start', $form, $options), $context));
    }

    /**
     * Render the closing controls — captcha, submit button, optional assets and
     * the `</form>` tag — paired with {@see self::renderFormStart()} (#137).
     *
     * @param array<string, mixed> $options
     * @throws \Throwable from the underlying View render
     */
    public function renderFormEnd(string $handle, array $options = []): Markup
    {
        $form = $this->_resolveForm($handle);
        if ($form === null) {
            return Template::raw('');
        }

        $context = $this->buildContext($form, $options);

        return Template::raw($this->_render($this->_resolvePartial('form-end', $form, $options), $context));
    }

    /**
     * Render a single field group via the resolved `field` partial, so a
     * hand-authored form keeps the field's required/conditional data-attrs (#137).
     *
     * @param array<string, mixed> $options
     * @throws \Throwable from the underlying View render
     */
    public function renderField(string $handle, string $fieldHandle, array $options = []): Markup
    {
        $form = $this->_resolveForm($handle);
        if ($form === null) {
            return Template::raw('');
        }

        $context = $this->buildContext($form, $options);

        $field = null;
        foreach ($context['fields'] as $row) {
            if (($row['name'] ?? null) === $fieldHandle) {
                $field = $row;
                break;
            }
        }
        if ($field === null) {
            Craft::warning(sprintf('Field "%s" not found on form "%s"', $fieldHandle, $handle), 'simple-form');
            return Template::raw('');
        }

        return Template::raw($this->_render($context['partials']['field'], [
            'field' => $field,
            'partials' => $context['partials'],
            'values' => $context['resume']['values'] ?? [],
        ]));
    }

    /**
     * Render an editable, pre-filled copy of a form for an existing submission
     * (#144). Each field is primed with the submission's stored value via the
     * resolved `field` partial, and the form posts to the front-end edit endpoint
     * with the submission id and (for the anonymous path) a CSRF-protected hidden
     * edit token. Server-side authorization (allowEditing + window + token/owner)
     * is re-checked on submit — this only renders the UI.
     *
     * @param \anvildev\simpleform\elements\Submission $submission the submission to edit
     * @param array<string, mixed> $options `token` (string) for the anonymous path; `submitText`
     * @return string the edit-form markup, or an HTML comment when editing is disabled
     * @throws \Throwable from the underlying View render
     */
    public function renderEditForm(\anvildev\simpleform\elements\Submission $submission, array $options = []): string
    {
        $form = $submission->getForm();
        if (!$form instanceof Form || !$form->allowEditing) {
            return '<!-- Editing is not enabled for this form -->';
        }

        // Map the submission's stored data (field_<id> => [..., value]) into the
        // prefill shape the field partial expects (field_<id> => value). A legacy
        // bare-scalar entry (older rows without the {label,type,value} wrapper) is
        // carried through as-is.
        $prefill = array_map(SubmissionValues::value(...), $submission->data ?? []);

        // Prime every input with the submission's stored value via the context
        // builder (the input HTML carries the value, so the prefill must be applied
        // during field resolution — not in the partial).
        $context = $this->buildContext($form, $options, $prefill);
        $token = isset($options['token']) ? (string) $options['token'] : '';
        $submitText = (string) ($options['submitText'] ?? Craft::t('simple-form', 'Save changes'));
        $action = Craft::$app->getUrlManager()->createUrl('simple-form/submission-edit/update');

        $enctype = $context['hasFileField'] ? ' enctype="multipart/form-data"' : '';
        $errorMessage = (string) $context['errorMessage'];
        $errorAttr = $errorMessage !== '' ? ' data-sf-error="' . htmlspecialchars($errorMessage, ENT_QUOTES) . '"' : '';

        $html = '<form class="simple-form simple-form-edit" method="POST"' . $enctype . $errorAttr
            . ' action="' . htmlspecialchars($action, ENT_QUOTES) . '">';
        $html .= (string) $context['csrfInput'];
        $html .= '<input type="hidden" name="submissionId" value="' . (int) $submission->id . '">';
        if ($token !== '') {
            $html .= '<input type="hidden" name="t" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
        }
        $html .= (string) $context['honeypot'];

        foreach ($context['fields'] as $field) {
            $html .= $this->_render($context['partials']['field'], [
                'field' => $field,
                'partials' => $context['partials'],
                'values' => $prefill,
            ]);
        }

        $html .= (string) $context['captcha'];
        $html .= '<button type="submit" class="simple-form-submit-btn">' . htmlspecialchars($submitText) . '</button>';
        $html .= '</form>';
        $html .= (string) $context['assets'];

        return $html;
    }

    /**
     * Build the documented render context passed to the partials.
     *
     * The returned array's `csrfInput`, `honeypot`, `captcha` and `assets` keys
     * are pre-rendered {@see Markup}; `partials` maps each contract partial to
     * its resolved template path; `fields` is the resolved field set; `steps` is
     * the {@see FormSteps::group()} grouping; `resume` is the save-&-resume state
     * (or null when disabled).
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $prefillValues optional input prefill map
     *        (field_<id> => value) that overrides the resume map, e.g. an edit's
     *        existing submission data (#144)
     * @return array<string, mixed>
     */
    public function buildContext(Form $form, array $options = [], ?array $prefillValues = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, $siteId);

        // Resume prefill must be known before rendering inputs so a saved value
        // re-populates the control. Group first (resume.enabled depends on steps),
        // but the prefill map itself is independent of grouping. An explicit
        // $prefillValues (e.g. an edit's existing submission data, #144) overrides
        // the resume map so each input is primed with the value being edited.
        $prefill = $this->_resumeValues($form);
        if ($prefillValues !== null) {
            $prefill['values'] = $prefillValues;
        }

        // Query-string prefill (#316): visible fields that opt in (per field, with
        // the form-level default) seed their control from the URL query string.
        // It's the lowest-precedence default, so a resume/edit value always wins;
        // resolving it into the same prefill map means it composes with conditional
        // logic and multi-step, and the value is still validated on submit.
        $prefill['values'] += $this->_queryStringPrefill($form, $fields);

        $resolvedFields = array_map(fn(array $row): array => $this->_resolveFieldRow($row, $prefill['values']), $fields);
        $steps = FormSteps::group($resolvedFields);
        $resume = $this->_buildResume($form, $steps, $prefill);

        // Multi-column layout (#136): group adjacent fields into rows so the theme
        // can wrap multi-field rows in a responsive grid. A single-field row emits
        // today's exact markup. `rows` is the single-step grouping; `stepRows`
        // groups within each step for the multi-step path.
        $rows = FormRows::group($resolvedFields);
        $stepRows = array_map(static fn(array $stepFields): array => FormRows::group($stepFields), $steps);

        // Conversational render mode (#239): derive the one-question-per-screen
        // sequence and the translated progress label. Standard mode keeps every
        // conversational key empty so its output is byte-for-byte unchanged.
        $renderMode = $form->renderMode === 'conversational' ? 'conversational' : 'standard';
        $conversational = $renderMode === 'conversational';
        $screens = $conversational
            ? FormScreens::conversational(
                $resolvedFields,
                $steps,
                Plugin::getInstance()->getFieldTypeRegistry()->layoutTypeHandles(),
            )
            : [];

        // Logic jumps (#245): per-step jump rules for the rendered sequence
        // (screens when conversational, pages otherwise). The navigator reads
        // them from `data-sf-jumps` and the server replays the same rules, so
        // both agree on the branch. Only meaningful when there's a navigator.
        $jumpSequence = JumpResolver::stepSequence(
            $resolvedFields,
            $conversational,
            Plugin::getInstance()->getFieldTypeRegistry()->layoutTypeHandles(),
        );
        $configByHandle = [];
        foreach ($resolvedFields as $f) {
            $configByHandle[(string) $f['name']] = is_array($f['config'] ?? null) ? $f['config'] : [];
        }
        $stepJumps = count($jumpSequence) > 1 ? JumpResolver::buildStepRules($jumpSequence, $configByHandle) : [];
        $hasJumps = false;
        foreach ($stepJumps as $rules) {
            if ($rules !== []) {
                $hasJumps = true;
                break;
            }
        }

        // No-JS submit round-trip (#287): SubmitController flashes the outcome
        // under per-form keys and redirects back here; the re-render shows it.
        // Read-and-clear, guarded so console/queue renders never touch a session.
        $flashSuccess = '';
        $flashErrors = [];
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $session = Craft::$app->getSession();
            $flashSuccess = (string) $session->getFlash("simpleForm:success:{$form->handle}", '');
            $flashErrors = (array) $session->getFlash("simpleForm:errors:{$form->handle}", []);
        }

        $context = [
            'form' => $form,
            'handle' => $form->handle,
            'flashSuccess' => $flashSuccess,
            'flashErrors' => $flashErrors,
            'fields' => $resolvedFields,
            'rows' => $rows,
            'steps' => $steps,
            'stepRows' => $stepRows,
            'renderMode' => $renderMode,
            'screens' => $screens,
            // Logic jumps (#245): JSON of per-step rules for the navigator, or ''
            // when the form has none (so standard forms emit no extra attribute).
            'jumpsAttr' => $hasJumps ? (string) json_encode($stepJumps) : '',
            'progressLabel' => $conversational
                ? Craft::t('simple-form', 'Question {current} of {total}')
                : Craft::t('simple-form', 'Step {current} of {total}'),
            'options' => $options,
            'submitText' => (string) ($options['submitText'] ?? Craft::t('simple-form', 'Submit')),
            'formClass' => trim('simple-form' . ($conversational ? ' simple-form--conversational' : '') . ' ' . (string) ($options['class'] ?? '')),
            'formId' => isset($options['id']) ? (string) $options['id'] : null,
            'extraAttributes' => is_array($options['attributes'] ?? null) ? $options['attributes'] : [],
            'action' => Craft::$app->getUrlManager()->createUrl('simple-form/submit'),
            'hasFileField' => $this->_hasFileField($resolvedFields),
            // Per-form override (#133) wins over the global default when set.
            'errorMessage' => trim((string) $form->errorMessage) !== ''
                ? $form->errorMessage
                : (string) ($settings->errorMessage ?? ''),
            'csrfInput' => Template::raw(Craft::$app->getView()->renderString('{{ csrfInput() }}')),
            'honeypot' => $settings->enableHoneypot
                ? Template::raw('<input type="hidden" name="__honeypot" value="" style="display:none;" aria-hidden="true" autocomplete="off">')
                : Template::raw(''),
            // UTM/referrer auto-capture (#249): empty hidden inputs the front-end
            // capture script fills from the URL/referrer; absent unless opted in.
            'attributionInput' => Template::raw($this->_attributionInputs($form)),
            // Passive partial capture (#242): the debounced auto-save endpoint and
            // the hidden token the front-end fills, so completing the form deletes
            // its partial. Both empty unless the form opted in.
            'captureUrl' => $form->capturePartials
                ? Craft::$app->getUrlManager()->createUrl('simple-form/submit/capture')
                : '',
            'captureInput' => Template::raw($form->capturePartials
                ? '<input type="hidden" name="partialToken" value="" data-sf-partial-token>'
                : ''),
            'captcha' => Template::raw($this->_captcha($settings)),
            'assets' => Template::raw($this->_assets($settings)),
            'resume' => $resume,
            'partials' => $this->_resolvePartials($form, $options),
        ];

        // Let third parties add to or rewrite the render context before output.
        // Only fires when a handler is attached.
        $plugin = Plugin::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(Plugin::EVENT_MODIFY_RENDER_CONTEXT)) {
            $event = new ModifyRenderContextEvent(['form' => $form, 'context' => $context]);
            $plugin->trigger(Plugin::EVENT_MODIFY_RENDER_CONTEXT, $event);
            $context = $event->context;
        }

        return $context;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Resolve a form by handle on the current site, logging a warning when absent.
     */
    private function _resolveForm(string $handle): ?Form
    {
        if ($handle === '') {
            return null;
        }

        $form = Form::find()
            ->handle($handle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        if (!$form) {
            Craft::warning(sprintf('Form "%s" not found for Twig rendering', $handle), 'simple-form');
        }

        return $form;
    }

    /**
     * Build the access-gate notice shown instead of the form (#135), or null when
     * the visitor may proceed:
     *  - login required + guest → the login-required message plus a login link
     *    that returns to the current URL after sign-in;
     *  - logged-in user at/over the per-user cap → the limit-reached message.
     *
     * The server re-checks both gates on submit, so this is purely a render-time
     * convenience (a cached/forged request is still rejected there).
     */
    private function _renderAccessNotice(Form $form): ?string
    {
        $user = Craft::$app->getUser();

        if ($form->requireLogin && $user->getIsGuest()) {
            /** @var \craft\web\Request $request */
            $request = Craft::$app->getRequest();
            $loginUrl = UrlHelper::siteUrl(
                Craft::$app->getConfig()->getGeneral()->getLoginPath(),
                ['return' => $request->getAbsoluteUrl()]
            );

            return '<div class="simple-form simple-form--login-required" role="status">'
                . htmlspecialchars($form->getLoginRequiredMessage())
                . ' <a href="' . htmlspecialchars($loginUrl) . '">'
                . htmlspecialchars(Craft::t('simple-form', 'Log in')) . '</a></div>';
        }

        $userId = $user->getId();
        if (Plugin::getInstance()->getSubmissionService()->userHasReachedLimit($form, $userId !== null ? (int) $userId : null)) {
            return '<div class="simple-form simple-form--limit-reached" role="status">'
                . htmlspecialchars($form->getUserLimitMessage()) . '</div>';
        }

        return null;
    }

    /**
     * Resolve every contract partial to a concrete template path (theme override
     * first, plugin built-in last), keyed by partial name for the templates.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function _resolvePartials(Form $form, array $options): array
    {
        return array_combine(
            self::PARTIALS,
            array_map(fn(string $partial): string => $this->_resolvePartial($partial, $form, $options), self::PARTIALS),
        );
    }

    /**
     * Resolve one partial to a template path: the theme override (per-render
     * `theme` option → per-form path → global path) when it exists in the site's
     * templates, otherwise the plugin built-in (`simple-form/<partial>`).
     *
     * @param array<string, mixed> $options
     */
    private function _resolvePartial(string $partial, Form $form, array $options): string
    {
        $themeRoot = $this->_themeRoot($form, $options);
        $builtIn = 'simple-form/' . $partial;

        if ($themeRoot === null) {
            return $builtIn;
        }

        $candidate = rtrim($themeRoot, '/') . '/' . $partial;
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $exists = $view->doesTemplateExist($candidate);
        } catch (\Throwable $e) {
            Craft::warning(sprintf('Theme partial lookup failed for "%s": %s', $candidate, $e->getMessage()), 'simple-form');
            $exists = false;
        } finally {
            $view->setTemplateMode($mode);
        }

        return $exists ? $candidate : $builtIn;
    }

    /**
     * The resolved theme root (per-render override → per-form → global), or null
     * when no custom path is configured (use the built-in).
     *
     * @param array<string, mixed> $options
     */
    private function _themeRoot(Form $form, array $options): ?string
    {
        // An explicit `theme` option always wins for this render — even an empty
        // string, which forces the built-in partials regardless of per-form/global.
        if (array_key_exists('theme', $options)) {
            $theme = $options['theme'];
            return is_string($theme) && $theme !== '' ? $theme : null;
        }

        // Custom templating is opt-in per form: with the switch off the form
        // always renders with the built-in markup, ignoring any global default.
        if (!$form->useCustomTemplate) {
            return null;
        }

        // Opted in: the per-form path overrides; blank falls back to the global
        // default; an unset global then falls through to the built-in markup.
        if (is_string($form->templatePath) && $form->templatePath !== '') {
            return $form->templatePath;
        }

        $global = Plugin::getInstance()->getSettings()->templatePath;

        return (is_string($global) && $global !== '') ? $global : null;
    }

    /**
     * Render a resolved partial in SITE template mode, restoring the prior mode.
     *
     * @param array<string, mixed> $context
     */
    private function _render(string $template, array $context): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            return $view->renderTemplate($template, $context);
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    /**
     * Overlay this site's option labels and decode config into a render-ready row
     * (adds `decodedConfig`, `isChoice`, `fieldName`, `labelId`, `conditional`).
     *
     * The `displayMode` key tells the `field` partial how to render the row:
     *  - `layout` — a presentational block (heading/divider/html), pre-rendered
     *    bare into `rawHtml`; no label/required/group wrapper.
     *  - `bare` — a value field that renders its own control with no group wrapper
     *    (e.g. Hidden, #124), pre-rendered into `rawHtml`.
     *  - `group` — the normal labelled `.simple-form-group` field (default).
     *
     * @param array<string, mixed> $row a resolved field row from FormStructureService
     * @param array<string, mixed> $prefill resume prefill (field_<id> => value)
     * @return array<string, mixed>
     */
    private function _resolveFieldRow(array $row, array $prefill = []): array
    {
        $config = FieldQueryHelper::applyOptionLabels(
            is_array($row['config'] ?? null) ? $row['config'] : [],
            is_array($row['optionLabels'] ?? null) ? $row['optionLabels'] : []
        );

        $type = (string) ($row['type'] ?? '');
        $fieldType = Plugin::getInstance()->getFieldTypeRegistry()->getFieldType($type, $config);
        $fieldName = 'field_' . ($row['id'] ?? '');
        $conditional = is_array($config['conditional'] ?? null) && !empty($config['conditional']['enabled'])
            ? $config['conditional']
            : null;

        $row['decodedConfig'] = $config;
        $row['required'] = !empty($config['required']);
        $row['isChoice'] = $fieldType !== null && $fieldType->isChoiceGroup();
        $row['rendersOwnLabel'] = $fieldType !== null && $fieldType->rendersOwnLabel();
        $row['fieldName'] = $fieldName;
        $row['labelId'] = $fieldName . '-label';
        $row['conditional'] = $conditional;
        $row['label'] = $row['label'] ?? ($row['name'] ?? '');
        $row['helpText'] = $row['helpText'] ?? '';
        $row['input'] = Template::raw($fieldType !== null ? $fieldType->renderInput($fieldName, $prefill[$fieldName] ?? null) : '');

        // Presentational/layout blocks (heading, divider, html) and bare value
        // fields (Hidden) render outside the labelled group wrapper. Pre-render
        // them here so the `field` partial can emit the markup verbatim and a
        // theme override of the group wrapper never wraps a layout block.
        if ($fieldType === null) {
            $row['displayMode'] = 'group';
            $row['rawHtml'] = Template::raw('');
            return $row;
        }

        if (!$fieldType->isInput()) {
            $row['displayMode'] = 'layout';
            $row['rawHtml'] = Template::raw($this->_renderLayoutBlock($row, $config));
            return $row;
        }

        if (!$fieldType->rendersInGroup()) {
            $row['displayMode'] = 'bare';
            $row['rawHtml'] = Template::raw($fieldType->renderInput($fieldName, $prefill[$fieldName] ?? null));
            return $row;
        }

        $row['displayMode'] = 'group';
        $row['rawHtml'] = Template::raw('');

        return $row;
    }

    /**
     * Render a presentational/layout block (heading, divider, html, paragraph, callout) bare — no
     * label, required marker or input wrapper. Its per-site translatable content
     * lives in the label/helpText columns, threaded into the config keys the
     * layout field types read. Returns '' when the block renders nothing.
     *
     * @param array<string, mixed> $row a resolved field row
     * @param array<string, mixed> $config the decoded, option-labelled config
     */
    private function _renderLayoutBlock(array $row, array $config): string
    {
        $type = (string) ($row['type'] ?? '');
        $label = (string) ($row['label'] ?? '');
        $helpText = (string) ($row['helpText'] ?? '');

        if ($type === 'heading') {
            $config['text'] = $label;
        } elseif ($type === 'divider') {
            // A divider's label falls back to the handle in the field row, which
            // we do not want to surface as visible copy — only use a label the
            // editor actually translated for this site.
            $config['label'] = ($label !== '' && $label !== (string) ($row['name'] ?? '')) ? $label : '';
        } elseif ($type === 'html') {
            $config['html'] = $helpText;
        } elseif ($type === 'paragraph') {
            // Static "Text" copy: the plain-text body lives in the per-site
            // helpText column and renders escaped + line-break-preserved.
            $config['text'] = $helpText;
        } elseif ($type === 'callout') {
            // Callout guidance copy: the plain-text body lives in the per-site
            // helpText column; the tone/icon ride on the field config.
            $config['body'] = $helpText;
        }

        $fieldType = Plugin::getInstance()->getFieldTypeRegistry()->getFieldType($type, $config);
        if ($fieldType === null) {
            return '';
        }

        $inner = $fieldType->renderInput('field_' . ($row['id'] ?? ''));
        if ($inner === '') {
            return '';
        }

        $groupAttrs = ' data-sf-handle="' . htmlspecialchars((string) ($row['name'] ?? ''), ENT_QUOTES) . '"';
        if (is_array($row['conditional'] ?? null)) {
            $groupAttrs .= ' data-sf-conditional="'
                . htmlspecialchars((string) json_encode($row['conditional']), ENT_QUOTES) . '"';
        }

        return '<div class="simple-form-layout simple-form-layout--' . htmlspecialchars($type, ENT_QUOTES) . '"'
            . $groupAttrs . '>' . $inner . '</div>';
    }

    /**
     * The query-string prefill map (field_<id> => value) for this render, or an
     * empty set (#316). Delegates the opt-in decision and value coercion to
     * {@see QueryPrefillResolver}; here we just gather the prefillable input
     * fields (never the Hidden field — it reads the query via its own source —
     * nor presentational layout blocks) and the live request query params.
     *
     * @param array<int, array<string, mixed>> $fields the raw field rows for the form
     * @return array<string, string|list<string>>
     */
    private function _queryStringPrefill(Form $form, array $fields): array
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request) {
            return [];
        }

        $registry = Plugin::getInstance()->getFieldTypeRegistry();
        $prefillable = [];
        foreach ($fields as $row) {
            $type = (string) ($row['type'] ?? '');
            if ($type === 'hidden') {
                continue;
            }

            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            $fieldType = $registry->getFieldType($type, $config);
            if ($fieldType === null || !$fieldType->isInput()) {
                continue;
            }

            $prefillable[] = [
                'key' => 'field_' . ($row['id'] ?? ''),
                'handle' => (string) ($row['name'] ?? ''),
                'config' => $config,
                'acceptsList' => $fieldType->acceptsListValue(),
            ];
        }

        return QueryPrefillResolver::resolve($prefillable, $request->getQueryParams(), $form->prefillFromQuery);
    }

    /**
     * The saved prefill values + token for a `?sfresume=<token>` page load, or an
     * empty set. Independent of the (multi-)step grouping.
     *
     * @return ResumePrefill
     */
    private function _resumeValues(Form $form): array
    {
        if (!$form->allowSaveResume) {
            return ['values' => [], 'token' => ''];
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $param = (string) $request->getParam('sfresume', '');
        if ($param === '') {
            return ['values' => [], 'token' => ''];
        }

        $saved = Plugin::getInstance()->getDrafts()->getData($param, (int) $form->id);

        return $saved !== null
            ? ['values' => $saved, 'token' => $param]
            : ['values' => [], 'token' => ''];
    }

    /**
     * Save-&-resume state (enabled flag, prefill values, resume token + labels),
     * or null when the form has not opted in.
     *
     * @param list<list<array<string, mixed>>> $steps
     * @param ResumePrefill $prefill
     * @return array<string, mixed>|null
     */
    private function _buildResume(Form $form, array $steps, array $prefill): ?array
    {
        if (!$form->allowSaveResume) {
            return null;
        }

        return [
            'enabled' => count($steps) > 1,
            'values' => $prefill['values'],
            'token' => $prefill['token'],
            'url' => Craft::$app->getUrlManager()->createUrl('simple-form/submit/save-draft'),
            'labels' => [
                'saved' => Craft::t('simple-form', 'Saved. Use this link to continue later:'),
                'copy' => Craft::t('simple-form', 'Copy'),
                'copied' => Craft::t('simple-form', 'Copied'),
                'button' => Craft::t('simple-form', 'Save & continue later'),
            ],
        ];
    }

    /**
     * Whether any field needs a multipart enctype (file uploads on the no-JS path).
     *
     * @param list<array<string, mixed>> $fields
     */
    private function _hasFileField(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? null) === FileFieldType::getType()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The form's CSS/JS: register the {@see FormAsset} bundle (returns '') or emit
     * it inline as an escape hatch / when no web View can publish.
     */
    /**
     * The empty hidden inputs for UTM/referrer auto-capture (#249), or an empty
     * string when the form didn't opt in. The front-end capture script fills
     * each `value` from the URL query / referrer / landing page at submit time.
     */
    private function _attributionInputs(Form $form): string
    {
        if (!$form->autoCaptureAttribution) {
            return '';
        }

        $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'referrer', 'landing_page'];
        $html = '';
        foreach ($keys as $key) {
            $html .= sprintf('<input type="hidden" name="__sf_attr[%s]" value="" data-sf-attr="%s" autocomplete="off">', $key, $key);
        }

        return $html;
    }

    private function _assets(Settings $settings): string
    {
        $view = Craft::$app->getView();

        if (!$settings->inlineFormAssets) {
            try {
                $view->registerAssetBundle(FormAsset::class);
                return '';
            } catch (\Throwable $e) {
                Craft::warning('Falling back to inline form assets: ' . $e->getMessage(), 'simple-form');
            }
        }

        $css = $this->_readInlineAsset(FormAsset::distPath('css/simple-form.css'));
        $js = $this->_readInlineAsset(FormAsset::distPath('js/simple-form.js'));

        return '<style>' . $css . '</style>' . '<script>' . $js . '</script>';
    }

    /**
     * Read a bundled build artifact for inline embedding. A missing file means a
     * broken/incomplete build, so log a warning instead of silently emitting an
     * empty asset block.
     */
    private function _readInlineAsset(string $path): string
    {
        if (!is_file($path)) {
            Craft::warning('Inline form asset missing (build artifact not found): ' . $path, 'simple-form');

            return '';
        }

        return file_get_contents($path) ?: '';
    }

    /**
     * The selected captcha provider's widget, or '' when captcha is disabled.
     */
    private function _captcha(Settings $settings): string
    {
        if (!$settings->enableCaptcha) {
            return '';
        }

        return Plugin::getInstance()->getCaptchaService()->renderWidget();
    }

    /**
     * The standard "form not found" HTML comment for an empty/unknown handle.
     */
    private function _missing(string $handle): string
    {
        return $handle === ''
            ? '<!-- Form handle is required -->'
            : sprintf('<!-- Form "%s" not found -->', htmlspecialchars($handle));
    }
}
