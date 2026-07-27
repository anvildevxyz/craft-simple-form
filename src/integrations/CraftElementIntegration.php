<?php

namespace anvildev\simpleform\integrations;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\support\ElementMapping;
use anvildev\simpleform\integrations\support\SubmissionValues;
use anvildev\simpleform\Plugin;
use Craft;
use craft\elements\Entry;
use craft\elements\User;

/**
 * Create a native Craft element (Entry or User) from a submission. Unlike the
 * external connectors there is no HTTP call: {@see send()} builds and saves an
 * element through Craft's element API. It still flows through the standard async
 * dispatch (`SendIntegrationJob`), retry, and dispatch-log framework — so a
 * validation failure surfaces as a retryable failed log row while the submission
 * row itself stays saved.
 *
 * Settings:
 * - `elementType` — `entry` | `user`.
 * - Entry: `sectionUid`, `entryTypeUid`, `titleTemplate`, `authorMode`
 *   (`submitter` | `fixed`), `authorId`, `entryStatus` (`live`|`pending`|`disabled`).
 * - User: `groupUid`, `userStatus` (`active`|`pending`|`suspended`).
 * - `fieldMapping` — `submissionHandle => targetHandle` onto native attributes
 *   (title/slug/email/username) and the target's custom fields.
 *
 * @phpstan-import-type SelectOption from ElementMapping
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class CraftElementIntegration implements IntegrationTypeInterface
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** Native Entry attribute handles offered as mapping targets. */
    public const ENTRY_ATTRIBUTES = ['title', 'slug'];

    /** Native User attribute handles offered as mapping targets. */
    public const USER_ATTRIBUTES = ['email', 'username', 'fullName'];

    public const ELEMENT_TYPES = ['entry', 'user'];

    private const ENTRY_STATUSES = ['live', 'pending', 'disabled'];

    private const USER_STATUSES = ['active', 'pending', 'suspended'];

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function handle(): string
    {
        return 'craft-element';
    }

    public static function displayName(): string
    {
        return 'Create Craft Element';
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function settingsHtml(array $settings): string
    {
        // Settings render in the CP; force the CP template mode so the
        // `simple-form/...` root resolves even when this is reached from a
        // non-CP request context.
        return Craft::$app->getView()->renderTemplate(
            'simple-form/integrations/craft-element/settings',
            [
                'settings' => $settings,
                'elementTypeOptions' => $this->elementTypeOptions(),
                'sectionOptions' => ElementMapping::sectionOptions(),
                'entryTypeOptions' => ElementMapping::entryTypeOptions(),
                'groupOptions' => ElementMapping::userGroupOptions(),
                'authorModeOptions' => $this->authorModeOptions(),
                'entryStatusOptions' => $this->statusOptions(self::ENTRY_STATUSES),
                'userStatusOptions' => $this->statusOptions(self::USER_STATUSES),
            ],
            \craft\web\View::TEMPLATE_MODE_CP,
        );
    }

    public function defineSettingsRules(): array
    {
        return [
            [['elementType'], 'required'],
            [['elementType'], 'in', 'range' => self::ELEMENT_TYPES],
            [['sectionUid', 'entryTypeUid', 'groupUid', 'titleTemplate', 'authorMode', 'authorId', 'entryStatus', 'userStatus', 'fieldMapping'], 'safe'],
        ];
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $type = (string) ($settings['elementType'] ?? '');
        if (!in_array($type, self::ELEMENT_TYPES, true)) {
            return IntegrationResult::failure(null, Craft::t('simple-form', 'No element type configured.'));
        }

        // Resend idempotency (#142): if a prior successful dispatch of this
        // integration already created an element for this submission, link the
        // existing one rather than creating a duplicate.
        if ($submission->id !== null) {
            $integrationId = $this->resolveIntegrationId($settings);
            if ($integrationId !== null) {
                $linked = Plugin::getInstance()->getIntegrations()->getLinkedElement($integrationId, (int) $submission->id);
                if ($linked !== null) {
                    return IntegrationResult::success(null, Craft::t('simple-form', 'Element already created (#{id}); skipped duplicate.', ['id' => $linked['id']]))
                        ->withElement($linked['id'], $linked['type']);
                }
            }
        }

        return $type === 'user'
            ? $this->createUser($submission, $settings)
            : $this->createEntry($submission, $settings);
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Build, validate, and save an Entry from the submission.
     *
     * @param array<string, mixed> $settings
     */
    protected function createEntry(Submission $submission, array $settings): IntegrationResult
    {
        $sectionUid = (string) ($settings['sectionUid'] ?? '');
        $entryTypeUid = (string) ($settings['entryTypeUid'] ?? '');

        $section = $sectionUid !== '' ? Craft::$app->getEntries()->getSectionByUid($sectionUid) : null;
        if ($section === null) {
            return IntegrationResult::failure(null, Craft::t('simple-form', 'The configured section no longer exists.'));
        }

        $entryTypes = $section->getEntryTypes();
        $entryType = null;
        foreach ($entryTypes as $candidate) {
            if ($candidate->uid === $entryTypeUid) {
                $entryType = $candidate;
                break;
            }
        }
        $entryType ??= $entryTypes[0] ?? null;
        if ($entryType === null) {
            return IntegrationResult::failure(null, Craft::t('simple-form', 'The configured section has no entry types.'));
        }

        $values = SubmissionValues::byHandle($submission);

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->setTypeId($entryType->id);
        // Create on the submission's site; the section's propagation settings then
        // govern whether it propagates to others.
        if ($submission->siteId !== null) {
            $entry->siteId = $submission->siteId;
        }
        $entry->setAuthorId($this->resolveAuthorId($submission, $settings));

        $status = (string) ($settings['entryStatus'] ?? 'live');
        $entry->enabled = $status !== 'disabled';

        $this->applyMapping($entry, $values, $settings, self::ENTRY_ATTRIBUTES);

        // The title template wins over a mapped `title`: it's the explicit derived
        // title, applied after the mapping so it isn't clobbered.
        $title = $this->renderTitle($settings, $values, $submission);
        if ($title !== null) {
            $entry->title = $title;
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            return IntegrationResult::failure(null, ElementMapping::summariseErrors($entry->getErrors()));
        }

        return IntegrationResult::success(null, Craft::t('simple-form', 'Created entry #{id}.', ['id' => (int) $entry->id]))
            ->withElement((int) $entry->id, Entry::class);
    }

    /**
     * Build, validate, and save a User from the submission.
     *
     * @param array<string, mixed> $settings
     */
    protected function createUser(Submission $submission, array $settings): IntegrationResult
    {
        $values = SubmissionValues::byHandle($submission);

        $user = new User();
        $status = (string) ($settings['userStatus'] ?? 'pending');
        if ($status === 'suspended') {
            $user->suspended = true;
        }
        // `active` skips Craft's activation flow; `pending` (the default) leaves the
        // account inactive so the site's user/registration settings can govern it.
        if ($status === 'active') {
            $user->pending = false;
        }

        $this->applyMapping($user, $values, $settings, self::USER_ATTRIBUTES);

        if ($user->username === null && $user->email !== null) {
            $user->username = $user->email;
        }

        if (!Craft::$app->getElements()->saveElement($user)) {
            return IntegrationResult::failure(null, ElementMapping::summariseErrors($user->getErrors()));
        }

        $groupUid = (string) ($settings['groupUid'] ?? '');
        if ($groupUid !== '') {
            $group = Craft::$app->getUserGroups()->getGroupByUid($groupUid);
            if ($group !== null) {
                Craft::$app->getUsers()->assignUserToGroups((int) $user->id, [$group->id]);
            }
        }

        return IntegrationResult::success(null, Craft::t('simple-form', 'Created user #{id}.', ['id' => (int) $user->id]))
            ->withElement((int) $user->id, User::class);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Apply the configured `submissionHandle => targetHandle` mapping onto an
     * element: native attributes named in `$attributes` are set directly, the
     * rest are treated as custom-field handles.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $settings
     * @param list<string> $attributes
     */
    private function applyMapping(Entry|User $element, array $values, array $settings, array $attributes): void
    {
        $mapping = self::normalizeMapping($settings['fieldMapping'] ?? []);
        if ($mapping === []) {
            return;
        }

        $customValues = [];
        foreach ($mapping as $handle => $target) {
            if (!is_string($target) || $target === '' || !array_key_exists($handle, $values)) {
                continue;
            }
            if (in_array($target, $attributes, true)) {
                $element->{$target} = $values[$handle];
                continue;
            }
            $customValues[$target] = $values[$handle];
        }

        if ($customValues !== []) {
            $element->setFieldValues($customValues);
        }
    }

    /**
     * Render the Entry title from a Twig template against the submission values.
     * Falls back to a mapped `title` attribute (handled by the mapping) when no
     * template is configured.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $values
     */
    private function renderTitle(array $settings, array $values, Submission $submission): ?string
    {
        $template = trim((string) ($settings['titleTemplate'] ?? ''));
        if ($template === '') {
            return null;
        }

        try {
            // Route through the forced-sandbox seam ({@see SafeRenderService}) —
            // never the raw View::renderString(), which would expose `craft.app`,
            // the database and the filesystem to an editor holding only the
            // (non-admin) `manageIntegrations` permission.
            $rendered = Plugin::getInstance()->getSafeRender()->render(
                $template,
                [
                    'values' => $values,
                    'submission' => $submission,
                ] + $values,
                [Submission::class],
            );
        } catch (\Throwable $e) {
            Craft::warning('Failed to render element title template: ' . $e->getMessage(), 'simple-form');
            return null;
        }

        $rendered = trim($rendered);
        return $rendered !== '' ? $rendered : null;
    }

    /**
     * Resolve the Entry author: the submitting user when logged in (and the
     * connector is in `submitter` mode), else the configured fixed author, else
     * the current/first admin.
     *
     * @param array<string, mixed> $settings
     */
    private function resolveAuthorId(Submission $submission, array $settings): ?int
    {
        $mode = (string) ($settings['authorMode'] ?? 'submitter');

        if ($mode === 'submitter' && $submission->userId !== null) {
            return (int) $submission->userId;
        }

        $authorId = $settings['authorId'] ?? null;
        if (is_array($authorId)) {
            $authorId = $authorId[0] ?? null;
        }
        if ($authorId !== null && $authorId !== '') {
            return (int) $authorId;
        }

        return null;
    }

    /**
     * The integration id this connector instance was dispatched for, resolved by
     * matching the env-parsed settings back to a stored definition. Needed for
     * the resend-idempotency lookup; absent in unit contexts.
     *
     * @param array<string, mixed> $settings
     */
    private function resolveIntegrationId(array $settings): ?int
    {
        $id = $settings['__integrationId'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /**
     * Normalise the field-mapping setting to a `sourceHandle => targetHandle`
     * map. Accepts both the stored associative shape and the editable-table list
     * shape (`[['source' => …, 'target' => …], …]`) that the settings UI posts.
     *
     * @param mixed $mapping
     * @return array<string, string>
     */
    public static function normalizeMapping(mixed $mapping): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $out = [];
        foreach ($mapping as $key => $value) {
            if (is_array($value)) {
                $source = trim((string) ($value['source'] ?? ''));
                $target = trim((string) ($value['target'] ?? ''));
            } else {
                $source = trim((string) $key);
                $target = trim((string) $value);
            }
            if ($source !== '' && $target !== '') {
                $out[$source] = $target;
            }
        }

        return $out;
    }

    /**
     * @return list<SelectOption>
     */
    private function elementTypeOptions(): array
    {
        return [
            ['label' => Craft::t('simple-form', 'Entry'), 'value' => 'entry'],
            ['label' => Craft::t('simple-form', 'User'), 'value' => 'user'],
        ];
    }

    /**
     * @return list<SelectOption>
     */
    private function authorModeOptions(): array
    {
        return [
            ['label' => Craft::t('simple-form', 'The submitting user (if logged in)'), 'value' => 'submitter'],
            ['label' => Craft::t('simple-form', 'A fixed author'), 'value' => 'fixed'],
        ];
    }

    /**
     * @param list<string> $statuses
     * @return list<SelectOption>
     */
    private function statusOptions(array $statuses): array
    {
        return array_map(
            static fn(string $status): array => ['label' => Craft::t('simple-form', ucfirst($status)), 'value' => $status],
            $statuses,
        );
    }
}
