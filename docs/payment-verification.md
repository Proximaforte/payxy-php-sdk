# Payment verification

[← Back to README](../README.md)

Verification is the **only** proof of payment besides a verified webhook. Never fulfil from a browser redirect.

## Endpoint

```http
POST /api/Payments/VerifyPayment
Authorization: Bearer pk_test_xxx
Content-Type: application/json

{ "transactionId": "ORD-261019-A12BC34D" }
```

There is no `caller` parameter: Core no longer accepts it.

## With the SDK

```php
$verification = $payxy->payments()->verify($order->payxy_transaction_id);

if ($verification->isPaid()) {
    $check = $verification->checkAmount($order->amount, 'NGN');      // see "Amount comparison"
    $check->isAcceptable()
        ? $order->markPaidOnce($verification->getPaymentReference())  // idempotent
        : $order->flagForReview($check->value);                       // e.g. "underpaid", "unexplained_surplus"
} elseif ($verification->isPending()) {
    // not final yet: show "processing", re-check later or wait for the webhook
} else {
    // failed / declined
}
```

`isPaid()` is exactly Core's fulfilment rule:

```text
responseCode === "00"  &&  data !== null  &&  data.isSuccessful === true
```

## Results by `responseCode`

VerifyPayment reports the **transaction state** in `responseCode`, always over HTTP 200:

| `responseCode` | `data.status` | SDK behaviour |
|---|---|---|
| `00` | `Successful` | Returns the response. `isPaid()` is true when `isSuccessful === true`. |
| `05` | `Pending` (processing / unknown outcome) | Returns the response. `isPending()` is true. |
| `02` | `Failed` | Returns the response. `isPaid()` and `isPending()` are false. |
| `04` | *(`data: null`)* "Invalid transaction id." | Throws `RequestFailedException`, so it never looks successful. Before a payment is attempted, an initialized transaction also returns `04`. |
| HTTP 401 | plain text `Invalid merchant key` | Throws `AuthenticationException` |

Pending and failed payments are **typed results**, not exceptions. Only a missing record throws.

## Data model

| Getter | Field | Meaning |
|---|---|---|
| `getAmount()` | `amount` | The commercial order amount **when the fee breakdown is present**. Successful Transfers have been seen without the breakdown and with fees **included** in `amount`; see [Amount comparison](#amount-comparison). |
| `hasFeeBreakdown()` | `customerPaid` present? | Whether the fee breakdown below was returned, which tells you how to read `amount` |
| `getCustomerPaid()` | `customerPaid` | Total collected from the customer. Can be **higher** than `amount` when the customer bears fees. |
| `getAmountPaidToMerchant()` | `amountPaidToMerchant` | Amount credited or due to the merchant |
| `getProcessingFee()` | `processingFee` | Processing charge, excluding platform VAT |
| `getPlatformVat()` | `platformVat` | VAT on the platform processing fee |
| `getMerchantVat()` | `merchantVat` | VAT from the merchant's vatable products |
| `getTotalCharges()` | `totalCharges` | Total Payxy/platform deductions or additions |
| `getFeeBearer()` | `feeBearer` | Usually `Customer` or `Merchant` |
| `getSettlementStatus()` / `settlementStatus()` | `settlementStatus` | Settlement lifecycle (raw string / typed enum) |
| `getStatus()`, `isSuccessful()` | `status`, `isSuccessful` | Payment state |
| `getPaymentType()`, `getCurrency()`, `getGatewayResponse()` | | |
| `getTransactionId()`, `getPaymentReference()`, `getProviderReference()` | | Your ID, Payxy's reference, the processor's reference |
| `getMaskedCardPan()`, `getCardType()`, `getSessionId()`, `getMerchantId()` | | Empty strings are returned as `null` |

All amounts are `Payxy\Support\Amount` (exact). Core sends them as JSON numbers (`75000.00`) or strings
(`"101.51"`); both are parsed exactly. Breakdown getters return `null` when Payxy omits the field.

### Settlement status

`SettlementStatus` covers `NotCreated`, `Pending`, `InProgress`, `Completed`, `Failed` and `PartiallyFailed`.
Values newer than this SDK aren't an error: `settlementStatus()` returns `null` and `getSettlementStatus()` still
returns the raw string.

Settlement is independent of payment success. **Don't hold back customer fulfilment just because settlement
hasn't completed**, unless your business specifically requires it.

## Amount comparison

Compare your order amount with the verified payment using `checkAmount()`. It returns an `AmountCheck` that says
**why** the payment does or doesn't match, so you can log it or route it for review:

```php
use Payxy\Payments\AmountCheck;

$check = $verification->checkAmount($expectedOrderAmount, 'NGN');   // AmountCheck
$check->isAcceptable();                                             // true only for Exact / WithinFeeAllowance
$verification->amountMatches($expectedOrderAmount, 'NGN');          // shorthand for the line above
```

| `AmountCheck` | When | Acceptable |
|---|---|---|
| `Exact` | `data.amount` equals the order amount (and the currency matches) | ✔ |
| `WithinFeeAllowance` | **No** fee breakdown, and `data.amount` exceeds the order by no more than the fee allowance **you** passed | ✔ (your business decision) |
| `Underpaid` | `data.amount` is below the order amount | ✘ never, whatever the allowance |
| `Overpaid` | The fee breakdown **is** present (so `amount` is the commercial amount) and it exceeds the order | ✘ |
| `UnexplainedSurplus` | **No** fee breakdown and `data.amount` exceeds the order beyond any allowance | ✘ review it |
| `CurrencyMismatch` | A different currency, or none reported when you passed one | ✘ |
| `Missing` | No usable amount (or, in `PayxyCheckout`, an order you don't recognise) | ✘ |

**Never** compare with `customerPaid`: fees borne by the customer make it higher than the order amount.

### Transfers: `amount` can include fees

Payxy doesn't return the same shape for every payment. The live sandbox returns two:

| Response | `amount` | Fee breakdown |
|---|---|---|
| Card payments (Core contract), Transfers while Pending / Failed | The order amount | Present (`customerPaid`, `totalCharges`, …) |
| **Successful Transfer** | **What the customer paid, including fees** (e.g. `101.51` for a ₦100.00 order) | **Missing** |

When the breakdown is missing, a surplus might be the customer's fees **or** an overpayment, and the response can't
tell them apart. So by default the SDK **doesn't accept it** (`UnexplainedSurplus`), and it never accepts less than
the order (`Underpaid`). If you're willing to fulfil such payments, pass a **fee allowance**: the most a payment
may exceed the order when no breakdown is present.

```php
// Business decision: accept up to 2% above the order when Payxy sends no fee breakdown.
// Within this allowance an actual overpayment is accepted too.
$allowance = Amount::fromMinorUnits((int) ceil($order->amount->toMinorUnits() * 0.02));

$verification->checkAmount($order->amount, 'NGN', $allowance);   // WithinFeeAllowance for 101.51 vs 100.00
```

With the `PayxyCheckout` helper, override `feeAllowance()`; it defaults to `null`, which is strict:

```php
protected function feeAllowance(string $transactionId, Amount $expected): ?Amount
{
    return Amount::fromMinorUnits((int) ceil($expected->toMinorUnits() * 0.02));
}
```

`CheckoutResult::$amountCheck` then records which rule applied (`Exact`, `WithinFeeAllowance`, `UnexplainedSurplus`, …).

> **Open question for Payxy:** the inconsistent `amount` meaning and the missing breakdown on successful Transfers.
> Once Payxy returns the breakdown for every payment, `amount` is always the order amount and no allowance is needed.

## Retries

VerifyPayment is a read, so the SDK retries it on network errors, 429, 502, 503 and 504, following a bounded
schedule: immediately, then after 2 s, 5 s and 10 s. It never polls indefinitely. See [errors and retries](errors-and-retries.md).

## GetPaymentInfo (checkout-oriented)

```php
$info = $payxy->payments()->getPaymentInfo($accessCode);
```

`GET /api/Payments/GetPaymentInfo/{accessCode}` is anonymous and mainly serves Payxy's own checkout page.
It returns the session (`amount`, `link`, `businessName`, payment methods and their charges) plus two tokens:

| Field | Purpose |
|---|---|
| `token` | Authorizes the checkout's own calls (MakePayment / ValidatePayment) |
| `paymentRealtimeToken` | Authorizes only the SignalR payment hub (short-lived, currently ≤ 30 min) |

Neither is a merchant key. **Never** use them as Bearer tokens for Initialize or Verify, and **never log them**.
The SDK exposes `getRealtimeToken()` only for browser UIs that subscribe to live status. Fulfilment still requires
VerifyPayment or a verified webhook. An invalid or completed link answers HTTP 400 with `responseCode "02"`, which
raises `ValidationException`.
