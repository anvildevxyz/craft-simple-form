<?php

namespace anvildev\simpleform\elements;

use anvildev\simpleform\elements\actions\SetSubmissionStatus;
use anvildev\simpleform\elements\db\SubmissionQuery;
use anvildev\simpleform\elements\exporters\SubmissionExporter;
use anvildev\simpleform\Plugin;
use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\actions\Delete;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

/**
 * @phpstan-type SubmissionData array<string, array{label: string, type: string, value: mixed}>
 * @phpstan-type FieldSnapshot array<string, array{handle: string, label: string, type: string, order: int, options?: array<string, string>}>
 */
class Submission extends Element
{
    /** Payment is required but not yet settled. */
    public const PAYMENT_PENDING = 'pending';
    /** Payment has settled. */
    public const PAYMENT_PAID = 'paid';
    /** A pending payment was abandoned/expired (or its order was canceled) before settling. */
    public const PAYMENT_CANCELED = 'canceled';

    public ?int $formId = null;
    /** @var SubmissionData|null */
    public ?array $data = null;
    /**
     * Immutable snapshot of the form's input-field structure captured at submit
     * time (#312): handle, label, type, option labels, and display order, keyed by
     * `field_<id>`. Renders the detail view and CSV export so a later rename,
     * reorder, or delete never corrupts this submission. Null on pre-snapshot
     * rows, which fall back to the labels/order stored inline on {@see self::$data}.
     *
     * @var FieldSnapshot|null
     */
    public ?array $fieldSnapshot = null;
    public ?int $userId = null;
    public string $readStatus = SubmissionStatus::NEW;
    /** Why this submission is flagged spam: 'akismet', 'manual', a denylist reason, 'duplicate', or null. */
    public ?string $spamReason = null;
    /** Submitter's source IP, captured at submit time and subject to `ipCapturePolicy` masking (#140, #315). */
    public ?string $sourceIp = null;
    /**
     * SHA-256 hash of the submitter's *full* IP, independent of `sourceIp`'s
     * display masking (#326, fixing #315). Used exclusively for the `ip`
     * duplicate-detection key so anonymized-mode masking can't collapse
     * distinct visitors into false-positive duplicates. Never reversible to
     * the original IP.
     */
    public ?string $ipHash = null;
    /**
     * SHA-256 of this submission's dedupe fingerprint (#341), recomputed on save
     * from the form's `duplicateKey`. Lets duplicate detection be an indexed
     * `(formId, dedupeHash)` lookup instead of rehydrating the form's history.
     * Null when no fingerprint is derivable (e.g. email key with no email value).
     */
    public ?string $dedupeHash = null;
    /**
     * SHA-256 of the normalized first-email-field value (#341), recomputed on
     * save. Lets the per-guest email cap be an indexed `(formId, guestEmailHash)`
     * count instead of a JSON `LIKE`. Null when the submission has no email value.
     */
    public ?string $guestEmailHash = null;
    /** null = no payment; self::PAYMENT_PENDING = awaiting; self::PAYMENT_PAID = complete. */
    public ?string $paymentStatus = null;
    public ?string $paymentAmount = null;
    public ?int $orderId = null;
    /** Owner-defined approval-workflow stage handle (#248), null = not in a pipeline. */
    public ?string $workflowStatus = null;
    /** Applied discount code (#246), null when none was used. */
    public ?string $couponCode = null;
    /** Discount the coupon took off the amount due (#246). */
    public ?string $discountAmount = null;
    /**
     * Quiz score computed once at submit time (#241), null on non-quiz forms.
     * Stored raw + max + percentage + grade band so the value stays stable even
     * if the form's answer key changes later.
     */
    public ?int $quizScore = null;
    public ?int $quizMaxScore = null;
    public ?int $quizPercentage = null;
    public ?string $quizGrade = null;
    /**
     * Marketing attribution captured at submit when the form opted in (#249):
     * a map of utm_source/medium/campaign/term/content + referrer + landing_page
     * (only the non-empty keys), or null on forms that don't capture it.
     *
     * @var array<string, string>|null
     */
    public ?array $attribution = null;
    /** SHA-256 hash of the active front-end edit token; the token itself lives only in the edit URL. */
    public ?string $editTokenHash = null;
    /** Absolute expiry of the edit token (UTC), or null when no token is active. */
    public ?string $editTokenExpires = null;

    /**
     * Memoized {@see self::getDisplayData()} result. Lazily populated on first
     * call so a caller that reads it several times per submission (e.g. the CSV
     * exporter, #323) doesn't repeat the relabel/reorder work. Reset via
     * {@see self::resetDisplayDataCache()} whenever `$data`/`$fieldSnapshot` are
     * reassigned after the first read (see {@see \anvildev\simpleform\services\SubmissionService::update()}).
     *
     * @var SubmissionData|null
     */
    private ?array $_displayData = null;

    public static function displayName(): string
    {
        return Craft::t('simple-form', 'Submission');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('simple-form', 'Submissions');
    }

    /** Whether this submission has a required payment that hasn't settled yet. */
    public function isAwaitingPayment(): bool
    {
        return $this->paymentStatus === self::PAYMENT_PENDING;
    }

    /** Whether this submission's required payment has settled. */
    public function isPaid(): bool
    {
        return $this->paymentStatus === self::PAYMENT_PAID;
    }

    public static function hasContent(): bool
    {
        return false;
    }

    public static function hasTitles(): bool
    {
        return false;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function find(): SubmissionQuery
    {
        return new SubmissionQuery(static::class);
    }

    public function __toString(): string
    {
        return "Submission #{$this->id}";
    }

    /**
     * The CP detail-view URL, so native element-index rows (and relation chips)
     * link straight to the submission. The view is read-only, but it is the
     * canonical "open this submission" target.
     */
    public function getCpEditUrl(): ?string
    {
        return $this->id !== null ? UrlHelper::cpUrl('simple-form/submissions/' . $this->id) : null;
    }

    public function getForm(): ?Form
    {
        if ($this->formId === null || $this->formId <= 0) {
            return null;
        }

        // Serve the eager-loaded form when the query was run with `.with(['form'])`,
        // avoiding a per-submission query.
        if ($this->hasEagerLoadedElements('form')) {
            $eager = $this->getEagerLoadedElements('form')?->first();
            return $eager instanceof Form ? $eager : null;
        }

        // An absent form already yields null from `->one()` without throwing, so
        // there is no try/catch here: a genuine query/infrastructure failure is
        // left to propagate as a clear DB error instead of being masked as a
        // confusing "form not found" state.
        return Form::find()->id($this->formId)->one();
    }

    /**
     * The submission's stored `data`, relabeled and reordered to honor the field
     * snapshot captured at submit time (#312).
     *
     * When a {@see self::$fieldSnapshot} is present, each entry's label and the
     * overall row order come from the snapshot — the authoritative record of how
     * the form looked when this submission was made — so a later rename, reorder,
     * or delete of a live field never changes how this historical submission
     * reads. Entries with no matching snapshot key (and every entry on a
     * pre-snapshot row, where the snapshot is null) fall back to the label and
     * order already stored inline on `data`. The entry shape (`type`, `value`,
     * `display`, …) is otherwise preserved so the detail view and exporter keep
     * rendering each field type exactly as before.
     *
     * Memoized on the instance ({@see self::$_displayData}) since `data` and
     * `fieldSnapshot` are immutable per load — a caller that reads this more than
     * once per submission (e.g. the CSV exporter) doesn't repeat the work.
     *
     * @return SubmissionData
     */
    public function getDisplayData(): array
    {
        if ($this->_displayData !== null) {
            return $this->_displayData;
        }

        $data = is_array($this->data) ? $this->data : [];
        $snapshot = $this->fieldSnapshot;
        if ($snapshot === null || $snapshot === []) {
            return $this->_displayData = $data;
        }

        $ordered = [];

        // Snapshot-ordered entries first, relabeled from the snapshot.
        foreach ($snapshot as $key => $field) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $entry = $data[$key];
            if (is_array($entry)) {
                $entry['label'] = $field['label'];
            }
            $ordered[$key] = $entry;
        }

        // Any stored entry the snapshot doesn't cover (defensive: a field type
        // that stored data without a snapshot row) keeps its inline label/order.
        foreach ($data as $key => $entry) {
            if (!array_key_exists($key, $ordered)) {
                $ordered[$key] = $entry;
            }
        }

        return $this->_displayData = $ordered;
    }

    /**
     * Invalidate the {@see self::getDisplayData()} memo. Call after reassigning
     * {@see self::$data} or {@see self::$fieldSnapshot} on an instance that may
     * already have been read (e.g. {@see \anvildev\simpleform\services\SubmissionService::update()}
     * rewriting an existing submission's content) so the next read reflects the
     * new values instead of a stale cache.
     */
    public function resetDisplayDataCache(): void
    {
        $this->_displayData = null;
    }

    /**
     * Map the submission→form relationship so `Submission::find()->with(['form'])`
     * batch-loads parent forms in a bounded number of queries (Craft's standard
     * eager-loading mechanism).
     *
     * @param self[] $sourceElements
     * @return array<string,mixed>|null|false
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle === 'form') {
            $map = [];
            foreach ($sourceElements as $submission) {
                if ($submission->formId !== null && $submission->formId > 0) {
                    $map[] = ['source' => $submission->id, 'target' => $submission->formId];
                }
            }

            return [
                'elementType' => Form::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    /**
     * Persist the submission's custom columns. A Craft element only writes its
     * `elements`/`elements_sites` rows automatically; the plugin-owned row in
     * `simpleform_submissions` (formId, siteId, data, userId, readStatus) must be
     * written here, mirroring the pattern used by the Form element. Without this,
     * saveElement() creates an element row but no submission row, so the
     * SubmissionQuery (which INNER-joins simpleform_submissions) returns nothing.
     */
    public function afterSave(bool $isNew): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        // Recompute the indexed dedupe/guest-email hashes from the current data on
        // every save, so an edited submission re-keys correctly (#341).
        $submissions = Plugin::getInstance()->getSubmissionService();
        $form = $this->getForm();
        $this->dedupeHash = $form !== null
            ? $submissions->computeDedupeHash($form, $this->data ?? [], $this->ipHash)
            : null;
        $this->guestEmailHash = $submissions->computeGuestEmailHash($this->data ?? []);

        $row = [
            'formId' => $this->formId,
            'siteId' => $this->siteId,
            // Craft's json() column type encodes the array exactly once; pass the array.
            'data' => $this->data,
            'fieldSnapshot' => $this->fieldSnapshot,
            'userId' => $this->userId,
            'readStatus' => $this->readStatus,
            'workflowStatus' => $this->workflowStatus,
            'spamReason' => $this->spamReason,
            'sourceIp' => $this->sourceIp,
            'ipHash' => $this->ipHash,
            'dedupeHash' => $this->dedupeHash,
            'guestEmailHash' => $this->guestEmailHash,
            'paymentStatus' => $this->paymentStatus,
            'paymentAmount' => $this->paymentAmount,
            'orderId' => $this->orderId,
            'couponCode' => $this->couponCode,
            'discountAmount' => $this->discountAmount,
            'quizScore' => $this->quizScore,
            'quizMaxScore' => $this->quizMaxScore,
            'quizPercentage' => $this->quizPercentage,
            'quizGrade' => $this->quizGrade,
            'attribution' => $this->attribution,
            'editTokenHash' => $this->editTokenHash,
            'editTokenExpires' => $this->editTokenExpires,
            'dateUpdated' => $now,
        ];

        $exists = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $this->id])
            ->exists();

        if (!$exists) {
            $db->createCommand()->insert('{{%simpleform_submissions}}', $row + [
                'id' => $this->id,
                'dateCreated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        } else {
            $db->createCommand()->update('{{%simpleform_submissions}}', $row, ['id' => $this->id])->execute();
        }

        parent::afterSave($isNew);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'form' => ['label' => Craft::t('simple-form', 'Form')],
            'dateCreated' => ['label' => Craft::t('simple-form', 'Date')],
            'readStatus' => ['label' => Craft::t('simple-form', 'Status')],
            'workflow' => ['label' => Craft::t('simple-form', 'Stage')],
            'spamReason' => ['label' => Craft::t('simple-form', 'Spam reason')],
            'payment' => ['label' => Craft::t('simple-form', 'Payment')],
            'userId' => ['label' => Craft::t('simple-form', 'User')],
        ];
    }

    /**
     * Render the `payment` column as a small, translated status pill. Submissions
     * with no payment requirement (`paymentStatus === null`) show a neutral dash so
     * the column reads cleanly in mixed indexes. All other attributes fall through
     * to the core renderer.
     *
     * @throws \yii\base\InvalidConfigException from {@see parent::attributeHtml()}.
     */
    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'form' => $this->formHtml(),
            'userId' => $this->userHtml(),
            'readStatus' => $this->readStatusHtml(),
            'workflow' => $this->workflowHtml(),
            'spamReason' => $this->spamReason !== null && $this->spamReason !== ''
                ? Html::tag('span', Html::encode($this->spamReason))
                : Html::tag('span', '—', ['class' => 'light']),
            'payment' => $this->paymentHtml(),
            default => parent::attributeHtml($attribute),
        };
    }

    /** The parent form's title, linking to that form's filtered submissions. */
    private function formHtml(): string
    {
        $form = $this->getForm();
        if ($form === null) {
            return Html::tag('span', '#' . $this->formId, ['class' => 'light']);
        }

        return Html::a(
            Html::encode((string) ($form->title ?? $form->name)),
            UrlHelper::cpUrl('simple-form/submissions', ['formId' => $this->formId, 'status' => 'all']),
        );
    }

    /** The submitter as an element chip, or a dash for anonymous submissions. */
    private function userHtml(): string
    {
        if ($this->userId === null) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        $user = Craft::$app->getUsers()->getUserById($this->userId);
        return $user !== null
            ? Cp::elementChipHtml($user)
            : Html::tag('span', '#' . $this->userId, ['class' => 'light']);
    }

    /** Status dot + label, matching the CP submissions listing. */
    private function readStatusHtml(): string
    {
        return Html::tag('span', '', ['class' => "status {$this->readStatus}"])
            . Html::tag('span', StringHelper::titleize($this->readStatus));
    }

    /** The approval-workflow stage pill, or a dash when the row isn't in a pipeline. */
    private function workflowHtml(): string
    {
        if ($this->workflowStatus === null || $this->workflowStatus === '') {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        foreach (Plugin::getInstance()->getWorkflow()->getStatuses() as $stage) {
            if ($stage['handle'] === $this->workflowStatus) {
                return Html::tag('span', '', ['class' => 'status ' . $stage['color']])
                    . Html::tag('span', Html::encode($stage['label']));
            }
        }

        return Html::tag('span', Html::encode($this->workflowStatus));
    }

    /**
     * Render the `payment` column as a small, translated status pill. Submissions
     * with no payment requirement (`paymentStatus === null`) show a neutral dash.
     */
    private function paymentHtml(): string
    {
        if ($this->paymentStatus === null) {
            return Html::tag('span', '—', ['class' => 'light']);
        }

        // match evaluates only the hit arm, so a single Craft::t runs per row.
        return Html::tag(
            'span',
            match ($this->paymentStatus) {
                self::PAYMENT_PENDING => Craft::t('simple-form', 'Pending'),
                self::PAYMENT_PAID => Craft::t('simple-form', 'Paid'),
                self::PAYMENT_CANCELED => Craft::t('simple-form', 'Canceled'),
                default => StringHelper::titleize($this->paymentStatus),
            },
            ['class' => "status-label status-{$this->paymentStatus}"],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected static function defineSortOptions(): array
    {
        return [
            'dateCreated' => Craft::t('simple-form', 'Date'),
            'readStatus' => Craft::t('simple-form', 'Status'),
            'payment' => [
                'label' => Craft::t('simple-form', 'Payment'),
                'orderBy' => 'simpleform_submissions.paymentStatus',
                'attribute' => 'payment',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['form', 'dateCreated', 'readStatus'];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function defineActions(?string $source = null): array
    {
        return [
            ['type' => SetSubmissionStatus::class, 'status' => SubmissionStatus::READ],
            ['type' => SetSubmissionStatus::class, 'status' => SubmissionStatus::ARCHIVED],
            ['type' => SetSubmissionStatus::class, 'status' => SubmissionStatus::SPAM],
            Delete::class,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function defineExporters(string $source): array
    {
        $exporters = parent::defineExporters($source);
        $exporters[] = SubmissionExporter::class;
        return $exporters;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('simple-form', 'All Submissions'),
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            ['heading' => Craft::t('simple-form', 'Status')],
            ['key' => 'status:new', 'label' => Craft::t('simple-form', 'New'), 'criteria' => ['readStatus' => SubmissionStatus::NEW]],
            ['key' => 'status:read', 'label' => Craft::t('simple-form', 'Read'), 'criteria' => ['readStatus' => SubmissionStatus::READ]],
            ['key' => 'status:archived', 'label' => Craft::t('simple-form', 'Archived'), 'criteria' => ['readStatus' => SubmissionStatus::ARCHIVED]],
            ['key' => 'status:spam', 'label' => Craft::t('simple-form', 'Spam'), 'criteria' => ['readStatus' => SubmissionStatus::SPAM]],
        ];

        $forms = Form::find()->all();
        if ($forms !== []) {
            $sources[] = ['heading' => Craft::t('simple-form', 'Forms')];
            foreach ($forms as $form) {
                $sources[] = [
                    'key' => 'form:' . $form->id,
                    'label' => $form->title ?? $form->name,
                    'criteria' => ['formId' => $form->id],
                ];
            }
        }

        // Recoverable deletes: a Trashed source so soft-deleted submissions can be
        // reviewed and restored (Craft adds the Restore/Delete-permanently actions).
        $sources[] = ['heading' => Craft::t('simple-form', 'Trash')];
        $sources[] = [
            'key' => 'trashed',
            'label' => Craft::t('simple-form', 'Trashed'),
            'criteria' => ['trashed' => true],
        ];

        return $sources;
    }
}
