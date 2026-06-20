<?php

namespace fabianhaef\simpleform\web\twig\variables;

use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use fabianhaef\simpleform\elements\db\FormQuery;
use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\TwigExtension;
use Twig\Markup;

/**
 * `craft.simpleForm.*` template API (#110). Complements the existing
 * `simpleForm()` Twig function with element queries and a render helper.
 */
class SimpleFormVariable
{
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
     * Render a form to markup (same output as the `simpleForm()` function).
     *
     * @param array<string, mixed> $options
     */
    public function render(string $handle, array $options = []): Markup
    {
        return Template::raw((new TwigExtension())->renderForm($handle, $options));
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
        $element = $submission instanceof Submission
            ? $submission
            : Submission::find()->id($submission)->one();

        if (!$element instanceof Submission) {
            return Template::raw('<!-- Submission not found -->');
        }

        return Template::raw((new TwigExtension())->renderEditForm($element, $options));
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
        $element = $submission instanceof Submission
            ? $submission
            : Submission::find()->id($submission)->one();

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
}
