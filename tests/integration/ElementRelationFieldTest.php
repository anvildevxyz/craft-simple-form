<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\fields\CategoryRelationFieldType;
use anvildev\simpleform\helpers\SubmissionCsv;
use Craft;
use craft\elements\Category;
use craft\helpers\StringHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;

/**
 * Exercises the element-relation field type against real Craft elements: a
 * category from the allowed group validates and its id round-trips into the
 * submission data, a forged id from a disallowed group is rejected, and the CSV
 * export resolves the stored ids back to element titles.
 *
 * Category groups are the lightest self-contained source to seed; the same
 * membership logic backs the entry/tag/user/asset variants.
 *
 * @group requires-craft
 */
class ElementRelationFieldTest extends SimpleFormTestCase
{
    public function testAllowedIdValidatesAndDisallowedIdIsRejected(): void
    {
        $this->requireCraft();

        $allowedGroup = $this->createCategoryGroup('Allowed Group');
        $deniedGroup = $this->createCategoryGroup('Denied Group');

        $allowedCategory = $this->createCategory($allowedGroup->id, 'Allowed Category');
        $deniedCategory = $this->createCategory($deniedGroup->id, 'Denied Category');

        $field = new CategoryRelationFieldType([
            'sources' => [$allowedGroup->handle],
            'multiple' => false,
            'required' => true,
        ]);

        // Membership: the allowed-id set holds only the allowed group's category.
        $allowedIds = $field->allowedIds();
        $this->assertContains((int) $allowedCategory->id, $allowedIds);
        $this->assertNotContains((int) $deniedCategory->id, $allowedIds);

        // A category from the allowed group passes validation.
        $this->assertSame([], $field->validate((string) $allowedCategory->id));

        // A forged id from a disallowed group is rejected.
        $errors = $field->validate((string) $deniedCategory->id);
        $this->assertNotSame([], $errors);
        $this->assertContains('Please select a valid option.', $errors);

        // A non-existent id is rejected too.
        $this->assertNotSame([], $field->validate('424242'));
    }

    public function testValidSelectionStoresIdsAndExportResolvesTitles(): void
    {
        $this->requireCraft();

        $group = $this->createCategoryGroup('Topics');
        $category = $this->createCategory($group->id, 'Billing');

        $form = $this->createForm('Enquiry', 'relEnquiryForm', 'Enquiry');
        $fieldId = $this->createField(
            $form->id,
            'category',
            'topic',
            'Topic',
            true,
            ['sources' => [$group->handle], 'multiple' => false],
        );

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'relEnquiryForm',
            'field_' . $fieldId => (string) $category->id,
        ]);

        $result = \anvildev\simpleform\Plugin::getInstance()
            ->get('submissionService')
            ->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        // The stored value is the live element id.
        $reloaded = Submission::find()->id($result['submission']->id)->one();
        $this->assertNotNull($reloaded);
        $stored = $reloaded->data['field_' . $fieldId]['value'];
        $this->assertSame((int) $category->id, (int) (is_array($stored) ? $stored[0] : $stored));

        // CSV export resolves the id back to the element title.
        $csv = SubmissionCsv::fromSubmissions([$reloaded]);
        $this->assertStringContainsString('Billing', $csv);
    }

    public function testForgedDisallowedIdSubmissionIsRejected(): void
    {
        $this->requireCraft();

        $allowedGroup = $this->createCategoryGroup('Allowed');
        $deniedGroup = $this->createCategoryGroup('Denied');
        $this->createCategory($allowedGroup->id, 'Keep');
        $denied = $this->createCategory($deniedGroup->id, 'Forge');

        $form = $this->createForm('Forge', 'relForgeForm', 'Forge');
        $fieldId = $this->createField(
            $form->id,
            'category',
            'topic',
            'Topic',
            true,
            ['sources' => [$allowedGroup->handle], 'multiple' => false],
        );

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'relForgeForm',
            // Post a category that exists but is outside the allowed group.
            'field_' . $fieldId => (string) $denied->id,
        ]);

        $result = \anvildev\simpleform\Plugin::getInstance()
            ->get('submissionService')
            ->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testMultiSelectLimitIsEnforced(): void
    {
        $this->requireCraft();

        $group = $this->createCategoryGroup('Many');
        $a = $this->createCategory($group->id, 'A');
        $b = $this->createCategory($group->id, 'B');
        $c = $this->createCategory($group->id, 'C');

        $field = new CategoryRelationFieldType([
            'sources' => [$group->handle],
            'multiple' => true,
            'limit' => 2,
        ]);

        // Within the limit passes.
        $this->assertSame([], $field->validate([(string) $a->id, (string) $b->id]));

        // Over the limit fails.
        $errors = $field->validate([(string) $a->id, (string) $b->id, (string) $c->id]);
        $this->assertContains('Please select no more than 2 options.', $errors);
    }

    /**
     * Create and persist a category group (URL-less) across all sites.
     */
    private function createCategoryGroup(string $name): CategoryGroup
    {
        $handle = 'sfRel' . StringHelper::UUID();
        $handle = 'g' . substr(preg_replace('/[^a-zA-Z0-9]/', '', $handle), 0, 20);

        $group = new CategoryGroup();
        $group->name = $name;
        $group->handle = $handle;

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $settings = new CategoryGroup_SiteSettings();
            $settings->siteId = $site->id;
            $settings->hasUrls = false;
            $siteSettings[$site->id] = $settings;
        }
        $group->setSiteSettings($siteSettings);

        $saved = Craft::$app->getCategories()->saveGroup($group);
        $this->assertTrue($saved, 'Category group should save');

        return $group;
    }

    /**
     * Create and persist a category in the given group.
     */
    private function createCategory(int $groupId, string $title): Category
    {
        $category = new Category();
        $category->groupId = $groupId;
        $category->title = $title;

        $saved = Craft::$app->getElements()->saveElement($category);
        $this->assertTrue($saved, 'Category should save: ' . implode(', ', $category->getFirstErrors()));

        return $category;
    }
}
