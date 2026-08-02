<?php

/**
 * Commerce-backed payments, against a live install.
 *
 * The unit suite cannot cover this: Commerce is a soft dependency, so the test
 * app has no gateway, no Donation purchasable and no orders. Everything below
 * therefore runs against the real plugins.
 *
 * The claims under test are the ones in docs/payments.md, in the order that
 * matters if they are wrong:
 *
 *   1. A form that requires payment never yields a paid-looking submission for
 *      free — not when the gateway is missing, not when the charge is declined.
 *   2. A successful charge produces a Commerce order and a `paid` submission.
 *   3. Settling a pending payment releases the notification that was held back
 *      (the holding itself is covered in tests/integration/PaymentsServiceTest).
 *   4. Amount bounds are enforced server-side, not just in the field UI.
 *   5. expire-payments cancels a stale pending submission and leaves paid ones.
 *   6. A coupon changes what the gateway is actually charged — including a
 *      full-discount code, which must settle without touching the gateway.
 *
 * Usage (from the project root):
 *   ddev exec php plugins/simple-form/tests/integration-live/payments.php
 *
 * Seeds and cleans up after itself. Exits non-zero on any failure.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';
/** @var \craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;

const TAG = 'PAYPROBE';

$failures = 0;
$made = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? ' OK ' : 'FAIL', $label, $detail !== '' ? " — {$detail}" : '');
}

$plugin = Plugin::getInstance();
$payments = $plugin->getPayments();
$elements = Craft::$app->getElements();

// ---------------------------------------------------------------- environment
echo "Environment\n";
check('Commerce is available to the plugin', $payments->commerceAvailable());
$currency = $payments->primaryCurrencyIso();
check('a primary currency resolves', $currency !== null, (string)$currency);

$gateways = \craft\commerce\Plugin::getInstance()->getGateways()->getAllCustomerEnabledGateways();
check('at least one customer-enabled gateway', count($gateways) > 0, count($gateways) . ' gateway(s)');
$gateway = $gateways->first();

$donation = \craft\commerce\elements\Donation::find()->status(null)->one();
check('the Donation purchasable exists', $donation !== null, $donation ? "id={$donation->id}" : 'missing');

// ------------------------------------------------------------------- fixture
/**
 * Fields are rows in simpleform_fields (+ a per-site label row), not a property
 * on the element — Form::$fields is read-only. This mirrors FieldsController.
 */
function addField(int $formId, string $type, string $name, string $label, array $config = [], bool $required = false): int
{
    $db = Craft::$app->getDb();
    $now = date('Y-m-d H:i:s');
    $db->createCommand()->insert('{{%simpleform_fields}}', [
        'formId' => $formId,
        'type' => $type,
        'name' => $name,
        'required' => $required,
        // NOT json_encode()ed: `config` is a json column and Yii encodes arrays
        // for it. Pre-encoding stores a JSON *string*, which decodes back to a
        // string, fails is_array() in FieldQueryHelper and silently becomes [].
        'config' => $config,
        'sortOrder' => 1,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => \craft\helpers\StringHelper::UUID(),
    ])->execute();
    $fieldId = (int)$db->getLastInsertID();
    foreach (Craft::$app->getSites()->getAllSites() as $site) {
        $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
            'fieldId' => $fieldId,
            'siteId' => $site->id,
            'label' => $label,
            'helpText' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();
    }
    return $fieldId;
}

function makeForm(array $paymentConfig = [], string $suffix = ''): Form
{
    $form = new Form();
    $form->name = TAG . ' Paid Form' . $suffix;
    $form->handle = strtolower(TAG) . 'PaidForm' . $suffix;
    $form->title = TAG . ' Paid Form' . $suffix;
    $form->siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $form->emailTo = 'payprobe-owner@example.test';
    Craft::$app->getElements()->saveElement($form);

    addField($form->id, 'email', 'email', 'Email', [], true);
    // The config keys are the ones PaymentFieldType actually reads:
    // amountType (fixed|field), amount, and the optional minAmount/maxAmount.
    addField($form->id, 'payment', 'payment', 'Payment', array_merge([
        'amountType' => 'fixed',
        'amount' => 25.00,
    ], $paymentConfig));

    return $form;
}

/**
 * Submission data as SubmissionService persists it: keyed field_<id>, each value
 * a {label, type, value} envelope. Resolved from the form's own field set so it
 * stays correct across the several probe forms below.
 *
 * @return array<string, array{label: string, type: string, value: mixed}>
 */
function says(Form $form, string $name, mixed $value): array
{
    foreach (Plugin::getInstance()->getFormStructure()->getFieldSet((int)$form->id, (int)$form->siteId) as $field) {
        if ($field['name'] === $name) {
            return ['field_' . $field['id'] => [
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $value,
            ]];
        }
    }
    throw new RuntimeException("no field named {$name} on form {$form->id}");
}

foreach (Form::find()->status(null)->handle(strtolower(TAG) . 'PaidForm*')->all() as $old) {
    $elements->deleteElement($old, true);
}

$form = makeForm();
$made[] = $form;
check('a form with a Payment field saves', $form->id !== null, "id={$form->id}");
check('the plugin sees it as requiring payment', $payments->requiresPayment($form));

$amount = $payments->resolveAmount($form, []);
check('the fixed amount resolves', abs(($amount ?? 0) - 25.00) < 0.001, var_export($amount, true));

// ------------------------------------------------- 4. bounds enforced in code
// Bounds only bite when the visitor names the amount, so this needs a
// pay-what-you-want field, not the fixed one above.
echo "\nAmount bounds (visitor-named amount)\n";
$boundedForm = makeForm([
    'amountType' => 'field',
    'amountField' => 'donation',
    'minAmount' => 5.00,
    'maxAmount' => 500.00,
], 'Bounded');
$made[] = $boundedForm;
// The amount is read from another field by handle, so that field has to exist —
// and submitted data is keyed field_<id>, not by handle.
$donationFieldId = addField($boundedForm->id, 'number', 'donation', 'Donation amount');
// Submission data is keyed field_<id> and each value is a {label,type,value}
// envelope — the shape SubmissionService actually persists.
$give = fn(float $n): array => says($boundedForm, 'donation', $n);


check(
    'the visitor-named amount resolves from the submitted data',
    abs(($payments->resolveAmount($boundedForm, $give(42.00)) ?? 0) - 42.00) < 0.01,
    var_export($payments->resolveAmount($boundedForm, $give(42.00)), true),
);

check('below the minimum is refused', $payments->amountOutOfBoundsMessage($boundedForm, 1.00) !== null);
check('above the maximum is refused', $payments->amountOutOfBoundsMessage($boundedForm, 5000.00) !== null);
check('an in-range amount is accepted', $payments->amountOutOfBoundsMessage($boundedForm, 25.00) === null);

// And the guard has to hold in the charge path, not only in the helper: an
// out-of-bounds amount must come back as an error, never as a charge.
$overBound = $payments->authorizeForSubmit($boundedForm, $give(5000.00), [
    'number' => '4242424242424242', 'expiry' => '01/2030', 'cvv' => '123',
]);
check(
    'authorize refuses an out-of-bounds amount before charging',
    ($overBound['error'] ?? null) !== null && ($overBound['status'] ?? '') !== Submission::PAYMENT_PAID,
    json_encode($overBound),
);

// ------------------------------------------------------- 2. a successful charge
echo "\nA successful charge\n";
$result = $payments->authorizeForSubmit($form, says($form, 'email', 'payprobe@example.test'), [
    'number' => '4242424242424242',
    'month' => 1,
    'year' => 2030,
    'cvv' => '123',
    'firstName' => 'Pay',
    'lastName' => 'Probe',
]);

check('a valid card is not refused', is_array($result) && ($result['error'] ?? null) === null, json_encode($result));
check(
    'it settles as paid',
    ($result['status'] ?? null) === Submission::PAYMENT_PAID,
    'status=' . var_export($result['status'] ?? null, true),
);
check('the charge is the resolved amount', abs(($result['amount'] ?? 0) - 25.00) < 0.01, var_export($result['amount'] ?? null, true));

$order = null;
if (!empty($result['orderId'])) {
    $order = \craft\commerce\elements\Order::find()->id((int)$result['orderId'])->status(null)->one();
}
check('a Commerce order was created', $order !== null, 'orderId=' . var_export($result['orderId'] ?? null, true));
if ($order) {
    check('the order total matches', abs((float)$order->getTotalPrice() - 25.00) < 0.01, 'total=' . $order->getTotalPrice());
    check('the order is marked paid in Commerce', $order->getIsPaid(), 'isPaid=' . var_export($order->getIsPaid(), true));
}

// --------------------------------------------- 1. it must never pass for free
// The single most expensive way this can be wrong: a paid form yielding a paid
// submission without money changing hands.
echo "\nIt must not pass for free\n";

$declined = $payments->authorizeForSubmit($form, says($form, 'email', 'payprobe@example.test'), [
    // Commerce's Dummy gateway approves a card whose last digit is even and
    // declines an odd one — 4242… is not magic, the parity is.
    'number' => '4242424242424241',
    'month' => 1, 'year' => 2030, 'cvv' => '123',
    'firstName' => 'Pay', 'lastName' => 'Probe',
]);
check(
    'a declined card never returns paid',
    ($declined['status'] ?? null) !== Submission::PAYMENT_PAID,
    json_encode($declined),
);
check('…and it says why', ($declined['error'] ?? null) !== null, json_encode($declined));

$noCard = $payments->authorizeForSubmit($form, says($form, 'email', 'payprobe@example.test'), []);
check(
    'no card details is refused, not accepted',
    ($noCard['status'] ?? null) !== Submission::PAYMENT_PAID && ($noCard['error'] ?? null) !== null,
    json_encode($noCard),
);

// A form with a Payment field but no positive amount is not a paid form — it
// must return null (nothing to charge) rather than an empty paid-looking result.
$freeForm = makeForm(['amountType' => 'fixed', 'amount' => 0], 'Zero');
$made[] = $freeForm;
$zero = $payments->authorizeForSubmit($freeForm, says($freeForm, 'email', 'payprobe@example.test'), []);
check('a zero-amount Payment field charges nothing', $zero === null, json_encode($zero));

// -------------------------------------- 5. expire-payments only touches pending
echo "\nExpiring abandoned payments\n";
$pending = new Submission();
$pending->formId = $form->id;
$pending->data = says($form, 'email', 'payprobe-pending@example.test');
$pending->paymentStatus = Submission::PAYMENT_PENDING;
$elements->saveElement($pending, false);
$made[] = $pending;

$paid = new Submission();
$paid->formId = $form->id;
$paid->data = says($form, 'email', 'payprobe-paid@example.test');
$paid->paymentStatus = Submission::PAYMENT_PAID;
$elements->saveElement($paid, false);
$made[] = $paid;

// Backdate the pending one past any sane TTL.
Craft::$app->getDb()->createCommand()->update(
    '{{%elements}}',
    ['dateCreated' => (new DateTime('-30 days'))->format('Y-m-d H:i:s')],
    ['id' => $pending->id],
)->execute();

$expired = $payments->expirePending();
$pendingAfter = Submission::find()->id($pending->id)->status(null)->one();
$paidAfter = Submission::find()->id($paid->id)->status(null)->one();

check('a stale pending payment is expired', $expired >= 1, "expirePending() returned {$expired}");
check(
    'the stale one is no longer pending',
    $pendingAfter?->paymentStatus !== Submission::PAYMENT_PENDING,
    'status=' . var_export($pendingAfter?->paymentStatus, true),
);
check(
    'a paid submission is never touched',
    $paidAfter?->paymentStatus === Submission::PAYMENT_PAID,
    'status=' . var_export($paidAfter?->paymentStatus, true),
);

// ------------------------------------------------------ 6. coupons and the charge
// The usage cap is unit-covered; what is not is the money. A coupon has to move
// the amount the gateway sees, and a 100%-off code has to settle without a
// charge rather than either charging full price or slipping through unpaid.
echo "\nCoupons change what is charged\n";

$coupons = $plugin->getCoupons();
$mkCoupon = function (string $code, string $type, float $amount) use ($coupons) {
    if ($existing = $coupons->getByCode($code)) {
        $coupons->delete((int) $existing->id);
    }
    $c = new \anvildev\simpleform\models\CouponModel();
    $c->code = $code;
    $c->type = $type;
    $c->amount = $amount;
    $c->enabled = true;
    if (!$coupons->save($c)) {
        throw new RuntimeException("coupon {$code}: " . json_encode($c->getErrors()));
    }
    return $c;
};

$tenOff = $mkCoupon(TAG . '-TENOFF', \anvildev\simpleform\models\CouponModel::TYPE_FIXED, 10.00);
$allOff = $mkCoupon(TAG . '-FREE', \anvildev\simpleform\models\CouponModel::TYPE_PERCENTAGE, 100.00);

$card = ['number' => '4242424242424242', 'month' => 1, 'year' => 2030, 'cvv' => '123', 'firstName' => 'Pay', 'lastName' => 'Probe'];

$discounted = $payments->authorizeForSubmit($form, says($form, 'email', 'payprobe@example.test'), $card, $tenOff->code);
check('a fixed coupon settles', ($discounted['status'] ?? null) === Submission::PAYMENT_PAID, json_encode($discounted));
check('…and the gateway is charged the reduced amount', abs(($discounted['amount'] ?? 0) - 15.00) < 0.01, 'charged ' . var_export($discounted['amount'] ?? null, true));
if (!empty($discounted['orderId'])) {
    $dOrder = \craft\commerce\elements\Order::find()->id((int)$discounted['orderId'])->status(null)->one();
    check('…and the Commerce order agrees', $dOrder && abs((float)$dOrder->getTotalPrice() - 15.00) < 0.01, 'order total=' . ($dOrder?->getTotalPrice() ?? '—'));
}

$free = $payments->authorizeForSubmit($form, says($form, 'email', 'payprobe@example.test'), [], $allOff->code);
check('a 100%-off coupon settles as paid', ($free['status'] ?? null) === Submission::PAYMENT_PAID, json_encode($free));
check('…without creating a Commerce order', (int)($free['orderId'] ?? -1) === 0, 'orderId=' . var_export($free['orderId'] ?? null, true));
check('…and records the full discount', abs(($free['discount'] ?? 0) - 25.00) < 0.01, 'discount=' . var_export($free['discount'] ?? null, true));

$badCode = $payments->authorizeForSubmit($form, says($form, 'email', 'payprobe@example.test'), $card, 'NO-SUCH-CODE');
check('an unknown code rejects the submit', ($badCode['error'] ?? null) !== null && ($badCode['status'] ?? '') !== Submission::PAYMENT_PAID, json_encode($badCode));

foreach ([$tenOff, $allOff] as $c) {
    $coupons->delete((int) $c->id);
}

// ------------------------------------------- 3. settling releases the notification
// The hold is unit-covered; what is not is that money arriving actually lets the
// held email go. Observed on the real queue, since the mailer is real here.
echo "\nSettling releases the held notification\n";

// The dev install dispatches synchronously, so a queue row is the wrong signal;
// the honest one is a message actually reaching the mail server.
$delivered = function (): int {
    $json = @file_get_contents('http://127.0.0.1:8025/api/v1/search?query=' . urlencode('to:payprobe-owner@example.test'));
    return $json === false ? -1 : (int) (json_decode($json, true)['messages_count'] ?? -1);
};

$held = new Submission();
$held->formId = $form->id;
$held->data = says($form, 'email', 'payprobe-held@example.test');
$held->paymentStatus = Submission::PAYMENT_PENDING;
$elements->saveElement($held, false);
$made[] = $held;

check('a pending submission reports as awaiting payment', $payments->isAwaitingPayment($held));

$before = $delivered();
$payments->markPaid($held);
$after = $delivered();

$settled = Submission::find()->id($held->id)->status(null)->one();
check('markPaid flips the status', $settled?->paymentStatus === Submission::PAYMENT_PAID, 'status=' . var_export($settled?->paymentStatus, true));
check('…and it is no longer awaiting payment', $settled && !$payments->isAwaitingPayment($settled));
check('…and the withheld notification actually goes out', $before >= 0 && $after > $before, "delivered {$before} -> {$after}");

// ------------------------------------------------------------------ teardown
foreach ($made as $el) {
    if ($el instanceof Form) {
        foreach (Submission::find()->status(null)->siteId('*')->formId($el->id)->all() as $sub) {
            $elements->deleteElement($sub, true);
        }
    }
}
foreach (array_reverse($made) as $el) {
    if ($el instanceof Form) {
        $elements->deleteElement($el, true);
    }
}

printf("\n%s\n", $failures === 0 ? 'All checks passed.' : "{$failures} check(s) FAILED.");
exit($failures === 0 ? 0 : 1);
