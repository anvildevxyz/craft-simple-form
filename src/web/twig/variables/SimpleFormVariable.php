<?php

namespace fabianhaef\simpleform\web\twig\variables;

use Craft;
use craft\helpers\Template;
use fabianhaef\simpleform\elements\db\FormQuery;
use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
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
