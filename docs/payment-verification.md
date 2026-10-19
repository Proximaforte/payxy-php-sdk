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

if ($verification->isPaid() && $verification->amountMatches($order->amount, 'NGN')) {
    $order->markPaidOnce($verification->getPaymentReference());     // idempotent
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
| `getAmount()` | `amount` | Commercial / order amount expected by the merchant |
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

All amounts are `Payxy\Support\Amount` (exact). Core sends them as JSON numbers, e.g. `75000.00`.

### Settlement status

`SettlementStatus` covers `NotCreated`, `Pending`, `InProgress`, `Completed`, `Failed` and `PartiallyFailed`.
Values newer than this SDK aren't an error: `settlementStatus()` returns `null` and `getSettlementStatus()` still
returns the raw string.

Settlement is independent of payment success. **Don't hold back customer fulfilment just because settlement
hasn't completed**, unless your business specifically requires it.

## Amount comparison

Compare your order with the **commercial amount**:

```php
$verification->amountMatches($expectedOrderAmount, 'NGN');   // compares data.amount (and currency)
```

**Never** compare with `customerPaid`: fees borne by the customer make it higher than the order amount.

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
