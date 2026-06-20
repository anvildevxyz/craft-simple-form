<?php

namespace fabianhaef\simpleform\elements;

use Craft;
use craft\base\Element;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\db\FormQuery;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\traits\HasPropagation;

/**
 * @phpstan-import-type ResolvedFieldRow from FieldQueryHelper
 */
class Form extends Element
{
    use HasPropagation;

    /**
     * Propagation methods Simple Form supports — Craft's PropagationMethod set
     * minus `custom`, which needs per-element site selection this plugin does not
     * implement ({@see HasPropagation::getSupportedSites()} treats it as single-site).
     *
     * @var list<string>
     */
    public const SUPPORTED_PROPAGATION_METHODS = ['none', 'siteGroup', 'language', 'all'];

    // Shared across sites
    public ?string $name = null;
    public ?string $handle = null;
    /** Per-form opt-in for save-&-resume drafts (shared, not translatable). */
    public bool $allowSaveResume = false;

    // Per-site (translatable). title is stored in elements_sites via hasTitles().
    public ?string $title = null;
    public ?string $description = null;
    public ?string $emailTo = null;
    public ?string $emailSubject = null;
    public ?string $emailReplyTo = null;
    public ?string $emailBody = null;

    public static function displayName(): string
    {
        return 'Form';
    }

    public static function tableName(): string
    {
        return 'simpleform_forms';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function find(): FormQuery
    {
        return new FormQuery(static::class);
    }

    /**
     * Pre-resolved field set for this form/site, primed by
     * {@see self::eagerLoadFields()} so a forms listing avoids an N+1.
     *
     * @var list<ResolvedFieldRow>|null
     */
    private ?array $eagerFields = null;

    public function __toString(): string
    {
        return $this->title ?? $this->name ?? '';
    }

    /**
     * The form's resolved field set (decoded config + this site's label/help
     * text), served from the structure cache. When pre-loaded via
     * {@see self::eagerLoadFields()} the primed set is returned with no query.
     *
     * @return list<ResolvedFieldRow>
     */
    public function getFields(): array
    {
        if ($this->eagerFields !== null) {
            return $this->eagerFields;
        }

        if (!$this->id) {
            return [];
        }

        return Plugin::getInstance()->getFormStructure()->getFieldSet((int)$this->id, (int)$this->siteId);
    }

    /**
     * Batch-load the field sets for a list of forms in a bounded number of
     * queries (instead of one per form) and prime each form so a later
     * {@see self::getFields()} is query-free. Forms are grouped by site so the
     * per-site label/help-text join stays correct.
     *
     * @param array<int,self> $forms
     */
    public static function eagerLoadFields(array $forms): void
    {
        $structure = Plugin::getInstance()->getFormStructure();

        // Group form ids by their resolved site so each site batches into one query.
        $bySite = [];
        foreach ($forms as $form) {
            if ($form->id) {
                $bySite[(int)$form->siteId][] = (int)$form->id;
            }
        }

        $sets = [];
        foreach ($bySite as $siteId => $formIds) {
            $sets[$siteId] = $structure->getFieldSets($formIds, $siteId);
        }

        foreach ($forms as $form) {
            if ($form->id) {
                $form->eagerFields = $sets[(int)$form->siteId][(int)$form->id] ?? [];
            }
        }
    }

    /**
     * @return list<string>
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['name', 'handle', 'title', 'description'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => 'Title'],
            'handle' => ['label' => 'Handle'],
            'emailTo' => ['label' => 'Email To'],
            'dateCreated' => ['label' => 'Date Created'],
        ];
    }

    /**
     * @return list<string>
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'handle', 'emailTo', 'dateCreated'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function defineSources(?string $context = null): array
    {
        return [
            [
                'key' => '*',
                'label' => 'All Forms',
            ],
        ];
    }

    /**
     * @return array<int, array<array-key, mixed>|\yii\validators\Validator>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['name', 'handle'], 'string', 'max' => 255];
        $rules[] = [['title', 'description'], 'string'];
        $rules[] = [['emailTo', 'emailSubject', 'emailReplyTo'], 'string', 'max' => 255];
        $rules[] = [['emailBody'], 'string'];
        $rules[] = [['allowSaveResume'], 'boolean'];

        // handle is shared across sites, so it must be globally unique
        $rules[] = [['handle'], 'validateHandleUnique'];

        return $rules;
    }

    public function validateHandleUnique(string $attribute): void
    {
        if (empty($this->handle)) {
            return;
        }

        $query = (new \craft\db\Query())
            ->from('{{%simpleform_forms}}')
            ->where(['handle' => $this->handle]);

        if ($this->id) {
            $query->andWhere(['not', ['id' => $this->id]]);
        }

        if ($query->exists()) {
            $this->addError($attribute, Craft::t('simple-form', 'This handle is already in use.'));
        }
    }

    public function afterSave(bool $isNew): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        // (a) SHARED row in simpleform_forms — keyed by element id (not per-site).
        // Seed it on ANY save, including a propagation pass, so an element can
        // never be left without its shared row (which would orphan it: FormQuery
        // inner-joins this table, so a missing row makes the form un-loadable).
        // Only the canonical (directly-edited) save updates an existing row.
        $shared = [
            'handle' => $this->handle,
            'name' => $this->name,
            'propagationMethod' => $this->propagationMethod->value,
            'allowSaveResume' => $this->allowSaveResume,
            'dateUpdated' => $now,
        ];

        $exists = (new \craft\db\Query())
            ->from('{{%simpleform_forms}}')
            ->where(['id' => $this->id])
            ->exists();

        if (!$exists) {
            $db->createCommand()->insert('{{%simpleform_forms}}', $shared + [
                'id' => $this->id,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } elseif (!$this->propagating) {
            $db->createCommand()->update('{{%simpleform_forms}}', $shared, ['id' => $this->id])->execute();
        }

        // (b) PER-SITE row in simpleform_forms_sites — translatable content (title lives in
        // elements_sites). The content is per-site, so we must NOT let propagation clobber a
        // sibling site's existing translation:
        //   - canonical save (the edited site): upsert this site's values.
        //   - propagating save (Craft copying to sibling sites): only SEED a row if one is
        //     missing; preserve any existing translation.
        $siteRow = [
            'description' => $this->description,
            'emailTo' => $this->emailTo,
            'emailSubject' => $this->emailSubject,
            'emailReplyTo' => $this->emailReplyTo,
            'emailBody' => $this->emailBody,
        ];

        $rowExists = (new \craft\db\Query())
            ->from('{{%simpleform_forms_sites}}')
            ->where(['formId' => $this->id, 'siteId' => $this->siteId])
            ->exists();

        if (!$rowExists) {
            $db->createCommand()->insert('{{%simpleform_forms_sites}}', $siteRow + [
                'formId' => $this->id,
                'siteId' => $this->siteId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } elseif (!$this->propagating) {
            // Only the directly-edited site updates an existing row.
            $db->createCommand()->update('{{%simpleform_forms_sites}}', $siteRow + [
                'dateUpdated' => $now,
            ], ['formId' => $this->id, 'siteId' => $this->siteId])->execute();
        }

        // Per-site label/option/config edits also flow through a form save, so
        // invalidating here covers every structural change for all sites.
        if ($this->id) {
            Plugin::getInstance()->getFormStructure()->invalidate((int)$this->id);
        }

        parent::afterSave($isNew);
    }

    // simpleform_forms (and its cascades) is removed automatically when the element row is deleted
    // via the id -> elements.id foreign key, so no explicit beforeDelete cleanup is required.

    public function afterDelete(): void
    {
        if ($this->id) {
            Plugin::getInstance()->getFormStructure()->invalidate((int)$this->id);
        }

        parent::afterDelete();
    }
}
