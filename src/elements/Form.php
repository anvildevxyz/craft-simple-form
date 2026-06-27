<?php

namespace fabianhaef\simpleform\elements;

use Craft;
use craft\base\Element;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use fabianhaef\simpleform\elements\actions\DuplicateForm;
use fabianhaef\simpleform\elements\db\FormQuery;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\helpers\SafeUrl;
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

    /** Post-submit action: show an inline message (default). */
    public const POST_SUBMIT_MESSAGE = 'message';
    /** Post-submit action: redirect to a URL. */
    public const POST_SUBMIT_URL = 'url';
    /** Post-submit action: redirect to a Craft entry. */
    public const POST_SUBMIT_ENTRY = 'entry';

    /**
     * Post-submit action: show an inline message (default), redirect to a URL,
     * or redirect to a Craft entry. The choice is structural, so it is shared
     * across sites.
     *
     * @var list<string>
     */
    public const POST_SUBMIT_ACTIONS = [self::POST_SUBMIT_MESSAGE, self::POST_SUBMIT_URL, self::POST_SUBMIT_ENTRY];

    /** {@see getClosedReason()}: the open date has not arrived yet. */
    public const CLOSED_NOT_YET = 'not_yet';

    /** {@see getClosedReason()}: the close date has passed. */
    public const CLOSED_ENDED = 'ended';

    /** {@see getClosedReason()}: the submission limit has been reached. */
    public const CLOSED_FULL = 'full';

    /** Per-user limit never applies to guests. */
    public const GUEST_LIMIT_NONE = 'none';
    /** Key the per-user limit for guests on the submitted email-field value. */
    public const GUEST_LIMIT_EMAIL = 'email';
    /** Key the per-user limit for guests on the submitter IP (reserved; not stored in v1). */
    public const GUEST_LIMIT_IP = 'ip';

    /**
     * Supported {@see self::$guestLimitKey} values.
     *
     * @var list<string>
     */
    public const GUEST_LIMIT_KEYS = [self::GUEST_LIMIT_NONE, self::GUEST_LIMIT_EMAIL, self::GUEST_LIMIT_IP];

    /** Duplicate-dedupe key: the first email field's value. */
    public const DUPLICATE_KEY_EMAIL = 'email';
    /** Duplicate-dedupe key: a hash of the persisted data payload. */
    public const DUPLICATE_KEY_CONTENT = 'content';
    /** Duplicate-dedupe key: the submitter's source IP. */
    public const DUPLICATE_KEY_IP = 'ip';

    /**
     * Every valid {@see self::$duplicateKey} value.
     *
     * @var list<string>
     */
    public const DUPLICATE_KEYS = [self::DUPLICATE_KEY_EMAIL, self::DUPLICATE_KEY_CONTENT, self::DUPLICATE_KEY_IP];

    // Shared across sites
    public ?string $name = null;
    public ?string $handle = null;
    /** Per-form opt-in for save-&-resume drafts (shared, not translatable). */
    public bool $allowSaveResume = false;
    /** What to do after a successful submission: message|url|entry (shared, not translatable). */
    public string $postSubmitAction = 'message';
    /** Target entry id for the `entry` post-submit action (shared element id). */
    public ?int $redirectEntryId = null;

    /**
     * Scheduling window + quota (shared, not translatable). All optional:
     * an unset bound is open-ended, a null limit is unlimited.
     */
    public ?DateTime $openDate = null;
    public ?DateTime $closeDate = null;
    public ?int $submissionLimit = null;
    /** Require a logged-in user to view/submit the form (shared, not translatable). */
    public bool $requireLogin = false;
    /** Max submissions per user; null = unlimited (shared, not translatable). */
    public ?int $submissionsPerUser = null;
    /** How to key the per-user limit for guests: 'none' | 'email' | 'ip' (shared). */
    public string $guestLimitKey = self::GUEST_LIMIT_NONE;
    /**
     * Per-form opt-in for custom render templating (shared, not translatable).
     * When off, the form always renders with the plugin's built-in markup,
     * regardless of any global {@see Settings::$templatePath}. When on, the form
     * uses its own {@see self::$templatePath} override, falling back to the
     * global default.
     */
    public bool $useCustomTemplate = false;
    /**
     * Per-form custom render-template path (#137), a site-templates directory of
     * Twig partials (e.g. `_simple-form/landing`) that override the plugin's
     * built-in form markup. Only consulted when {@see self::$useCustomTemplate}
     * is on; blank then falls back to the global {@see Settings::$templatePath}.
     * Shared across sites (structural, not translatable).
     */
    public ?string $templatePath = null;
    /** Per-form duplicate-submission prevention (#140; shared, not translatable). */
    public bool $preventDuplicates = false;
    /** Lookback window for duplicate detection, in minutes; 0 = "ever". */
    public int $duplicateWindowMinutes = 0;
    /** What makes two submissions duplicates: {@see self::DUPLICATE_KEYS}. */
    public string $duplicateKey = self::DUPLICATE_KEY_EMAIL;
    /** Per-form opt-in for front-end submission editing (shared, not translatable). */
    public bool $allowEditing = false;
    /** Minutes after a submission's creation that edits are accepted; 0 = unlimited while allowed. */
    public int $editWindowMinutes = 0;
    /** Per-form quiz scoring (#241; shared, not translatable). Off keeps the form a plain form. */
    public bool $quizMode = false;
    /**
     * Optional grade-band config: one band per line as `<minPercent> <label>`
     * (e.g. `90 Excellent`). Parsed leniently by {@see QuizScoringService}; the
     * highest band whose threshold a score meets wins. Blank = numeric only.
     */
    public ?string $quizGradeBands = null;
    /** Per-form opt-in for UTM/referrer auto-capture (#249; shared, not translatable). */
    public bool $autoCaptureAttribution = false;
    /** Per-form opt-in for passive partial capture (#242; shared, not translatable). */
    public bool $capturePartials = false;

    // Per-site (translatable). title is stored in elements_sites via hasTitles().
    public ?string $title = null;
    public ?string $description = null;
    public ?string $emailTo = null;
    public ?string $emailSubject = null;
    public ?string $emailReplyTo = null;
    public ?string $emailBody = null;
    /** Per-form success message override; blank falls back to the global Settings value. */
    public ?string $submitMessage = null;
    /** Per-form error message override; blank falls back to the global Settings value. */
    public ?string $errorMessage = null;
    /** Redirect target for the `url` post-submit action; supports {handle} placeholders. */
    public ?string $redirectUrl = null;
    /** Per-site message shown in place of the form when it is closed/full. */
    public ?string $closedMessage = null;
    /** Message shown (with a login link) instead of the form when login is required. */
    public ?string $loginRequiredMessage = null;
    /** Message shown instead of the form when the per-user limit is reached. */
    public ?string $userLimitMessage = null;

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

    /**
     * Request-scoped cache of the non-spam submission count, so repeated
     * availability checks within one request (render + submit guard) issue at
     * most one count query. Reset on save via {@see self::afterSave()}.
     */
    private ?int $submissionCount = null;

    public function __toString(): string
    {
        return $this->title ?? $this->name ?? '';
    }

    /**
     * @return list<string>
     */
    public function datetimeAttributes(): array
    {
        return [...parent::datetimeAttributes(), 'openDate', 'closeDate'];
    }

    /**
     * Whether the form currently accepts submissions, independent of the
     * visitor. False before the open date, after the close date, and once the
     * submission limit is reached.
     */
    public function isAcceptingSubmissions(): bool
    {
        return $this->getClosedReason() === null;
    }

    /**
     * Why the form is closed, or null when it is open. Returns one of
     * {@see self::CLOSED_NOT_YET}, {@see self::CLOSED_ENDED}, or
     * {@see self::CLOSED_FULL} — checked in that order so the most relevant
     * reason wins (a not-yet-open form is reported as such even if its limit is
     * coincidentally 0-of-N).
     */
    public function getClosedReason(): ?string
    {
        $now = DateTimeHelper::now();

        if ($this->openDate && $now < $this->openDate) {
            return self::CLOSED_NOT_YET;
        }

        if ($this->closeDate && $now > $this->closeDate) {
            return self::CLOSED_ENDED;
        }

        // The limit is the maximum number of accepted submissions: the form
        // closes once the count reaches it (count >= limit), so the Nth
        // submission is accepted and the (N+1)th is rejected.
        if ($this->submissionLimit !== null && $this->getSubmissionCount() >= $this->submissionLimit) {
            return self::CLOSED_FULL;
        }

        return null;
    }

    /**
     * Cheap, count-only tally of this form's submissions across every site,
     * excluding spam (a spam row must not burn a seat). Trashed submissions are
     * excluded by the element query's default status filter. Cached for the
     * duration of the request.
     *
     * Race-safety: this count → save is not atomic, so under concurrent submits
     * a form may slightly exceed its limit (two requests can both read N-1 then
     * both save). The cap is a soft business limit, not a hard inventory lock,
     * so a small over-count is accepted for v1. A DB-level guard keyed on formId
     * is the documented follow-up if a hard cap is ever required.
     */
    public function getSubmissionCount(): int
    {
        if ($this->submissionCount !== null) {
            return $this->submissionCount;
        }

        if (!$this->id) {
            return $this->submissionCount = 0;
        }

        return $this->submissionCount = (int)Submission::find()
            ->formId((int)$this->id)
            ->siteId('*')
            ->status(null)
            ->andWhere(['not', ['simpleform_submissions.readStatus' => SubmissionStatus::SPAM]])
            ->count();
    }

    /**
     * The message to show in place of the form when it is closed: this site's
     * configured {@see self::$closedMessage}, or a translatable default when
     * blank. The default is keyed to the {@see self::getClosedReason()} so a
     * not-yet-open form reads differently from a full one.
     */
    public function getResolvedClosedMessage(): string
    {
        if ($this->closedMessage !== null && trim($this->closedMessage) !== '') {
            return $this->closedMessage;
        }

        return match ($this->getClosedReason()) {
            self::CLOSED_NOT_YET => Craft::t('simple-form', 'This form is not open for submissions yet.'),
            self::CLOSED_FULL => Craft::t('simple-form', 'This form has reached its submission limit.'),
            default => Craft::t('simple-form', 'This form is no longer accepting submissions.'),
        };
    }

    /**
     * The message to show (with a login link) when login is required, falling
     * back to a translatable default when the per-site message is blank.
     */
    public function getLoginRequiredMessage(): string
    {
        $message = trim((string) $this->loginRequiredMessage);

        return $message !== ''
            ? $message
            : Craft::t('simple-form', 'Please log in to submit this form.');
    }

    /**
     * The message to show when the per-user submission limit is reached, falling
     * back to a translatable default when the per-site message is blank.
     */
    public function getUserLimitMessage(): string
    {
        $message = trim((string) $this->userLimitMessage);

        return $message !== ''
            ? $message
            : Craft::t('simple-form', 'You have already submitted this form.');
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
     * @return array<int,\craft\base\ElementActionInterface|string|array<string,mixed>>
     */
    protected static function defineActions(string $source): array
    {
        return [
            DuplicateForm::class,
        ];
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
        $rules[] = [['requireLogin'], 'boolean'];
        $rules[] = [['submissionsPerUser'], 'integer', 'min' => 1];
        $rules[] = [['loginRequiredMessage', 'userLimitMessage'], 'string'];
        $rules[] = [['guestLimitKey'], 'in', 'range' => self::GUEST_LIMIT_KEYS];

        // Post-submit behavior (#133).
        $rules[] = [['submitMessage', 'errorMessage', 'redirectUrl'], 'string'];
        $rules[] = [['postSubmitAction'], 'in', 'range' => self::POST_SUBMIT_ACTIONS];
        $rules[] = [['redirectEntryId'], 'integer'];
        $rules[] = [['redirectUrl'], 'required', 'when' => fn(): bool => $this->postSubmitAction === self::POST_SUBMIT_URL];
        $rules[] = [['redirectUrl'], 'validateRedirectUrl', 'when' => fn(): bool => $this->postSubmitAction === self::POST_SUBMIT_URL];
        $rules[] = [['redirectEntryId'], 'required', 'when' => fn(): bool => $this->postSubmitAction === self::POST_SUBMIT_ENTRY];

        // Scheduling + quota. The date properties are typed ?DateTime, so PHP
        // enforces the type on assignment and DateTimeHelper normalises CP POST
        // data — no `datetime` string-validator is needed (and Yii's would
        // reject the DateTime object Craft hydrates here).
        $rules[] = [['submissionLimit'], 'integer', 'min' => 1];
        $rules[] = [['closedMessage'], 'string'];
        // Custom rather than `compare`: Yii's CompareValidator stringifies its
        // operands, which throws on the DateTime objects Craft hydrates here.
        $rules[] = [['closeDate'], 'validateWindow'];

        // Custom render-template path (#137).
        $rules[] = [['templatePath'], 'string', 'max' => 255];
        $rules[] = [['useCustomTemplate'], 'boolean'];

        // Duplicate prevention (#140).
        $rules[] = [['preventDuplicates'], 'boolean'];
        $rules[] = [['duplicateWindowMinutes'], 'integer', 'min' => 0];
        $rules[] = [['duplicateKey'], 'in', 'range' => self::DUPLICATE_KEYS];

        // Front-end editing (#144).
        $rules[] = [['allowEditing'], 'boolean'];
        $rules[] = [['editWindowMinutes'], 'integer', 'min' => 0];

        // Quiz scoring (#241).
        $rules[] = [['quizMode'], 'boolean'];
        $rules[] = [['quizGradeBands'], 'string'];

        // UTM/referrer auto-capture (#249).
        $rules[] = [['autoCaptureAttribution'], 'boolean'];

        // Passive partial capture (#242).
        $rules[] = [['capturePartials'], 'boolean'];

        // handle is shared across sites, so it must be globally unique
        $rules[] = [['handle'], 'validateHandleUnique'];

        return $rules;
    }

    /**
     * Ensure the close date is on or after the open date when both are set.
     */
    public function validateWindow(string $attribute): void
    {
        if ($this->openDate !== null && $this->closeDate !== null && $this->closeDate < $this->openDate) {
            $this->addError($attribute, Craft::t('simple-form', 'The close date must be on or after the open date.'));
        }
    }

    /**
     * Reject redirect URL templates that would navigate off-site or execute script
     * after placeholder interpolation (CWE-601).
     */
    public function validateRedirectUrl(string $attribute): void
    {
        $url = $this->redirectUrl;
        if (!is_string($url) || trim($url) === '') {
            return;
        }

        if (!SafeUrl::isAcceptableRedirectTemplate($url)) {
            $this->addError(
                $attribute,
                Craft::t('simple-form', 'The redirect URL must be a site-relative path (starting with /) or a safe http(s) URL.'),
            );
        }
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
            'postSubmitAction' => $this->postSubmitAction,
            'redirectEntryId' => $this->redirectEntryId,
            'openDate' => Db::prepareDateForDb($this->openDate),
            'closeDate' => Db::prepareDateForDb($this->closeDate),
            'submissionLimit' => $this->submissionLimit,
            'requireLogin' => $this->requireLogin,
            'submissionsPerUser' => $this->submissionsPerUser,
            'guestLimitKey' => $this->guestLimitKey,
            'templatePath' => $this->templatePath,
            'useCustomTemplate' => $this->useCustomTemplate,
            'preventDuplicates' => $this->preventDuplicates,
            'duplicateWindowMinutes' => $this->duplicateWindowMinutes,
            'duplicateKey' => $this->duplicateKey,
            'allowEditing' => $this->allowEditing,
            'editWindowMinutes' => $this->editWindowMinutes,
            'quizMode' => $this->quizMode,
            'quizGradeBands' => $this->quizGradeBands,
            'autoCaptureAttribution' => $this->autoCaptureAttribution,
            'capturePartials' => $this->capturePartials,
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
            'submitMessage' => $this->submitMessage,
            'errorMessage' => $this->errorMessage,
            'redirectUrl' => $this->redirectUrl,
            'closedMessage' => $this->closedMessage,
            'loginRequiredMessage' => $this->loginRequiredMessage,
            'userLimitMessage' => $this->userLimitMessage,
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

        // A save may change the window/limit (or add a submission via the
        // element save path), so drop the request-scoped count cache.
        $this->submissionCount = null;

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
