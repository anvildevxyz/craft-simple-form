<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SubmissionCsv;
use fabianhaef\simpleform\Plugin;

/**
 * #126 — end-to-end coverage of the composite Name/Address field types through
 * the real submission path: serialization (enabled-key clamping + crafted-key
 * drop), per-sub-field validation (required propagation + country membership),
 * and the CSV export flatten (one column per enabled sub-field). Uses only
 * neutral placeholder values.
 *
 * @group requires-craft
 */
class CompositeFieldSubmissionTest extends SimpleFormTestCase
{
    /**
     * A Name (first + last, both required) + Address (line1/city/postalCode/
     * country required, line2 + state off) form.
     *
     * @return array{0: \fabianhaef\simpleform\elements\Form, 1: int, 2: int}
     */
    private function seedForm(string $handle): array
    {
        $form = $this->createForm('Composite', $handle);

        $nameId = $this->createField($form->id, 'name', 'fullName', 'Name', false, [
            'subFields' => [
                'first' => ['enabled' => true, 'required' => true, 'label' => 'First'],
                'middle' => ['enabled' => false, 'required' => false, 'label' => 'Middle'],
                'last' => ['enabled' => true, 'required' => true, 'label' => 'Last'],
            ],
        ]);

        $addressId = $this->createField($form->id, 'address', 'mailing', 'Address', false, [
            'subFields' => [
                'line1' => ['enabled' => true, 'required' => true, 'label' => 'Line 1'],
                'line2' => ['enabled' => false, 'required' => false, 'label' => 'Line 2'],
                'city' => ['enabled' => true, 'required' => true, 'label' => 'City'],
                'state' => ['enabled' => false, 'required' => false, 'label' => 'Region'],
                'postalCode' => ['enabled' => true, 'required' => true, 'label' => 'Postal'],
                'country' => ['enabled' => true, 'required' => true, 'label' => 'Country'],
            ],
        ]);

        return [$form, $nameId, $addressId];
    }

    public function testStoresStructuredMapLimitedToEnabledKeys(): void
    {
        $this->requireCraft();
        [$form, $nameId, $addressId] = $this->seedForm('composite_store');

        $result = Plugin::getInstance()->getSubmissionService()->submit($form, [
            'field_' . $nameId => ['first' => 'First', 'last' => 'Last', 'evil' => 'crafted'],
            'field_' . $addressId => [
                'line1' => '1 Example St',
                'city' => 'Springfield',
                'postalCode' => '00000',
                'country' => 'US',
                // line2/state are disabled — never stored.
                'line2' => 'crafted',
            ],
        ], ['skipCaptcha' => true]);

        $this->assertNotNull($result['submission'], 'Submission should persist.');
        $data = $result['submission']->data;

        // Name: only enabled keys; crafted 'evil' dropped.
        $this->assertSame(['first' => 'First', 'last' => 'Last'], $data['field_' . $nameId]['value']);
        $this->assertSame('name', $data['field_' . $nameId]['type']);

        // Address: disabled line2/state never appear.
        $addr = $data['field_' . $addressId]['value'];
        $this->assertSame(
            ['line1' => '1 Example St', 'city' => 'Springfield', 'postalCode' => '00000', 'country' => 'US'],
            $addr
        );
        $this->assertArrayNotHasKey('line2', $addr);
        $this->assertArrayNotHasKey('state', $addr);
    }

    public function testRequiredSubFieldEmptyBlocksSubmission(): void
    {
        $this->requireCraft();
        [$form, $nameId, $addressId] = $this->seedForm('composite_required');

        $result = Plugin::getInstance()->getSubmissionService()->submit($form, [
            'field_' . $nameId => ['first' => 'First', 'last' => 'Last'],
            'field_' . $addressId => [
                'line1' => '1 Example St',
                'city' => '', // required, blank → error
                'postalCode' => '00000',
                'country' => 'US',
            ],
        ], ['skipCaptcha' => true]);

        $this->assertNull($result['submission'], 'No row when a required sub-field is blank.');
        $this->assertArrayHasKey('field_' . $addressId, $result['errors']);
        // The error names the sub-field's label.
        $this->assertStringContainsString('City', implode(' ', $result['errors']['field_' . $addressId]));
        $this->assertCount(0, Submission::find()->formId($form->id)->all());
    }

    public function testInvalidCountryCodeIsRejected(): void
    {
        $this->requireCraft();
        [$form, $nameId, $addressId] = $this->seedForm('composite_country');

        $result = Plugin::getInstance()->getSubmissionService()->submit($form, [
            'field_' . $nameId => ['first' => 'First', 'last' => 'Last'],
            'field_' . $addressId => [
                'line1' => '1 Example St',
                'city' => 'Springfield',
                'postalCode' => '00000',
                'country' => 'ZZ', // not a real country code
            ],
        ], ['skipCaptcha' => true]);

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('field_' . $addressId, $result['errors']);
        $this->assertStringContainsString('Country', implode(' ', $result['errors']['field_' . $addressId]));
    }

    public function testExportFlattensCompositesIntoPerSubFieldColumns(): void
    {
        $this->requireCraft();
        [$form, $nameId, $addressId] = $this->seedForm('composite_export');

        Plugin::getInstance()->getSubmissionService()->submit($form, [
            'field_' . $nameId => ['first' => 'First', 'last' => 'Last'],
            'field_' . $addressId => [
                'line1' => '1 Example St',
                'city' => 'Springfield',
                'postalCode' => '00000',
                'country' => 'US',
            ],
        ], ['skipCaptcha' => true]);

        $submissions = Submission::find()->formId($form->id)->all();
        $csv = SubmissionCsv::fromSubmissions($submissions);

        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $header = $lines[0];

        // Flattened "<field> — <sub-field>" headers (em dash), not one pipe cell.
        $this->assertStringContainsString('Name — First', $header);
        $this->assertStringContainsString('Name — Last', $header);
        $this->assertStringContainsString('Address — City', $header);
        $this->assertStringContainsString('Address — Country', $header);
        // Disabled sub-fields produce no column.
        $this->assertStringNotContainsString('Line 2', $header);
        $this->assertStringNotContainsString('Region', $header);

        // Values land in their own cells (not joined into one).
        $this->assertStringContainsString('First', $csv);
        $this->assertStringContainsString('Springfield', $csv);
        $this->assertStringNotContainsString('1 Example St|Springfield', $csv);

        // toRows() keys by the same flattened labels.
        $rows = SubmissionCsv::toRows($submissions);
        $this->assertArrayHasKey('Address — City', $rows[0]);
        $this->assertSame('Springfield', $rows[0]['Address — City']);
    }
}
