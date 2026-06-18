<?php

namespace fabianhaef\simpleform\web\twig\variables;

use Craft;
use craft\helpers\Template;
use fabianhaef\simpleform\elements\db\FormQuery;
use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
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
