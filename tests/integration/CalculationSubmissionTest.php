<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FieldSyncService;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * Server-side authority of the Calculation field (#131) through the real
 * submission entry point. Covers the security guarantees: the stored value is
 * always the server computation (a forged client value is ignored), a linked
 * Payment amount reads the computed total, a calculation hidden by conditional
 * logic neither computes nor stores, and save-time validation rejects bad
 * formulas / unknown handles / cycles.
 *
 * @group requires-craft
 */
class CalculationSubmissionTest extends SimpleFormTestCase
{
    public function testServerRecomputeStoresComputedValueWithDisplay(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Calc Order', 'calcOrderForm', 'Calc Order');
        $qtyId = $this->createField($form->id, 'number', 'quantity', 'Quantity');
        $priceId = $this->createField($form->id, 'number', 'unitPrice', 'Unit price');
        $totalId = $this->createField($form->id, 'calculation', 'total', 'Total', false, [
            'formula' => '{quantity} * {unitPrice}',
            'decimals' => 2,
            'prefix' => 'CHF ',
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'calcOrderForm',
            'field_' . $qtyId => '3',
            'field_' . $priceId => '10',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $decoded = $this->storedData((int) $result['submission']->id);
        $this->assertSame(30.0, $decoded['field_' . $totalId]['value']);
        $this->assertSame('CHF 30.00', $decoded['field_' . $totalId]['display']);
    }

    public function testForgedClientValueIsIgnored(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Calc Forge', 'calcForgeForm', 'Calc Forge');
        $qtyId = $this->createField($form->id, 'number', 'quantity', 'Quantity');
        $priceId = $this->createField($form->id, 'number', 'unitPrice', 'Unit price');
        $totalId = $this->createField($form->id, 'calculation', 'total', 'Total', false, [
            'formula' => '{quantity} * {unitPrice}',
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'calcForgeForm',
            'field_' . $qtyId => '3',
            'field_' . $priceId => '10',
            'field_' . $totalId => '0.01', // forged total
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $decoded = $this->storedData((int) $result['submission']->id);
        $this->assertSame(30.0, $decoded['field_' . $totalId]['value'], 'Forged client total must be discarded');
    }

    public function testHiddenCalculationNeitherComputesNorStores(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Calc Hidden', 'calcHiddenForm', 'Calc Hidden');
        $modeId = $this->createField($form->id, 'select', 'mode', 'Mode', false, [
            'options' => [
                ['value' => 'basic', 'label' => 'Basic'],
                ['value' => 'pro', 'label' => 'Pro'],
            ],
        ]);
        $qtyId = $this->createField($form->id, 'number', 'quantity', 'Quantity');
        // Total only shown when mode is "pro".
        $totalId = $this->createField($form->id, 'calculation', 'total', 'Total', false, [
            'formula' => '{quantity} * 100',
            'conditional' => [
                'enabled' => true,
                'action' => 'show',
                'match' => 'all',
                'rules' => [['field' => 'mode', 'operator' => 'eq', 'value' => 'pro']],
            ],
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'calcHiddenForm',
            'field_' . $modeId => 'basic', // total stays hidden
            'field_' . $qtyId => '5',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $decoded = $this->storedData((int) $result['submission']->id);
        $this->assertArrayNotHasKey('field_' . $totalId, $decoded, 'Hidden calculation must not be stored');
    }

    public function testLinkedPaymentAmountReadsComputedTotal(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Calc Pay', 'calcPayForm', 'Calc Pay');
        $qtyId = $this->createField($form->id, 'number', 'quantity', 'Quantity');
        $priceId = $this->createField($form->id, 'number', 'unitPrice', 'Unit price');
        $totalId = $this->createField($form->id, 'calculation', 'total', 'Total', false, [
            'formula' => '{quantity} * {unitPrice}',
        ]);
        $this->createField($form->id, 'payment', 'payment', 'Payment', false, [
            'amountType' => 'field',
            'amountField' => 'total',
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'calcPayForm',
            'field_' . $qtyId => '4',
            'field_' . $priceId => '7.5',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        // The Payment service resolves its amount from the stored (server) total.
        $decoded = $this->storedData((int) $result['submission']->id);
        $amount = Plugin::getInstance()->getPayments()->resolveAmount($form, $decoded);
        $this->assertSame(30.0, $amount);

        unset($totalId);
    }

    public function testSaveTimeValidationRejectsBadFormulas(): void
    {
        $this->requireCraft();
        $sync = new FieldSyncService();

        // Unknown handle.
        $errors = $sync->validate([
            $this->calcItem('total', 'Total', '{nope} + 1'),
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsStringIgnoringCase('unknown field', implode(' ', $errors));

        // Syntax error.
        $errors = $sync->validate([
            $this->calcItem('total', 'Total', 'phpinfo()'),
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsStringIgnoringCase('invalid', implode(' ', $errors));

        // Empty formula.
        $errors = $sync->validate([
            $this->calcItem('total', 'Total', ''),
        ]);
        $this->assertNotEmpty($errors);

        // Cycle between two calculations.
        $errors = $sync->validate([
            $this->calcItem('a', 'A', '{b} + 1'),
            $this->calcItem('b', 'B', '{a} + 1'),
        ]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsStringIgnoringCase('circular', implode(' ', $errors));
    }

    public function testSaveTimeValidationAcceptsValidFormula(): void
    {
        $this->requireCraft();
        $sync = new FieldSyncService();

        $errors = $sync->validate([
            $this->item('quantity', 'Quantity', 'number'),
            $this->calcItem('total', 'Total', '{quantity} * 2'),
        ]);
        $this->assertSame([], $errors);
    }

    // --- helpers ---------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function storedData(int $submissionId): array
    {
        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $submissionId])->one();
        return is_array($row['data']) ? $row['data'] : (json_decode((string) $row['data'], true) ?? []);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function item(string $handle, string $label, string $type, array $config = []): array
    {
        return [
            'id' => null,
            'type' => $type,
            'handle' => $handle,
            'label' => $label,
            'required' => false,
            'helpText' => '',
            'errorMessage' => '',
            'config' => $config,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calcItem(string $handle, string $label, string $formula): array
    {
        return $this->item($handle, $label, 'calculation', ['formula' => $formula]);
    }

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}
