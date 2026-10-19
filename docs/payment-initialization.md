# Payment initialization

[← Back to README](../README.md)

## Endpoint

```http
POST /api/Payments/Initialize
Authorization: Bearer pk_test_xxx
Content-Type: application/json
```

## With the SDK

```php
use Payxy\Payments\Model\CheckoutCustomization;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Support\Amount;
use Payxy\Support\Reference;

$transactionId = Reference::generate('ORD');        // e.g. ORD-9F2C4A1B7E03D5C8 (≤ 30 chars)
$order->update(['payxy_transaction_id' => $transactionId]);   // persist BEFORE calling Payxy

$response = $payxy->payments()->initialize(new InitializePaymentRequest(
    email: 'customer@example.com',
    amount: Amount::fromString('75000.00'),
    transactionId: $transactionId,
    currency: 'NGN',
    callbackUrl: 'https://merchant.example.com/payments/payxy/return',
    metadata: ['orderId' => 'ORDER-10492', 'customerId' => 'CUSTOMER-77'],
    productId: 'SKU-1001',
    productDescription: 'Premium subscription',
    applyConvenienceCharge: true,
    customization: new CheckoutCustomization(
        bodyColor: '#FFFFFF',
        buttonColor: '#0066FF',
        footerText: 'Secured by Payxy',
        footerLink: 'https://merchant.example.com',
        footerLogo: 'https://merchant.example.com/logo.png',
    ),
));

$order->update(['payxy_access_code' => $response->getAccessCode()]);
header('Location: ' . $response->getPaymentUrl());
exit;
```

## Wire contract

```json
{
  "email": "customer@example.com",
  "amount": "75000.00",
  "transactionId": "ORD-261019-A12BC34D",
  "currency": "NGN",
  "callbackUrl": "https://merchant.example.com/payments/payxy/return",
  "metadata": [{ "name": "orderId", "value": "ORDER-10492" }, { "name": "customerId", "value": "CUSTOMER-77" }],
  "productId": "SKU-1001",
  "productDescription": "Premium subscription",
  "applyConviniencyCharge": true,
  "bodyColor": "#FFFFFF",
  "buttonColor": "#0066FF",
  "footerText": "Secured by Payxy",
  "footerLink": "https://merchant.example.com",
  "footerLogo": "https://merchant.example.com/logo.png"
}
```

### Validation (checked locally before any request)

| Field | Rule |
|---|---|
| `email` | Required, valid email address |
| `amount` | Required, positive, at most two decimals. Sent as an exact decimal string (`"75000.00"`); floats are never used. |
| `transactionId` | Required, **6–30 characters**, no control characters. Must be unique per merchant. |
| `currency` | Required, three letters (default `NGN`) |
| `callbackUrl` | Optional, absolute `http(s)` URL |
| `metadata` | Optional, sent as `[{name, value}]` |
| `applyConviniencyCharge` | Optional boolean. The SDK property is `applyConvenienceCharge`; the wire spelling is preserved exactly. |
| `mode` | **Never sent.** Core derives the mode from the public key. |

`amount` is the **order amount before Payxy's charges**. Payxy works out payment-method fees itself.

## Responses

**Success**: HTTP 200:

```json
{
  "responseCode": "00",
  "responseMessage": "Authorization URL created",
  "data": { "paymentUrl": "https://checkout.payxy.ng/abc123accesscode", "accessCode": "abc123accesscode" }
}
```

`$response->getPaymentUrl()`, `$response->getAccessCode()`, `$response->getResponseCode()` (`"00"`), `$response->getMessage()`.

| Failure | Core response | SDK exception |
|---|---|---|
| Duplicate transaction ID | HTTP 400, `{"responseCode":"04","responseMessage":"Transaction id already exists"}` | `DuplicateTransactionException` |
| Invalid amount | HTTP 400, `{"responseCode":"04","responseMessage":"Amount must be greater than zero"}` | `ValidationException` |
| Missing authentication | HTTP 401, plain text `You are not authorized to view this resource` | `AuthenticationException` |
| Invalid public key | HTTP 401, plain text `Invalid merchant key` | `AuthenticationException` |
| Any other non-`00` code | HTTP 200 or 4xx with `responseCode` | `RequestFailedException` / `ValidationException` (`getResponseCode()`) |

## Ambiguous failures: never invent a new ID

Initialize is **never retried automatically**. If it fails ambiguously (a `TransportException` such as a timeout,
or a 5xx), Payxy may or may not have created the session. **Don't generate a new transaction ID.** Instead:

1. **Retry with the same `transactionId`.**
2. If that returns a new `paymentUrl`, carry on.
3. If it throws `DuplicateTransactionException`, the first attempt landed. Reconcile:
   - `verify($transactionId)` throws `RequestFailedException` with code `04` while nobody has paid. The earlier
     session exists but its URL can't be fetched through the public API. Mark that attempt abandoned and only then
     start a new attempt with a new ID.
   - If verify returns a record, follow [payment verification](payment-verification.md).

```php
use Payxy\Exception\{DuplicateTransactionException, ServerException, TransportException};

try {
    $response = $payxy->payments()->initialize($request);
} catch (TransportException | ServerException) {
    try {
        $response = $payxy->payments()->initialize($request);          // same transactionId
    } catch (DuplicateTransactionException) {
        // reconcile with verify(); do not blindly create a new reference
    }
}
```

## References and money

```php
Reference::generate('ORD');           // "ORD-9F2C4A1B7E03D5C8": CSPRNG, prefix ≤ 10, total ≤ 27 chars
Amount::fromString('75000.00');       // exact; also Amount::of(75000), Amount::fromMinorUnits(7500000)
```
