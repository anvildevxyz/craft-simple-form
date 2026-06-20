<?php

namespace fabianhaef\simpleform\widgets;

use Craft;
use craft\base\Widget;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use fabianhaef\simpleform\elements\Submission;

/**
 * Dashboard widget: the most recent submissions for the current site, linking to
 * each submission's detail screen.
 */
class RecentSubmissionsWidget extends Widget
{
    use SubmissionWidgetTrait;

    public int $limit = 5;
    public ?int $formId = null;

    public static function displayName(): string
    {
        return Craft::t('simple-form', 'Recent Submissions');
    }

    public static function icon(): ?string
    {
        return null;
    }

    public function getTitle(): ?string
    {
        return Craft::t('simple-form', 'Recent Submissions');
    }

    public function getBodyHtml(): ?string
    {
        if (($denied = $this->submissionsPermissionError()) !== null) {
            return $denied;
        }

        $query = Submission::find()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(max(1, $this->limit));
        if ($this->formId) {
            $query->formId($this->formId);
        }

        /** @var Submission[] $submissions */
        $submissions = $query->all();
        if ($submissions === []) {
            return '<p class="light">' . Craft::t('simple-form', 'No submissions yet.') . '</p>';
        }

        $rows = '';
        foreach ($submissions as $submission) {
            $form = $submission->getForm();
            $url = UrlHelper::cpUrl('simple-form/submissions/' . $submission->id);
            $rows .= '<tr>'
                . '<td><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars((string) ($form?->title ?? $form?->name ?? '#' . $submission->formId)) . '</a></td>'
                . '<td>' . htmlspecialchars((string) $submission->readStatus) . '</td>'
                . '<td>' . htmlspecialchars($submission->dateCreated?->format('Y-m-d H:i') ?? '') . '</td>'
                . '</tr>';
        }

        return '<table class="data fullwidth"><thead><tr>'
            . '<th>' . Craft::t('simple-form', 'Form') . '</th>'
            . '<th>' . Craft::t('simple-form', 'Status') . '</th>'
            . '<th>' . Craft::t('simple-form', 'Date') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    public function getSettingsHtml(): ?string
    {
        $formOptions = $this->formScopeOptions();

        return Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'How many to show'),
            'name' => 'limit',
            'type' => 'number',
            'value' => (string) $this->limit,
        ]) . Cp::selectFieldHtml([
            'label' => Craft::t('simple-form', 'Form'),
            'name' => 'formId',
            'options' => $formOptions,
            'value' => (string) ($this->formId ?? ''),
        ]);
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['limit'], 'integer', 'min' => 1, 'max' => 50],
            [['formId'], 'integer'],
        ];
    }
}
