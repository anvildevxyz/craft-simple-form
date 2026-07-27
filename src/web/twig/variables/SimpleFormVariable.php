<?php

namespace anvildev\simpleform\web\twig\variables;

use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\db\FormQuery;
use anvildev\simpleform\elements\db\SubmissionQuery;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use Twig\Markup;

/**
 * `craft.simpleForm.*` template API (#110). Complements the existing
 * `simpleForm()` Twig function with element queries and a render helper.
 */
class SimpleFormVariable
{
    /**
     * The active edition handle (`solo` or `pro`).
     */
    public function edition(): string
    {
        return Editions::current();
    }

    /**
     * Whether the active edition is Standard.
     */
    public function isStandard(): bool
    {
        return Editions::isStandard();
    }

    /**
     * Whether the active edition may use a capability, e.g.
     * `craft.simpleForm.can('payments')`.
     */
    public function can(string $capability): bool
    {
        return Editions::can($capability);
    }

    /**
     * Look up a single form by handle or id.
     */
    public function form(string|int $handleOrId): ?Form
    {
        $query = Form::find();
        if (is_numeric($handleOrId)) {
            $query->id((int) $handleOrId);
        } else {
            $query->handle((string) $handleOrId);
        }

        return $query->one();
    }

    /**
     * A form element query (optionally configured with criteria like `{limit: 5}`).
     *
     * @param array<string, mixed> $criteria
     */
    public function forms(array $criteria = []): FormQuery
    {
        $query = Form::find();
        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        /** @var FormQuery $query */
        return $query;
    }

    /**
     * Render a whole form to markup (same output as the `simpleForm()` function).
     *
     * Options: `submitText`, `class`, `id`, `attributes` (extra `<form>` attrs)
     * and `theme` (override the resolved template path for this render only; an
     * empty string forces the built-in default partials).
     *
     * @param array<string, mixed> $options
     * @throws \Throwable from the underlying View render
     */
    public function render(string $handle, array $options = []): Markup
    {
        return Template::raw(Plugin::getInstance()->getFormRender()->renderForm($handle, $options));
    }

    /**
     * Render the opening `<form …>` tag plus CSRF, honeypot and hidden
     * `formHandle` for a hand-authored single-step form (#137). Pair with
     * {@see self::formEnd()}.
     *
     * @param array<string, mixed> $options
     * @throws \Throwable from the underlying View render
     */
    public function formStart(string $handle, array $options = []): Markup
    {
        return Plugin::getInstance()->getFormRender()->renderFormStart($handle, $options);
    }

    /**
     * Render the closing controls (captcha, submit, assets, `</form>`) paired
     * with {@see self::formStart()} (#137).
     *
     * @param array<string, mixed> $options
     * @throws \Throwable from the underlying View render
     */
    public function formEnd(string $handle, array $options = []): Markup
    {
        return Plugin::getInstance()->getFormRender()->renderFormEnd($handle, $options);
    }

    /**
     * Render a single field group via the `field` partial, preserving its
     * required/conditional data-attrs for hand-authored layouts (#137).
     *
     * @param array<string, mixed> $options
     * @throws \Throwable from the underlying View render
     */
    public function field(string $handle, string $fieldHandle, array $options = []): Markup
    {
        return Plugin::getInstance()->getFormRender()->renderField($handle, $fieldHandle, $options);
    }

    /**
     * Render an editable, pre-filled copy of a submission's form (#144). Pass a
     * `token` in $options for the anonymous tokenized-link path; a logged-in owner
     * needs none. The submission may be passed as an element or its id.
     *
     *     {{ craft.simpleForm.editForm(submission, { token: craft.app.request.getParam('t') }) }}
     *
     * @param Submission|int $submission
     * @param array<string, mixed> $options
     */
    public function editForm(Submission|int $submission, array $options = []): Markup
    {
        $element = $this->resolveSubmission($submission);

        if (!$element instanceof Submission) {
            return Template::raw('<!-- Submission not found -->');
        }

        return Template::raw(Plugin::getInstance()->getFormRender()->renderEditForm($element, $options));
    }

    /**
     * A secure, tokenized edit URL for a submission, suitable for an autoresponder
     * or "edit your submission" link (#144). Issues (or rotates) the submission's
     * edit token and embeds it (plus the submission id) as query params on the edit
     * page — `$path`, else the `editPath` setting. Returns null when the form does
     * not allow editing or no edit path is configured.
     *
     * @param Submission|int $submission
     * @param string|null $path site path of the edit page; falls back to the `editPath` setting
     */
    public function editUrl(Submission|int $submission, ?string $path = null): ?string
    {
        $element = $this->resolveSubmission($submission);

        if (!$element instanceof Submission) {
            return null;
        }

        $form = $element->getForm();
        if (!$form instanceof Form || !$form->allowEditing) {
            return null;
        }

        $path ??= Plugin::getInstance()->getSettings()->editPath;
        if ($path === '') {
            return null;
        }

        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($element);

        return UrlHelper::siteUrl($path, [
            'id' => (int) $element->id,
            't' => $token,
        ]);
    }

    /**
     * A submission element query (optionally configured with criteria).
     *
     * @param array<string, mixed> $criteria
     */
    public function submissions(array $criteria = []): SubmissionQuery
    {
        $query = Submission::find();
        if ($criteria !== []) {
            Craft::configure($query, $criteria);
        }

        /** @var SubmissionQuery $query */
        return $query;
    }

    /**
     * Resolve a `Submission|int` argument to a submission element, or null when
     * an id doesn't resolve. Shared by {@see self::editForm()} and
     * {@see self::editUrl()}.
     */
    private function resolveSubmission(Submission|int $submission): ?Submission
    {
        return $submission instanceof Submission
            ? $submission
            : Submission::find()->id($submission)->one();
    }
}
