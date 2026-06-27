<?php

namespace anvildev\simpleform\widgets;

use anvildev\simpleform\elements\Submission;
use Craft;
use craft\base\Widget;
use craft\helpers\Cp;
use craft\helpers\Db;

/**
 * Dashboard widget: a submission count for the current site over a selectable
 * range (today / 7d / 30d / all), optionally scoped to one form.
 */
class SubmissionCountWidget extends Widget
{
    use SubmissionWidgetTrait;

    public const RANGES = ['today', '7d', '30d', 'all'];

    public string $range = '30d';
    public ?int $formId = null;

    public static function displayName(): string
    {
        return Craft::t('simple-form', 'Form Submissions');
    }

    public static function icon(): ?string
    {
        return null;
    }

    /**
     * The start of the window for a range, or null for "all time".
     */
    public static function cutoffFor(string $range, \DateTimeInterface $now): ?\DateTimeInterface
    {
        $dt = \DateTimeImmutable::createFromInterface($now);
        return match ($range) {
            'today' => $dt->setTime(0, 0),
            '7d' => $dt->modify('-7 days'),
            '30d' => $dt->modify('-30 days'),
            default => null,
        };
    }

    public function getTitle(): ?string
    {
        return Craft::t('simple-form', 'Form Submissions');
    }

    public function getBodyHtml(): ?string
    {
        if (($denied = $this->submissionsPermissionError()) !== null) {
            return $denied;
        }

        $count = $this->count();

        $labels = [
            'today' => Craft::t('simple-form', 'today'),
            '7d' => Craft::t('simple-form', 'in the last 7 days'),
            '30d' => Craft::t('simple-form', 'in the last 30 days'),
            'all' => Craft::t('simple-form', 'all time'),
        ];
        $label = $labels[$this->range] ?? $labels['30d'];

        return '<div style="text-align:center;padding:10px 0;">'
            . '<div style="font-size:42px;font-weight:bold;line-height:1;">' . (int) $count . '</div>'
            . '<div class="light" style="margin-top:6px;">' . htmlspecialchars($label) . '</div>'
            . '</div>';
    }

    public function getSettingsHtml(): ?string
    {
        $formOptions = $this->formScopeOptions();

        return Cp::selectFieldHtml([
            'label' => Craft::t('simple-form', 'Range'),
            'name' => 'range',
            'options' => [
                ['label' => Craft::t('simple-form', 'Today'), 'value' => 'today'],
                ['label' => Craft::t('simple-form', 'Last 7 days'), 'value' => '7d'],
                ['label' => Craft::t('simple-form', 'Last 30 days'), 'value' => '30d'],
                ['label' => Craft::t('simple-form', 'All time'), 'value' => 'all'],
            ],
            'value' => $this->range,
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
            [['range'], 'in', 'range' => self::RANGES],
            [['formId'], 'integer'],
        ];
    }

    /** The submission count for the configured range + form on the current site. */
    public function count(): int
    {
        return (int) $this->buildCountQuery()->count();
    }

    private function buildCountQuery(): \anvildev\simpleform\elements\db\SubmissionQuery
    {
        $query = Submission::find()->siteId(Craft::$app->getSites()->getCurrentSite()->id);
        if ($this->formId) {
            $query->formId($this->formId);
        }
        $cutoff = self::cutoffFor($this->range, new \DateTime());
        if ($cutoff !== null) {
            $query->andWhere(['>=', 'elements.dateCreated', Db::prepareDateForDb($cutoff)]);
        }
        return $query;
    }
}
