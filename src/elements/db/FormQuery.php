<?php

namespace fabianhaef\simpleform\elements\db;

use Craft;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class FormQuery extends ElementQuery
{
    public ?string $handle = null;
    public ?string $name = null;

    public function handle(?string $value = null): static
    {
        $this->handle = $value;
        return $this;
    }

    public function name(?string $value = null): static
    {
        $this->name = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        // Shared columns: joined on the element id.
        $this->joinElementTable('simpleform_forms');

        // Per-site content: joined on (formId = element id) AND the resolved site.
        // joinElementTable only joins on id, so the per-site join is added manually.
        $joinSiteId = $this->resolveJoinSiteId();
        $this->query->leftJoin(
            ['simpleform_forms_sites' => '{{%simpleform_forms_sites}}'],
            '[[simpleform_forms_sites.formId]] = [[simpleform_forms.id]] AND [[simpleform_forms_sites.siteId]] = :ffsSite',
            [':ffsSite' => $joinSiteId]
        );
        $this->subQuery->leftJoin(
            ['simpleform_forms_sites' => '{{%simpleform_forms_sites}}'],
            '[[simpleform_forms_sites.formId]] = [[elements.id]] AND [[simpleform_forms_sites.siteId]] = :ffsSiteSub',
            [':ffsSiteSub' => $joinSiteId]
        );

        $this->query->select([
            'simpleform_forms.name',
            'simpleform_forms.handle',
            'simpleform_forms.propagationMethod',
            'simpleform_forms_sites.description',
            'simpleform_forms_sites.emailTo',
            'simpleform_forms_sites.emailSubject',
            'simpleform_forms_sites.emailReplyTo',
            // title is supplied from elements_sites.title via the element column map
        ]);

        if ($this->handle !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_forms.handle', $this->handle)
            );
        }

        if ($this->name !== null) {
            $this->subQuery->andWhere(
                Db::parseParam('simpleform_forms.name', $this->name)
            );
        }

        return parent::beforePrepare();
    }

    /**
     * Resolve a concrete siteId for the per-site content join. When the query targets
     * multiple sites ('*' / array), fall back to the current site for the content columns.
     */
    private function resolveJoinSiteId(): int
    {
        $siteId = $this->siteId;

        if (is_int($siteId)) {
            return $siteId;
        }

        if (is_array($siteId) && count($siteId) === 1 && is_numeric($siteId[0])) {
            return (int)$siteId[0];
        }

        return Craft::$app->getSites()->getCurrentSite()->id;
    }
}
