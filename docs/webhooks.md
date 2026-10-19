# Webhooks

[← Back to README](../README.md)

Payxy Core sends signed notifications to the webhook URL you configure in the merchant portal (configured
separately for test and production). Webhooks are **notifications**: confirm with VerifyPayment, or at least
process them idempotently, before giving value.

## Events

- `payment.success`
- `payment.failed`

## Request

```http
POST /merchant/payxy/webhook
Content-Type: application/json
x-payxy-signature: <lowercase-hex-hmac-sha512>[,<another during rotation>]
x-payxy-timestamp: 1792400000
x-payxy-attempt: 1
```

The JSON is **PascalCase**, because Core serializes its .NET notification object directly:

```json
{
  "MerchantId": 351,
  "Event": "payment.success",
  "WebhookUrl": "https://merchant.example.com/webhooks/payxy",
  "Mode": "Production",
  "Data": {
    "Amount": 75000.00, "CustomerPaid": 76129.00, "AmountPaidToMerchant": 75000.00,
    "FeeBearer": "Customer", "ProcessingFee": 1050.00, "PlatformVat": 79.00, "MerchantVat": 0.00,
    "TotalCharges": 1129.00, "SettlementStatus": "Completed", "PaymentType": "Card", "Currency": "NGN",
    "Status": "Successful", "IsSuccessful": true, "GatewayResponse": "Approved",
    "TransactionId": "ORD-261019-A12BC34D", "PaymentReference": "PXF35081579178",
    "ProviderReference": "PP75639243801918975770", "MerchantId": 351,
    "CardPan": "506099********7499", "CardType": "Verve", "CardExpiry": "09/30",
    "OriginatorBank": "", "OriginatorName": "", "OriginatorAccount": "", "SessionId": "PP75639243801918975770"
  }
}
```

`payment.failed` has the same shape, with `"Status": "Failed"`, `"IsSuccessful": false` and a reason in `GatewayResponse`.

## Signature

Core signs `{x-payxy-timestamp}.{raw HTTP request body}` with **HMAC-SHA512** and the mode's secret (`sk_test_…`
or `sk_live_…`), encoded as lowercase hex. During a secret rotation (24-hour grace period), `x-payxy-signature`
may carry several comma-separated signatures; accept the event if any one matches.

## SDK API

```php
// Boolean authenticity, freshness and structure check
$payxy->webhooks()->verify($rawBody, $signatureHeader, $timestampHeader);              // true / false

// Check + parse (throws WebhookVerificationException)
$event = $payxy->webhooks()->constructEvent($rawBody, $signatureHeader, $timestampHeader, $attemptHeader);
$event = $payxy->webhooks()->constructEventFromRequest($psr7ServerRequest);
$event = $payxy->webhooks()->constructEventFromGlobals();                               // php://input + $_SERVER
```

Needs `webhookSecret` in `PayxyConfig` (`PAYXY_WEBHOOK_SECRET`). The verifier enforces:

| Check | Rule |
|---|---|
| Signature | HMAC-SHA512 over `timestamp.rawBody`, compared in constant time against every comma-separated candidate |
| Freshness | `x-payxy-timestamp` within **300 s** of now (both directions; configurable `toleranceSeconds`) |
| Raw body | Exact bytes as received. A parsed and re-encoded body won't verify. |
| Size | ≤ 1 MiB, and must be a JSON object |
| Structure | Non-empty `Event`, `Data.TransactionId`, and a `Mode` of `Test` or `Production` |
| Environment | `Mode` must match the configured `sk_test_` or `sk_live_` webhook secret |

`WebhookEvent` getters: `getEvent()`, `isPaymentSuccess()`, `isPaymentFailed()`, `getMode()`, `getMerchantId()`,
`getTransactionId()`, `getStatus()`, `isSuccessful()`, `getAmount()`, `getCustomerPaid()`, `getAmountPaidToMerchant()`,
`getTotalCharges()`, `getCurrency()`, `getPaymentType()`, `getGatewayResponse()`, `getSettlementStatus()`,
`getPaymentReference()`, `getProviderReference()`, `getSessionId()`, `toArray()`, `$attempt`, and `idempotencyKey()`.

## Recommended processing

1. Read the raw request body.
2. Validate the timestamp.
3. Validate the signature.
4. Parse the JSON.
5. Begin a database transaction.
6. Insert a processing record keyed by `$event->idempotencyKey()` (`Event + Data.TransactionId`) under a **unique constraint**.
7. Apply the order state transition. Optionally `confirm()` through VerifyPayment first.
8. Commit.
9. Return HTTP 2xx. A duplicate valid webhook also gets 2xx, with no second effect.

Steps 1–4 are `constructEvent()`. A complete plain-PHP handler:

```php
use Payxy\Exception\WebhookVerificationException;

try {
    $event = $payxy->webhooks()->constructEventFromGlobals();
} catch (WebhookVerificationException) {
    http_response_code(401);
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO payxy_webhooks (idempotency_key, attempt) VALUES (?, ?)')
        ->execute([$event->idempotencyKey(), $event->attempt]);          // UNIQUE(idempotency_key)
} catch (PDOException) {
    $pdo->rollBack();
    http_response_code(200);                                             // duplicate: already processed
    exit;
}
if ($event->isPaymentSuccess()) {
    $result = $checkout->confirm($event->getTransactionId());            // VerifyPayment + amount check
    if ($result->isSuccessful()) {
        $orders->markPaid($event->getTransactionId());
    }
}
$pdo->commit();
http_response_code(200);
```

With a `PayxyCheckout` subclass, `handleWebhook($rawBody, $signature, $timestamp)` runs `constructEvent()` and
`confirm()` in one call.

## Delivery retries

The Core repository documents this schedule: immediately, then after 30 s, 2 min, 10 min, 1 h and 6 h. **It isn't
yet confirmed against the deployed workers**, so don't treat it as an SLA. Design for at-least-once delivery and
idempotent processing.
