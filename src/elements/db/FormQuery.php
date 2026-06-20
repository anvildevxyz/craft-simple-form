<?php

namespace fabianhaef\simpleform\elements\db;

use Craft;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\Form;

/**
 * @extends ElementQuery<int, Form>
 *
 * @method Form[] all($db = null)
 * @method Form|null one($db = null)
 * @method Form|null nth(int $n, $db = null)
 */
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
            'simpleform_forms.allowSaveResume',
            'simpleform_forms.openDate',
            'simpleform_forms.closeDate',
            'simpleform_forms.submissionLimit',
            'simpleform_forms_sites.description',
            'simpleform_forms_sites.emailTo',
            'simpleform_forms_sites.emailSubject',
            'simpleform_forms_sites.emailReplyTo',
            'simpleform_forms_sites.emailBody',
            'simpleform_forms_sites.closedMessage',
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

        // A single-target query may arrive as a non-zero-indexed array (e.g. during a
        // propagating save Craft passes the resolved site ids), so don't assume key 0.
        if (is_array($siteId) && count($siteId) === 1) {
            $only = array_values($siteId)[0];
            if (is_numeric($only)) {
                return (int)$only;
            }
        }

        return Craft::$app->getSites()->getCurrentSite()->id;
    }
}
