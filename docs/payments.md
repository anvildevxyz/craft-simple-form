# Payments

Simple Form can **collect a payment as part of a form submission** through
[Craft Commerce](https://plugins.craftcms.com/commerce). Add a **Payment** field
to a form, and the visitor pays on the same page they fill it out — the charge is
processed when they submit, and notifications, autoresponders, and outbound
integrations are held back until the payment settles.

Commerce is a **soft dependency**: it is never required to install or run Simple
Form. Without it (or without a usable gateway) a Payment field simply renders an
informational note and collects nothing — the rest of the form works unchanged.

> **No refunds or subscriptions.** This integration covers one-off payments taken
> at submit time. Refunds, partial captures, and recurring billing are managed in
> Commerce itself; Simple Form only reflects the *paid / pending / canceled*
> state of the submission.

---

## Requirements & setup

Payments need four things in place. The first time you add a Payment field,
Simple Form surfaces a clear error at submit if any are missing rather than
letting a paid form through for free.

1. **Install Craft Commerce.** `composer require craftcms/commerce` and install
   the plugin. (Simple Form lists it under `suggest`, so it is never pulled in
   automatically.)

2. **Configure at least one payment gateway** in *Commerce → Settings →
   Gateways* (Stripe, etc.). For local testing Commerce ships a **Dummy** gateway
   that approves the test card `4242 4242 4242 4242`.

3. **Enable the Donation purchasable** in *Commerce → Store Settings → Donation*
   — toggle **Available for purchase** on and save. Simple Form charges each
   payment as a Commerce **Donation** line item carrying the resolved amount, so
   no per-form product/catalog setup is needed. Commerce does **not** create this
   purchasable for you; if it is missing, payment submits fail with
   *"Payments are not available right now."*

4. **(Optional) Choose the gateway** Simple Form should use. By default it uses
   the store's first customer-enabled gateway. To pin a specific one, set the
   `paymentGatewayHandle` setting (see [Settings](#settings)).

---

## Adding a Payment field

Add a **Payment** field to a form in the builder like any other field. It defines
*how much* to charge and renders the gateway's payment form (card fields, etc.)
on the front end.

| Config | Meaning |
| --- | --- |
| `amountType` | `fixed` (a set price) or `field` (read the amount from another field). Default `fixed`. |
| `amount` | The fixed amount, when `amountType` = `fixed`. |
| `amountField` | Handle of a numeric field holding the amount, when `amountType` = `field` (e.g. a quantity × price [Calculation](field-types.md#calculation-calculation) field, or a plain Number field). |
| `currency` | ISO currency code, shown in the "Amount due" note. Informational only — the **Commerce store currency is authoritative** for the actual charge. |
| `minAmount` | Optional lower bound. A resolved amount below this is rejected before charging. |
| `maxAmount` | Optional upper bound. A resolved amount above this is rejected. |

The min/max bounds matter most with `amountType: field`, where the amount comes
from visitor input — they stop a £0.01 or £1,000,000 charge from a manipulated
or mistaken value. A resolved amount of **zero or less means "nothing to pay"**:
the form submits normally with no order and no payment gating.

> A Payment field collects no value of its own — it is not stored or exported as
> a submission field. The amount, order id, and payment status live on the
> submission record itself (see [In the Control Panel](#in-the-control-panel)).

---

## What happens on submit

Payment is **collected before the submission is saved** ("pay-to-submit"), so a
failed payment never leaves a stray submission behind:

1. The visitor fills the form and the embedded gateway card fields, then submits.
2. Simple Form resolves the amount, builds a pending Commerce order with a
   Donation line item, and processes the charge through the gateway.
3. The outcome decides what is saved:

| Outcome | Submission | Visitor sees |
| --- | --- | --- |
| **Charge succeeds** (onsite) | Saved, `paid`. Notifications + integrations fire immediately. | The form's normal success message / redirect. |
| **Card declined / gateway error** | **Nothing saved.** | A payment error; they can fix the card and retry. |
| **Amount out of `min`/`max` bounds** | **Nothing saved.** | A "below the minimum" / "exceeds the maximum" error. |
| **Offsite / 3-D-Secure redirect** | Saved, `pending`. | Redirected to the gateway to finish; on completion the submission flips to `paid`. |

When an offsite payment completes back in Commerce, Simple Form catches the
order-completed event and releases the submission automatically.

### Gating: nothing fires until paid

While a submission is `pending` (awaiting an offsite payment), Simple Form
**withholds** its admin notification, autoresponder, and every outbound
integration dispatch. They are released the moment the payment settles — so a
webhook or a "thanks for your order" email never goes out for an unpaid form.
A `paid`-on-submit charge releases them in the same request.

---

## Payment status & abandoned checkouts

Every submission of a payment form carries a payment status:

- **`paid`** — settled. The submission is fully released.
- **`pending`** — awaiting an offsite/3-D-Secure payment the visitor hasn't
  finished.
- **`canceled`** — a pending payment that was abandoned past its time-to-live (or
  whose order was canceled). Its notifications/integrations stay withheld
  permanently.

Pending submissions that are never completed are reconciled to `canceled`
automatically:

- On Craft's **garbage-collection** run, any submission pending longer than
  `paymentPendingTtlMinutes` (default 60) is canceled.
- Or on demand: `php craft simple-form/submissions/expire-payments`.

Set `paymentPendingTtlMinutes` to `0` to disable automatic expiry. (Onsite,
charged-on-submit payments never sit in `pending`, so this only affects
offsite/redirect gateways.)

---

## Coupons / discount codes

Payment forms can accept **discount codes**: a visitor enters a code next to the
payment fields, sees the discounted total immediately, and is charged the reduced
amount on submit.

### Creating coupons

Coupons are managed under **Simple Form → Settings → Coupons** (requires the
**Manage plugin settings** permission). They are **global** — any coupon works on
any payment form that accepts coupons; there is no per-form association.

| Field | Meaning |
| --- | --- |
| **Code** | The code visitors type. Matched **case-insensitively** (`COUPON10` = `coupon10`), stored as entered; uniqueness is enforced case-insensitively too. Max 64 characters. |
| **Discount type** | **Fixed amount** (in the Commerce store currency) or **Percentage** (0–100). |
| **Amount** | The amount off, per the type. |
| **Expiry date** | Optional. After this date (evaluated in UTC) the code is rejected. |
| **Usage limit** | Optional maximum number of redemptions, global across all forms; blank = unlimited. The edit screen shows how often it has been redeemed. |
| **Enabled** | When off, the code is rejected at checkout. |

The Coupons list flags codes that are *expired* or have *reached their limit*, and
each row can be toggled or deleted. (The screen is available without Commerce, but
coupons only *apply* to forms that collect a payment.)

### Accepting coupons on a form

Turn on **Allow Coupons** on the form's **Payment field** (config key
`enableCoupons`). The rendered field then shows a **Coupon code** input with an
**Apply** button:

- Clicking **Apply** validates the code against a public, rate-limited endpoint
  (max 30 attempts per IP per minute, to deter code guessing) and shows a live
  preview: *"Coupon applied: … off. You'll pay …"* — without consuming a use.
- Invalid, disabled, expired, or used-up codes get a specific message; unknown
  and disabled codes deliberately share the same *"This coupon code isn't
  valid."* so codes can't be enumerated.
- The preview is **advisory only**. On submit the code is re-validated and the
  discount recomputed **authoritatively server-side**; a code that fails at
  submit time rejects the whole submission (nothing is saved) so the visitor can
  fix or remove it.

### How the discount is applied

- The discount is computed off the resolved amount (after the field's
  `min`/`max` bounds) and **clamped so the total never goes negative**. Fixed
  discounts are in the **Commerce store's primary currency** — the authoritative
  one for the charge.
- The Commerce order's Donation line item carries the **discounted** amount; the
  coupon is not a Commerce promotion object.
- If the discount covers the full amount (**free after discount**), the
  submission is recorded as `paid` without contacting the gateway at all.
- **Usage counting is race-safe**: a use is reserved atomically right before the
  charge, so a once-only code can't be redeemed twice concurrently. A declined
  charge releases the reservation immediately; an abandoned offsite payment
  releases it when the pending submission expires or is canceled (see
  [above](#payment-status--abandoned-checkouts)). Previewing never consumes a use.

The applied code and discount amount are stored with the submission and shown as
a **Coupon** row on its CP detail screen.

---

## In the Control Panel

- **Submissions index** — add the optional **Payment** column (and sort by it) to
  see each submission's status at a glance. It is off by default; enable it from
  the column picker.
- **Submission detail** — a Payment block shows the status, the amount, a
  **Coupon** row (code + discount) when one was redeemed, and a link
  to the underlying Commerce **order** (when Commerce is installed).

Filter programmatically with the submission query params
`.paymentStatus()` and `.orderId()` — see
[Twig & developer API](twig-and-api.md).

---

## Front-end & theming

The Payment field renders two pieces inside your form:

- an **"Amount due"** note (`<p class="simple-form-payment-note">`), and
- the gateway's own payment form, wrapped in
  `<div class="simple-form-payment-fields">`. Its inputs are namespaced under
  `paymentForm[…]` (e.g. `paymentForm[number]`) so they post alongside the
  form's `field_*` values.

Both render inside the normal form markup, so your form's submit handling and
styling apply. Override the surrounding markup with a
[render template](render-templates.md) as with any field; the gateway-supplied
inner inputs come from Commerce.

---

## Headless / GraphQL

The embedded, pay-to-submit flow is a **front-end (Twig + JS) feature**. The
GraphQL `submitForm` mutation does **not** carry gateway card data, so submitting
a payment form over GraphQL is rejected with *"Payment information is required."*
Take payments through the rendered front-end form, or drive Commerce's own
checkout directly for a fully headless build.

---

## Settings

Both are optional and set on the plugin's settings (e.g. in
`config/simple-form.php`):

| Setting | Default | Purpose |
| --- | --- | --- |
| `paymentGatewayHandle` | _first enabled gateway_ | Handle of the Commerce gateway to charge through. Empty = the store's first customer-enabled gateway. |
| `paymentPendingTtlMinutes` | `60` | Minutes a pending (unpaid) submission may linger before garbage collection cancels it and reclaims the abandoned checkout. `0` disables expiry. |

```php
// config/simple-form.php
return [
    'paymentGatewayHandle' => 'stripe',
    'paymentPendingTtlMinutes' => 30,
];
```
