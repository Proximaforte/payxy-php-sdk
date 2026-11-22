# Payxy PHP SDK

Accept payments, manage virtual accounts and send payouts from your PHP server with [Payxy](https://docs.payxy.africa).

```bash
composer require payxy/payxy-php-sdk
```

```php
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Support\Amount;

$payxy = new Payxy(new PayxyConfig(getenv('PAYXY_PUBLIC_KEY'), getenv('PAYXY_WEBHOOK_SECRET') ?: null));

// 1. Create a payment and redirect the customer to Payxy
$payment = $payxy->payments()->initialize(new InitializePaymentRequest(
    email: 'ada@example.com',
    amount: Amount::of('75000.00'),
    transactionId: 'ORD-10042',
    callbackUrl: 'https://shop.example.com/pay/callback?transactionId=ORD-10042',
));
header('Location: ' . $payment->getPaymentUrl());

// 2. On the callback, confirm the result, then fulfil the order
$verification = $payxy->payments()->verify('ORD-10042');
if ($verification->isPaid() && $verification->amountMatches(Amount::of('75000.00'), 'NGN')) {
    // fulfil the order
}
```

The SDK handles authentication, retries, error handling, webhook signature checks and exact money arithmetic, so the safe way to integrate is also the short way.

> **Server-side only.** This SDK uses your secret credentials. Never run it in a browser or mobile app. Customers enter their card details on Payxy's hosted checkout page, never on your servers, so you stay out of card-data (PCI) scope.

## Contents

- [Getting started](#getting-started)
- [Accept a payment](#accept-a-payment)
- [Framework guides](#framework-guides): [Plain PHP](#plain-php) · [Laravel](#laravel) · [Symfony](#symfony)
- [Payments reference](#payments-reference): [`initialize`](#paymentsinitialize) · [`verify`](#paymentsverify) · [`getPaymentInfo`](#paymentsgetpaymentinfo) · [Amounts](#amounts)
- [Webhooks reference](#webhooks-reference)
- [Checkout helper](#checkout-helper)
- [Money movement](#money-movement)
- [Error handling](#error-handling)
- [Configuration](#configuration)
- [Testing](#testing) · [Go live](#go-live)
- [Security](#security)
- [Good to know](#good-to-know)
- [Troubleshooting](#troubleshooting) · [FAQ](#faq)
- [Compatibility](#compatibility) · [Support](#support)

---

## Getting started

### Requirements

- PHP **8.2 or later** with `ext-json`
- A PSR-18 HTTP client. Guzzle (bundled with Laravel) or `symfony/http-client` is detected and configured for you; any other PSR-18 client is found through discovery
- A Payxy merchant account
- Your server's public IP allowlisted with Payxy, if IP rules are enabled on your account

### Installation

```bash
composer require payxy/payxy-php-sdk guzzlehttp/guzzle
```

Laravel and Symfony integrations are included and activate only when you use them. Upgrade within a major version with `composer update payxy/payxy-php-sdk`.

### Get your API keys

Payxy issues two keys per environment:

| Key            | Looks like                | Used for                                                                      |
| -------------- | ------------------------- | ----------------------------------------------------------------------------- |
| Public key     | `pk_test_…` / `pk_live_…` | Authenticating API calls                                                      |
| Webhook secret | `sk_test_…` / `sk_live_…` | Verifying webhooks. Also required, with the public key, for [money movement](#money-movement) |

- **The key prefix selects the environment.** `pk_test_…` talks to the sandbox (`https://api.payxy.ng`); `pk_live_…` talks to production (`https://api.payxy.africa`). There is no flag to flip and no separate build.
- Despite its name, the public key is a **server credential** here. Keep both keys in environment variables or a secret manager, never in version control, browsers or logs.
- Both keys must belong to the same environment. Mixing a test key with a live secret fails when the client is created. An `sk_` key is rejected as a public key.

### Configure the client

Put your keys in the environment:

```dotenv
PAYXY_PUBLIC_KEY=pk_test_xxxxxxxxxxxxxxxx
PAYXY_WEBHOOK_SECRET=sk_test_xxxxxxxxxxxxxxxx   # needed for webhooks and money movement
```

Create the client once and reuse it. It keeps no per-request state:

```php
use Payxy\Payxy;
use Payxy\PayxyConfig;

$payxy = new Payxy(new PayxyConfig(
    publicKey: $_ENV['PAYXY_PUBLIC_KEY'],
    webhookSecret: $_ENV['PAYXY_WEBHOOK_SECRET'] ?? null,
));
```

Laravel and Symfony build this client from their own configuration; see the [framework guides](#framework-guides). Invalid settings throw a `ConfigurationException` immediately, not on the first request. Every option is listed under [Configuration](#configuration).

---

## Accept a payment

### How a payment works

```
 Your frontend            Your backend (uses the SDK)                  Payxy
 ─────────────            ───────────────────────────                  ─────
 "Pay" clicked  ───────▶  payments()->initialize(...)  ───────────▶  creates the payment
                          ◀── paymentUrl, accessCode ◀──────────────
 redirect  ◀──────────────  paymentUrl
      │
      └──────────────────────────────────────────────────────────▶  hosted checkout page
                                                                      (customer pays here)
 back to your site  ◀──────────────────────────────────────────────  redirect to callbackUrl
      │
      └──────────────▶  payments()->verify($transactionId)  ─────▶  the authoritative result
                        ◀── isPaid(), amount, fees ◀───────────────
                        fulfil the order only if paid and the amount is right

                                          Payxy also calls your webhook URL (payment.success / payment.failed).
                                          Treat it as a notification: confirm with verify() before fulfilling.
```

Three rules keep your integration safe. The SDK is built around them:

1. **Only `verify()` decides whether an order is paid.** A redirect or a webhook is a hint about which transaction to check.
2. **Compare the amount against your own records,** never against anything that came from the request.
3. **Verify webhooks on the raw request body.**

### Step 1: Initialize the payment

Call this when the customer clicks "Pay".

```php
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Support\Amount;

$payment = $payxy->payments()->initialize(new InitializePaymentRequest(
    email: 'ada@example.com',
    amount: Amount::of('75000.00'),       // exact money, never a float
    transactionId: 'ORD-10042',           // your own reference: 6 to 30 characters, unique per payment
    callbackUrl: 'https://shop.example.com/pay/callback?transactionId=ORD-10042',
));

$payment->getPaymentUrl();   // where to send the customer
$payment->getAccessCode();   // identifies this checkout
```

Payxy sends the customer back to `callbackUrl` **exactly as you gave it** and adds nothing to it, so put your transaction id in the URL yourself (or use the [checkout helper](#checkout-helper), which does it for you). Store the id with your order before redirecting.

Don't want to invent references? `Reference::generate('ORD')` (in `Payxy\Support`) returns a random, collision-resistant id such as `ORD-3F9A1C7E5B2D4A60`.

### Step 2: Redirect the customer

```php
header('Location: ' . $payment->getPaymentUrl());
exit;
```

The customer pays on Payxy's hosted checkout page, then lands on your `callbackUrl`.

### Step 3: Verify the payment

On your callback route (and again when a webhook arrives), ask Payxy for the authoritative result:

```php
$verification = $payxy->payments()->verify($transactionId);   // from your own record or the callback URL

if ($verification->isPaid() && $verification->amountMatches($order->amount, 'NGN')) {
    $order->markAsPaid();        // do this once per order
} elseif ($verification->isPending()) {
    // show "processing"; the webhook or a later check will settle it
} else {
    // not paid
}
```

`$order->amount` must be an `Amount` from **your database**. See [Checking the amount](#checking-the-amount) for what the result means.

If Payxy has no payment for that id (for example, the customer closed the page before paying), `verify` throws a `RequestFailedException` whose `getResponseCode()` is `'04'`. Treat that as "not paid". See [Error handling](#error-handling).

### Step 4: Listen for webhooks

Webhooks tell you about payments even when the customer never comes back to your site. They are optional but strongly recommended.

1. Register your webhook URL (for example `https://shop.example.com/webhooks/payxy`) in the Payxy dashboard.
2. Make sure `PAYXY_WEBHOOK_SECRET` is set.
3. Verify each request on its **raw body**; the [framework guides](#framework-guides) show a complete handler.

Payxy may deliver the same event more than once, so your handler must be idempotent. Details are in the [webhooks reference](#webhooks-reference).

---

## Framework guides

All frameworks share the same client and rules. Only the plumbing differs: where the client comes from and how to read the raw body.

### Plain PHP

One handler class serves every framework in this section. It verifies the webhook, skips duplicates, and confirms payments with `verify()` before fulfilling:

```php
use Payxy\Exception\WebhookVerificationException;
use Payxy\Payxy;

final class PayxyWebhookHandler
{
    public function __construct(private Payxy $payxy, private OrderStore $orders) {}

    /** Returns the HTTP status to answer with. */
    public function handle(string $rawBody, ?string $signature, ?string $timestamp, ?string $attempt): int
    {
        try {
            $event = $this->payxy->webhooks()->constructEvent($rawBody, $signature, $timestamp, $attempt);
        } catch (WebhookVerificationException) {
            return 400;
        }

        if (!$this->orders->rememberEvent($event->idempotencyKey())) {
            return 200;                                   // duplicate delivery
        }
        if ($event->isPaymentSuccess() && $event->getTransactionId() !== null) {
            $verification = $this->payxy->payments()->verify($event->getTransactionId());
            if ($verification->isPaid()) {
                $this->orders->markPaid($event->getTransactionId(), $verification->getAmount());
            }
        }

        return 200;
    }
}
```

`OrderStore` is your own class. `rememberEvent()` must insert the key under a unique constraint and return `false` if it already existed. Answer `5xx` for failures in your own processing so Payxy retries.

Callback and webhook endpoints in a plain script:

```php
// pay/callback.php
$verification = $payxy->payments()->verify($_GET['transactionId'] ?? '');
header('Location: ' . ($verification->isPaid() ? '/thanks' : '/payment-problem'));

// webhooks/payxy.php
http_response_code($handler->handle(
    file_get_contents('php://input'),                 // the raw body, before any parsing
    $_SERVER['HTTP_X_PAYXY_SIGNATURE'] ?? null,
    $_SERVER['HTTP_X_PAYXY_TIMESTAMP'] ?? null,
    $_SERVER['HTTP_X_PAYXY_ATTEMPT'] ?? null,
));
```

`$payxy->webhooks()->constructEventFromGlobals()` does the same reading for you, and `constructEventFromRequest($psr7Request)` accepts any PSR-7 server request (Slim, Mezzio and similar).

### Laravel

The service provider and the `Payxy` facade are discovered automatically (Laravel 11 or later).

```dotenv
PAYXY_PUBLIC_KEY=pk_test_xxx
PAYXY_WEBHOOK_SECRET=sk_test_xxx
# Optional
PAYXY_BASE_URL=
PAYXY_TIMEOUT=30
PAYXY_CONNECT_TIMEOUT=10
PAYXY_MAX_RETRIES=2
PAYXY_LOG_CHANNEL=payments
```

Publish `config/payxy.php` only if you want to edit it:

```bash
php artisan vendor:publish --tag=payxy-config
```

Credentials are read when `Payxy` is first resolved, so the app (and `artisan`) still boots before `.env` is filled in. Inject the singleton, or use the facade:

```php
use Payxy\Payxy;

final class PaymentController
{
    public function __construct(private Payxy $payxy) {}
}

\Payxy::payments()->verify($transactionId);   // facade
```

Routes (`routes/web.php`) and the webhook controller, reusing `PayxyWebhookHandler` from above:

```php
Route::get('/pay/callback', [PaymentController::class, 'callback']);
Route::post('/webhooks/payxy', function (Request $request, PayxyWebhookHandler $handler) {
    return response('', $handler->handle(
        $request->getContent(),                       // the raw body
        $request->header('x-payxy-signature'),
        $request->header('x-payxy-timestamp'),
        $request->header('x-payxy-attempt'),
    ));
});
```

Exclude the webhook route from CSRF protection in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['webhooks/payxy']);
})
```

Bind your `OrderStore` in a service provider so the handler can be resolved. Don't add middleware that rewrites the request body to the webhook route.

### Symfony

Register the bundle and configure it:

```php
// config/bundles.php
return [
    // ...
    Payxy\Bridge\Symfony\PayxyBundle::class => ['all' => true],
];
```

```yaml
# config/packages/payxy.yaml
payxy:
  public_key: '%env(PAYXY_PUBLIC_KEY)%'
  webhook_secret: '%env(default::PAYXY_WEBHOOK_SECRET)%'
  # base_url: ~                       # only for a proxy or mock
  # timeout: 30
  # connect_timeout: 10
  # max_retries: 2
  # http_client: my.psr18.client      # optional PSR-18 service id
```

`Payxy\Payxy` is autowirable, and SDK logs go to a dedicated `payxy` Monolog channel. Controllers, with the same `PayxyWebhookHandler` registered as a service:

```php
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class PayxyController
{
    #[Route('/pay/callback', name: 'payxy_callback')]
    public function callback(Request $request, Payxy $payxy): Response
    {
        $verification = $payxy->payments()->verify((string) $request->query->get('transactionId'));
        // render by $verification->isPaid() / isPending()
    }

    #[Route('/webhooks/payxy', methods: ['POST'])]
    public function webhook(Request $request, PayxyWebhookHandler $handler): Response
    {
        return new Response('', $handler->handle(
            $request->getContent(),                   // the raw body
            $request->headers->get('x-payxy-signature'),
            $request->headers->get('x-payxy-timestamp'),
            $request->headers->get('x-payxy-attempt'),
        ));
    }
}
```

Keep the webhook route outside any firewall that requires a login.

---

## Payments reference

All payment calls live on `$payxy->payments()`. Local validation errors throw an `InvalidArgumentException` before anything is sent.

### `payments()->initialize()`

Creates a payment and returns where to send the customer. It takes an `InitializePaymentRequest`:

| Parameter                | Type                    | Required | Description                                                                      |
| ------------------------ | ----------------------- | :------: | -------------------------------------------------------------------------------- |
| `email`                  | `string`                |   yes    | Customer's email address                                                         |
| `amount`                 | `Amount`                |   yes    | Greater than zero. See [Amounts](#amounts)                                       |
| `transactionId`          | `string`                |   yes    | Your unique reference for this payment, 6 to 30 characters                       |
| `callbackUrl`            | `?string`               |    no    | `http(s)` URL the customer returns to. Include your transaction id               |
| `currency`               | `string`                |    no    | 3-letter code. Default `NGN`                                                     |
| `metadata`               | `array<string, string>` |    no    | Up to 50 entries; names up to 100 and values up to 500 characters                |
| `productId`              | `?string`               |    no    | Up to 64 characters                                                              |
| `productDescription`     | `?string`               |    no    | Up to 500 characters                                                             |
| `applyConvenienceCharge` | `?bool`                 |    no    | Apply your convenience charge to this payment                                    |
| `customization`          | `?CheckoutCustomization`|    no    | `bodyColor`, `buttonColor`, `footerText`, `footerLink`, `footerLogo` for the hosted page (`Payxy\Payments\Model`) |

Error messages name the field and never repeat the value, for example `Invalid "transactionId": must be 6-30 characters.`

**Returns** an `InitializePaymentResponse`:

| Method                                              | Meaning                                                              |
| --------------------------------------------------- | -------------------------------------------------------------------- |
| `getPaymentUrl()`                                   | Where to send the customer. Always on Payxy's official checkout host |
| `getAccessCode()`                                   | Identifies the checkout; used by `getPaymentInfo`                    |
| `getRawPaymentUrl()`                                | The URL exactly as the gateway sent it (not used for redirects)      |
| `getMessage()`, `getRequestId()`, `getStatusCode()`, `getResponseCode()` | Gateway message, request id for support, HTTP status, raw code |

Every response object also offers `get($key)` for any other field and `toArray()` for the decoded body.

> **`initialize` is never retried automatically,** because a repeat could create a second payment. If it fails with a `TransportException` where `isOutcomeUnknown()` is `true`, the payment may exist: call `verify($transactionId)` before trying again. Reusing a `transactionId` throws `DuplicateTransactionException`.

### `payments()->verify()`

Returns the authoritative state of a payment. Safe to call repeatedly; the SDK retries it automatically on transient failures. It returns a `VerifyPaymentResponse`:

| Method                                                                   | Meaning                                                                             |
| ------------------------------------------------------------------------ | ----------------------------------------------------------------------------------- |
| `isPaid()`                                                               | Response code `00` **and** a data record whose `isSuccessful` is `true`             |
| `isPending()`                                                            | Still processing (OTP, 3-D Secure, validation, virtual account) or status `Pending` |
| `amountMatches($expected, $currency, $feeAllowance)`                     | Is the paid amount what the order costs?                                            |
| `checkAmount(...)`                                                       | The same check, returning the detailed `AmountCheck`                                |
| `getAmount()`, `getCurrency()`                                           | The order amount                                                                    |
| `getCustomerPaid()`                                                      | What the customer was charged **including fees**                                    |
| `getAmountPaidToMerchant()`, `getProcessingFee()`, `getPlatformVat()`, `getMerchantVat()`, `getTotalCharges()`, `getFeeBearer()` | Fee breakdown |
| `hasFeeBreakdown()`                                                      | `true` when `getCustomerPaid()` is reported                                         |
| `getSettlementStatus()` / `settlementStatus()`                           | Raw string / typed `SettlementStatus` (or `null`)                                   |
| `getStatus()`, `isSuccessful()`, `getPaymentType()`, `getGatewayResponse()`, `getPaymentReference()`, `getProviderReference()`, `getMerchantId()`, `getMaskedCardPan()`, `getCardType()`, `getSessionId()` | Payment details |

Outcomes at a glance:

| Situation                     | What you get                                                                    |
| ----------------------------- | ------------------------------------------------------------------------------- |
| Paid                          | `isPaid()` is `true`                                                            |
| In progress                   | `isPending()` is `true`                                                         |
| Failed (declined, etc.)       | Returned, not thrown: `isPaid()` and `isPending()` are both `false`             |
| Payxy has no such transaction | Throws `RequestFailedException` whose `getResponseCode()` is `'04'`             |

#### Checking the amount

```php
$verification->checkAmount(Amount::of('100.00'), 'NGN');    // AmountCheck::Exact, ::Underpaid, ::Overpaid, …
$verification->amountMatches(Amount::of('100.00'), 'NGN');  // true only for Exact or WithinFeeAllowance
```

| `AmountCheck`        | When                                                                | Accept |
| -------------------- | ------------------------------------------------------------------- | :----: |
| `Exact`              | Equals the expected amount                                          |  yes   |
| `WithinFeeAllowance` | No fee breakdown, above expected by no more than `$feeAllowance`    |  yes   |
| `Underpaid`          | Below the expected amount                                           |   no   |
| `Overpaid`           | Above expected on a payment that has a fee breakdown                |   no   |
| `UnexplainedSurplus` | Above expected with no breakdown and no (or too small an) allowance |   no   |
| `CurrencyMismatch`   | The currency differs, or is missing, when one is expected           |   no   |
| `Missing`            | The response has no usable amount                                   |   no   |

Always pass the amount from **your own records**, and compare against `getAmount()`, never `getCustomerPaid()` (which includes fees). To accept payments that arrive without a fee breakdown and with fees inside the amount, pass a `$feeAllowance` such as `Amount::of('2.00')`; without one, such a surplus is `UnexplainedSurplus`.

### `payments()->getPaymentInfo()`

Public details of a checkout. Needs no authentication.

```php
$info = $payxy->payments()->getPaymentInfo($payment->getAccessCode());
$info->getEmail();
$info->getPaymentUrl();
$info->getBusinessName();    // may be null
$info->get('merchantCode');  // any other field of the response
```

A completed or invalid link throws `ValidationException`. `getRealtimeToken()` and `getRealtimeTokenExpiresAt()` return short-lived secrets: never log them or use them as API credentials.

### Amounts

Money is an `Amount`, stored as integer minor units (kobo). It cannot hold a float, so rounding drift cannot happen.

```php
use Payxy\Support\Amount;

Amount::of('1500.50');               // a decimal string with at most 2 decimals
Amount::of(1500);                    // whole major units (naira)
Amount::fromMinorUnits(150050);      // kobo
Amount::of('1500.50')->toDecimalString();   // "1500.50"
Amount::of('1500.50')->toMinorUnits();      // 150050
```

Floats are rejected on purpose. Amounts in responses are `?Amount`, and a missing or unparseable value is `null` rather than an error.

---

## Webhooks reference

Payxy calls your URL when a payment succeeds or fails, a transfer into a virtual account is received, a settlement completes or fails, and when a virtual account is created or fails. You need `webhookSecret` configured.

### The raw body requirement

The signature covers the exact bytes Payxy sent. If a JSON parser reads the body first, re-encoding changes the bytes and verification fails. Hand the SDK the original string: `file_get_contents('php://input')`, `$request->getContent()` (Laravel, Symfony) or a PSR-7 body.

### Verifying a webhook

```php
$webhooks = $payxy->webhooks();

$event = $webhooks->constructEvent($rawBody, $signature, $timestamp, $attempt);   // throws on failure
$event = $webhooks->constructEventFromGlobals();                                    // plain PHP
$event = $webhooks->constructEventFromRequest($psr7Request);                        // PSR-7
$webhooks->verify($rawBody, $signature, $timestamp);                                // boolean
```

The signature, timestamp and attempt come from the `x-payxy-signature`, `x-payxy-timestamp` and `x-payxy-attempt` headers. `constructEvent` throws `WebhookVerificationException` unless all of the following hold, checked in this order:

1. The `x-payxy-signature` and `x-payxy-timestamp` headers are present and well formed.
2. The body is at most 1 MiB (measured in bytes).
3. The timestamp is within 5 minutes of now, in either direction.
4. The HMAC-SHA512 of `timestamp . "." . rawBody` matches one of the signatures. Payxy may send several, comma-separated, while secrets rotate.
5. The body is a JSON object with an `Event` and a `Data` object. `payment.success` and `payment.failed` must also carry `Data.TransactionId`. If the body carries a `Mode`, it must match your secret (`Test` for `sk_test_`, `Production` for `sk_live_`). A body with no `Mode` is accepted, because the signature, made with the environment's own secret, already proves where it came from.

Answer `400` for a `WebhookVerificationException`.

### Event types

| Event                     | Means                                                             | Read it with               |
| ------------------------- | ----------------------------------------------------------------- | -------------------------- |
| `payment.success`         | A checkout payment succeeded                                      | getters on the event       |
| `payment.failed`          | A checkout payment failed                                         | getters on the event       |
| `payment.received`        | A transfer into a virtual account was verified. Not yet settled   | `$event->paymentReceived()`|
| `settlement.completed`    | Your Payxy wallet was credited                                    | `$event->settlement()`     |
| `settlement.failed`       | Settlement hit a terminal failure. The payment itself may be fine | `$event->settlement()`     |
| `virtual_account.created` | A virtual account finished being created                          | `$event->virtualAccount()` |
| `virtual_account.failed`  | Virtual account creation failed for good                          | `$event->virtualAccount()` |

The typed accessors return `null` for other events, and `$event->data()` is always the raw `Data` array. Unknown event names are never rejected; branch on `getEvent()` or the `isPaymentSuccess()`, `isPaymentFailed()`, `isPaymentReceived()`, `isSettlementCompleted()`, `isSettlementFailed()`, `isVirtualAccountCreated()` and `isVirtualAccountFailed()` helpers. `payment.received` means money arrived, not that your wallet has it: wait for `settlement.completed` before treating funds as available.

### The event object

| Member                                                                                    | Meaning                                                              |
| ----------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `getEvent()`, `getMode()`, `getMerchantId()`                                              | Raw event name, `Test` / `Production` (or `null`), your merchant id  |
| `getEventId()`                                                                            | Stable across every delivery and resend of this event                |
| `getDeliveryId()`                                                                         | Different for every delivery attempt                                 |
| `getVersion()`, `getOccurredAtUtc()`, `getOccurredAt()`                                   | Schema version; when it happened, as sent and as a `DateTimeImmutable` |
| `getTransactionId()`, `getPaymentReference()`, `getProviderReference()`, `getSessionId()` | References                                                           |
| `getStatus()`, `isSuccessful()`, `getGatewayResponse()`, `getPaymentType()`, `getCurrency()` | Outcome and channel                                              |
| `getAmount()`, `getCustomerPaid()`, `getAmountPaidToMerchant()`, `getProcessingFee()`, `getPlatformVat()`, `getMerchantVat()`, `getTotalCharges()`, `getFeeBearer()` | Money (`?Amount`) and who bears the fees |
| `getSettlementStatus()` / `settlementStatus()`                                            | Raw string / typed `SettlementStatus`                                |
| `$event->attempt`                                                                         | Delivery attempt, from `x-payxy-attempt` (a property)                |
| `idempotencyKey()`                                                                        | The event id; `"payment.success:ORD-10042"` on older payloads        |
| `toArray()`                                                                               | The full payload                                                     |

The typed `Data` objects (`PaymentReceivedData`, `SettlementData`, `VirtualAccountEventData`, in `Payxy\Webhook\Data`) expose the same style of getters for their event: references, `Amount` values, `DateTimeImmutable` instants and `toArray()`. Fields that Payxy omits read as `null`. `PaymentReceivedData` also answers `isExactAmount()`, `isUnderpaid()`, `isOverpaid()`, `isAmountMismatch()` and `isLatePayment()`, and credit `getActualAmount()`, not the expected one.

### Delivery and idempotency

Payxy can deliver an event more than once, and [`webhookEvents()->resend()`](#webhook-history-and-resend) does it on purpose. De-duplicate on `idempotencyKey()` (or on your order's paid state), and never on `getDeliveryId()`, which changes every attempt. Don't trust a webhook for fulfilment on its own: call `payments()->verify()` first, or use the [checkout helper](#checkout-helper), which does.

---

## Checkout helper

`PayxyCheckout` is an optional layer over `initialize` and `verify` for one storefront. Every outcome it reports comes from Verify plus an amount check, never from a redirect or webhook alone, so it is the fastest safe integration. Extend it once and declare your settings as properties:

```php
use Payxy\Checkout\PayxyCheckout;
use Payxy\Support\Amount;

final class ShopCheckout extends PayxyCheckout
{
    protected string $callbackUrl = 'https://shop.example.com/pay/callback';
    protected ?string $buttonColor = '#0A84FF';

    protected function expectedAmount(string $transactionId): ?Amount
    {
        return Order::findByPayxyId($transactionId)?->amount;     // from your own records
    }

    // Optional: accept a surplus when Payxy sends no fee breakdown (bank transfers include fees).
    protected function feeAllowance(string $transactionId, Amount $expected): ?Amount
    {
        return Amount::fromMinorUnits((int) ceil($expected->toMinorUnits() * 0.02));   // 2%
    }
}

$checkout = new ShopCheckout($payxy);
```

| Setting                                  | Default                                                           |
| ---------------------------------------- | ----------------------------------------------------------------- |
| `$callbackUrl`                           | required; or override `callbackUrl()` to compute it at runtime    |
| `expectedAmount($transactionId)`         | required; return the order's amount, or `null` if unknown         |
| `feeAllowance($transactionId, $expected)`| `null` (strict)                                                   |
| `$currency`                              | `NGN`                                                             |
| `$display`                               | `CheckoutDisplay::Redirect` (or `Popup`)                          |
| `$buttonText`                            | `Pay {amount}`                                                    |
| `$buttonColor`, `$bodyColor`             | hex only: `#RGB`, `#RRGGBB`, `#RRGGBBAA`                          |
| `$footerText`, `$footerLink`, `$footerLogo` | none                                                           |
| `$merchantName`                          | `Checkout`                                                        |
| `$applyConvenienceCharge`                | unset                                                             |
| `$referencePrefix`                       | `PXY`                                                             |
| `$callbackReferenceKeys`                 | `transactionId`, `transaction_id`, `reference`, `trxref`, `txRef` |

With no `feeAllowance`, any surplus without a fee breakdown is an amount mismatch and underpayment is never accepted. Returning an allowance accepts a payment that exceeds the order by up to that amount, whether the surplus is fees or an overpayment.

### Two ways to use it

| Mode                          | You render                     | What you do                                              |
| ----------------------------- | ------------------------------ | -------------------------------------------------------- |
| **A. Payxy's page**           | nothing: redirect the customer | `start()`, then redirect to `$session->paymentUrl`       |
| **B. Your own checkout page** | your page and button           | `start()`, return `$session->toArray()` to your frontend |

### `start`, `confirm`, `handleCallback`, `handleWebhook`

```php
use Payxy\Checkout\CheckoutRequest;

$session = $checkout->start(new CheckoutRequest(
    email: 'ada@example.com',
    amount: Amount::of('75000.00'),
    transactionId: 'ORD-10042',          // optional: generated from $referencePrefix if omitted
    metadata: ['cartId' => '42'],
));
echo json_encode($session->toArray());   // transactionId, accessCode, paymentUrl, amount, currency, environment

$result = $checkout->confirm('ORD-10042');                                 // id from your own record
$result = $checkout->handleCallback($_GET);                                // finds the id in the callback's query
$result = $checkout->handleWebhook($rawBody, $signature, $timestamp);     // optional 4th argument: attempt
```

`start()` appends `transactionId` to the callback URL for you, because Payxy redirects to the callback exactly as given. The session contains **no credentials**, so it is safe to send to your frontend.

`$result->outcome` is a `CheckoutOutcome`:

| Outcome          | Meaning                                                                                      |
| ---------------- | -------------------------------------------------------------------------------------------- |
| `Successful`     | Paid, and the amount is acceptable. **Fulfil the order.**                                    |
| `Pending`        | Still processing. Wait for the webhook, or confirm again later.                              |
| `Failed`         | Not paid, or Payxy has no record (the customer abandoned the checkout).                      |
| `AmountMismatch` | Paid, but not the expected amount, or `expectedAmount` returned `null`. **Do not fulfil.**   |

`$result->isSuccessful()` is a shortcut, `$result->verification` is the full verify response (`null` for an abandoned checkout), `$result->amountCheck` is the detailed `AmountCheck`, and `$result->transactionId` is the id that was checked.

`handleWebhook` authenticates the webhook first, so an unsigned or tampered request never reaches the API. Only `payment.success` and `payment.failed` are checkout events: for any other authenticated event it returns `null`.

### Pay button and page

For server-rendered sites the helper can produce HTML:

```php
echo $checkout->page(new CheckoutRequest(email: $email, amount: $amount));      // start() + a full page
echo $checkout->renderButton($session, $cspNonce);                              // a button for your own page
```

`button()` and `page()` start the payment and render in one call; `renderButton()` and `renderPage()` render a session you already have. Print the HTML unescaped (`{!! !!}` in Blade, `|raw` in Twig).

- Every dynamic value is HTML-escaped. Colours are applied through a `<style>` rule, never inline `style=` attributes.
- Pass a CSP nonce (8 to 128 base64 or URL-safe characters) and it is added to every inline `<style>` and `<script>`. Without one, your policy must allow those inline elements.
- `Redirect` renders a plain link to the checkout. `Popup` opens the checkout in a full-screen frame on https pages and falls back to a redirect on plain http (local development). Popup mode is experimental and uses Payxy's browser checkout script, bundled with the package under its own licence notice. Either way the customer ends up on your callback, and the outcome is decided by `confirm`.

---

## Money movement

Beyond hosted checkout, the SDK can create virtual accounts, read your wallet balance, send payouts, simulate sandbox credits and replay webhooks. These services sit beside `payments()` and the checkout helper without changing them.

```php
use Payxy\Merchant\{AccountType, Provider};
use Payxy\Merchant\Request\{CreateVirtualAccountRequest, VirtualAccountCustomer};

$idempotencyKey = bin2hex(random_bytes(16));   // save this with your own record first

$account = $payxy->virtualAccounts()->create(new CreateVirtualAccountRequest(
    provider: Provider::Optimus,
    accountType: AccountType::Static,
    customer: new VirtualAccountCustomer(
        reference: 'customer-123',
        firstName: 'Ada',
        lastName: 'Okafor',
        bvn: '12345678901',
        dateOfBirth: '1990-01-01',
    ),
), $idempotencyKey);
$account->getAccountNumber();
```

**Authentication.** These calls need both your public key **and** your secret key (the `webhookSecret`). The SDK exchanges the pair for a short-lived access token, caches it and refreshes it for you; if the API reports a token as revoked, it gets a new one and retries once. Nothing is checked at startup, so card-only integrations need no secret key. If the secret is missing, the first money-movement call throws a `CredentialsException` before any request is sent.

### Idempotency keys

Every call that changes state takes an idempotency key as its last argument. Generate it **once per operation and store it before you send**, so a retry after a crash or timeout reuses it. The SDK sends the same key on every automatic retry, and reusing a key returns the original result. A key is 1 to 100 printable ASCII characters. A missing or malformed key fails locally with nothing sent.

### Providers

```php
$providers = $payxy->providers()->list()->getProviders();   // list of arrays, as returned
```

### Virtual accounts

`VirtualAccountsService::create()` takes a `CreateVirtualAccountRequest` and an idempotency key:

| Parameter                          | Required       | Notes                                                 |
| ---------------------------------- | -------------- | ----------------------------------------------------- |
| `provider`                         | yes            | `Provider::Vfd` or `Provider::Optimus`. Never switched automatically |
| `accountType`                      | yes            | `AccountType::Static` (reusable) or `AccountType::Dynamic` (single-use) |
| `customer`                         | yes            | A `VirtualAccountCustomer`, below                     |
| `expiresAt`                        | `Dynamic` only | `DateTimeInterface` or ISO 8601 string                |
| `expectedAmount`                   | `Dynamic` only | `Amount` or string such as `"2500.00"`                |
| `metadata`                         | no             | Key/value pairs, up to 50                             |
| `currency`                         | no             | Only `NGN` is supported                               |

`VirtualAccountCustomer`: `reference`, `firstName`, `lastName` (required); `email`, `phone` (optional); `bvn` (11 digits) and `dateOfBirth` (`YYYY-MM-DD`), required for `Static` accounts. The BVN is sent to the provider for KYC and is not stored by Payxy.

The `VirtualAccountResponse` offers `getId()`, `getAccountNumber()`, `getAccountName()`, `getBankName()`, `getBankCode()`, `getProvider()`, `getAccountType()`, `getStatus()`, `getCustomerReference()`, `getExpiresAt()`, `getExpectedAmount()` and `isIdempotentReplay()` (true when the key was already used).

### Wallet balance

```php
$wallet = $payxy->wallet()->balance();
$wallet->getBalance()?->toDecimalString();   // "125000.50", what can be spent now
```

Also `getAvailableBalance()`, `getLedgerBalance()`, `getWalletId()` and `getCurrency()`.

### Payouts

Payouts need **live** keys, an allowlisted server IP and a stored idempotency key. Test keys are refused locally.

```php
use Payxy\Merchant\Request\CreatePayoutRequest;

$payout = $payxy->payouts()->create(new CreatePayoutRequest(
    accountNumber: '0123456789',
    accountName: 'Ada Okafor',
    bankCode: '000013',
    amount: '5000.00',
    bankName: 'GTBank',
    narration: 'Vendor payment',
), $idempotencyKey);

$payxy->payouts()->get($payout->getId());   // by Payxy's payout id
```

`transactionPin` is an optional argument for wallets whose debit still requires one; it is never logged or printed. The `PayoutResponse` offers `getId()`, `getStatus()` (for example `PROCESSING` or `SUCCESS`), `getAmount()` and `isIdempotentReplay()`.

If `create` throws a `TransportException`, the payout may exist. Call `create` again with the **same** key, or look it up with `tsq`. Never send a new key to "try again".

#### Checking a payout's status (TSQ)

A transaction status query asks Payxy for the current status of a payout using the idempotency key you sent when creating it. It is read-only, safe to repeat, and works while a payout is still processing, which makes it the right call for polling or for settling doubt after a `TransportException`.

```php
$status = $payxy->payouts()->tsq('merchant-payout-20261106-001');   // the original idempotency key
$status->getId();       // Payxy's payout id
$status->getStatus();   // e.g. "PROCESSING" or "SUCCESS"
```

The reference must belong to your merchant account: an unknown or another merchant's reference throws `NotFoundException`, and a blank one or one over 100 characters throws `InvalidArgumentException` before any request. It works with test and live keys, and transient failures are retried automatically because the call changes nothing.

### Sandbox simulator

With test keys you can fake a transfer into a test virtual account; the normal webhook is queued to your test URL. Live keys are refused locally, and the simulator never touches the wallet balance.

```php
use Payxy\Merchant\Request\SimulateCreditRequest;

$credit = $payxy->simulator()->creditVirtualAccount(new SimulateCreditRequest(
    accountNumber: '1000000001',
    amount: '2500.00',
    senderName: 'Sandbox Customer',
), $idempotencyKey);
$credit->getEventId();   // the stable webhook event id, for de-duplication
$credit->getStatus();    // "QUEUED"
```

### Webhook history and resend

```php
$history = $payxy->webhookEvents()->list();   // up to the latest 100 events; pass ['limit' => '10'] to filter
foreach ($history->getEvents() as $event) {
    $event->getEventId();      // stable; pass it to resend()
    $event->getEventType();    // "settlement.completed"
    $event->wasDelivered();    // true once an attempt reached your endpoint
    foreach ($event->getDeliveries() as $delivery) {
        $delivery->getAttempt();
        $delivery->getStatus();           // "Delivered", ...
        $delivery->getHttpStatusCode();   // what your endpoint answered
    }
}

$queued = $payxy->webhookEvents()->resend('evt_...');   // delivers the same event again
$queued->getStatus();                                   // "QUEUED"
```

`resend` creates a new delivery of the same event to your current webhook URL. It is never retried automatically.

### Money-movement errors

These add to the [error tree](#error-handling):

| Exception                     | When                                                                |
| ----------------------------- | ------------------------------------------------------------------- |
| `CredentialsException`        | The secret key is missing. Thrown before any request.               |
| `ConfigurationException`      | Payouts with test keys, or the simulator with live keys             |
| `InvalidArgumentException`    | Bad input or idempotency key. Nothing was sent.                     |
| `AuthenticationException`     | The keys were rejected, or the server IP is not allowlisted         |
| `ValidationException`         | A final rejection: fix the request and use a **new** key            |
| `ServerException`             | Uncertain (502/503): retry later with the **same** key              |
| `ConflictException`           | For example a static account already exists for that BVN            |
| `NotFoundException`           | Unknown payout reference (TSQ) or webhook event                     |
| `TransportException`          | Could not reach Payxy. With a key, the call is safe to repeat as is |

---

## Error handling

Everything the SDK throws implements `PayxyException`, so one `catch` can cover it all.

```
PayxyException (interface)
├─ InvalidArgumentException         your input failed local validation (nothing was sent)
│  └─ ConfigurationException        bad settings, keys or URLs
│     └─ CredentialsException       the secret key a money-movement call needs is missing
├─ TransportException               could not reach Payxy (isOutcomeUnknown())
├─ InvalidResponseException         Payxy answered with something unusable
├─ WebhookVerificationException     a webhook could not be trusted
└─ ApiException                     Payxy answered with an error
   ├─ AuthenticationException         401
   ├─ PermissionDeniedException       403
   ├─ ValidationException             400, 422
   ├─ DuplicateTransactionException   the transactionId already exists
   ├─ NotFoundException               404
   ├─ ConflictException               409
   ├─ RateLimitException              429
   ├─ ServerException                 500 and above
   └─ RequestFailedException          HTTP 200, but the responseCode is not "00"
```

`ApiException` offers `getStatusCode()`, `getResponseCode()` (raw), `responseCode()` (typed, or `null`), `getErrors()` (`['field' => ['message']]` from validation problems), `getRequestId()` (give this to Payxy support), `getRetryAfterSeconds()` and `getResponseBody()`. Error messages never contain keys, tokens or the values you passed in.

**A 2xx response is not success by itself.** Payxy reports some failures with HTTP 200 and a `responseCode`. The SDK checks it and throws `RequestFailedException` when it is not `00` (or a code the call documents as accepted, such as `05` for a payout still processing). The one exception is `verify`, where a failed or pending payment is returned as a result rather than thrown.

```php
use Payxy\Exception\{ApiException, AuthenticationException, PayxyException, RequestFailedException, TransportException};

try {
    $verification = $payxy->payments()->verify($id);
} catch (RequestFailedException $e) {
    if ($e->getResponseCode() === '04') {
        // unknown transaction: not paid
    }
} catch (AuthenticationException $e) {
    // wrong or non-allowlisted key: fix configuration, do not retry
} catch (ApiException | TransportException $e) {
    // Payxy or the network is unavailable: try again later
} catch (PayxyException $e) {
    throw $e;
}
```

### Gateway response codes

| Code | Meaning            | Code | Meaning                  |
| ---- | ------------------ | ---- | ------------------------ |
| `00` | Successful         | `09` | Virtual account created  |
| `01` | Unauthorized       | `10` | Declined                 |
| `02` | Failed             | `12` | Pending 3-D Secure       |
| `03` | Not found          | `13` | Password change required |
| `04` | Bad request        | `14` | Chargeback               |
| `05` | Processing         | `15` | Timed out                |
| `06` | Awaiting OTP       | `16` | Account locked           |
| `07` | Pending validation |      |                          |
| `08` | Insufficient funds |      |                          |

Codes `05`, `06`, `07`, `09` and `12` count as "in progress" for `isPending()`. Unknown codes never throw; read the raw `getResponseCode()`. A code's meaning depends on the call: `verify` is final-success only on `00` with `isSuccessful` true.

---

## Configuration

```php
use Payxy\Http\RetryPolicy;
use Payxy\Payxy;
use Payxy\PayxyConfig;

$payxy = new Payxy(
    new PayxyConfig(
        publicKey: 'pk_test_…',
        webhookSecret: 'sk_test_…',
        timeoutSeconds: 30.0,
        connectTimeoutSeconds: 10.0,
        retryPolicy: RetryPolicy::withRetries(3),
        maxResponseBytes: 2_097_152,
        userAgentSuffix: 'my-shop/1.4',
    ),
    httpClient: $psr18Client,    // all remaining arguments are optional
    logger: $psr3Logger,
);
```

| Option                  | Default                   | Notes                                                                                     |
| ----------------------- | ------------------------- | ----------------------------------------------------------------------------------------- |
| `publicKey`             | required                  | `pk_test_…` or `pk_live_…`                                                                |
| `webhookSecret`         | none                      | Must match the public key's environment                                                   |
| `baseUrl`               | the environment's API URL | For proxies and mocks. `https` only, except `http` for `localhost`, `127.0.0.1`, `[::1]`. A key cannot be pointed at the other environment's official URL |
| `timeoutSeconds`        | `30`                      | Total time per request                                                                    |
| `connectTimeoutSeconds` | `10`                      | Must not exceed `timeoutSeconds`                                                          |
| `retryPolicy`           | 3 retries: 2s, 5s, 10s    | See [Retries](#retries)                                                                   |
| `maxResponseBytes`      | `2097152` (2 MiB)         | Larger responses are rejected. Minimum 1024                                               |
| `userAgentSuffix`       | none                      | 1 to 64 printable ASCII characters, appended to the `User-Agent` header                   |

`Payxy` also accepts, by name, `requestFactory`, `streamFactory`, `sleeper` and `clock` (current Unix time as a closure), mainly for tests.

### Environment variables

PHP does not read the environment on its own; pass the values to `PayxyConfig` as shown above. The Laravel config file and the Symfony bundle read these, and `PayxyConfig::fromArray()` accepts the same settings as an array (numeric strings are fine):

| Variable / setting                  | Maps to                 | Notes                                                    |
| ----------------------------------- | ----------------------- | -------------------------------------------------------- |
| `PAYXY_PUBLIC_KEY` / `public_key`   | `publicKey`             | required                                                 |
| `PAYXY_WEBHOOK_SECRET` / `webhook_secret` | `webhookSecret`   |                                                          |
| `PAYXY_BASE_URL` / `base_url`       | `baseUrl`               |                                                          |
| `PAYXY_TIMEOUT` / `timeout`         | `timeoutSeconds`        | seconds                                                  |
| `PAYXY_CONNECT_TIMEOUT` / `connect_timeout` | `connectTimeoutSeconds` | seconds                                          |
| `PAYXY_MAX_RETRIES` / `max_retries` | `retryPolicy`           | 0 to 5. The Laravel and Symfony defaults are 2; `fromArray()` alone defaults to 3 |
| `user_agent_suffix`                 | `userAgentSuffix`       | `fromArray()` only                                       |
| `PAYXY_LOG_CHANNEL`                 | Laravel log channel     | Laravel only                                             |

Blank values count as unset.

### Retries

Only calls that are safe to repeat are retried: `verify`, `getPaymentInfo`, and money-movement calls that carry an idempotency key or change nothing. They retry on network errors and on HTTP 429, 502, 503 and 504, waiting 2s, 5s, then 10s. A `Retry-After` header can lengthen a wait (up to 30s), never shorten it. `payments()->initialize()`, `webhookEvents()->resend()` and the token exchange are never retried, and 401 and 403 are never retried (a rejected money-movement token is renewed once).

```php
RetryPolicy::none();
RetryPolicy::withRetries(2);          // 2s, 5s
new RetryPolicy([500, 1500]);         // custom delays in ms: at most 5 entries, each up to 60000
```

### HTTP client

With Guzzle or `symfony/http-client` installed, the SDK builds a client that never follows redirects (so your `Authorization` header cannot be forwarded elsewhere), verifies TLS, and applies your timeouts. Otherwise any PSR-18 client is discovered. If you pass your own client, keep TLS verification on, disable redirects, and make sure it returns 4xx and 5xx responses instead of throwing (Guzzle: `'http_errors' => false`).

### Logging

Pass any PSR-3 logger as `logger:` (Laravel and Symfony wire theirs for you). Each request logs only `method`, `path` (the template, such as `/api/Payments/GetPaymentInfo/{accessCode}`), `status`, `attempt` and `duration_ms`. Keys, headers, bodies, tokens and resolved path values are never logged.

---

## Testing

### Use the sandbox

A `pk_test_` key talks to the sandbox (`https://api.payxy.ng`). No real money moves. Sandbox test cards are in the [Payxy documentation](https://docs.payxy.africa) (see Test Cards; the OTP differs per card). With test keys you can also use the [simulator](#sandbox-simulator) to fake incoming transfers.

### Fake the HTTP layer in unit tests

`Payxy` accepts any PSR-18 client, so your unit tests never touch the network. With Guzzle:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Payxy\Http\Sleeper;
use Payxy\Payxy;
use Payxy\PayxyConfig;

$mock = new MockHandler([
    new Response(200, [], json_encode([
        'responseCode' => '00',
        'data' => ['isSuccessful' => true, 'status' => 'Successful', 'amount' => 100.0, 'currency' => 'NGN'],
    ])),
]);

$payxy = new Payxy(
    new PayxyConfig('pk_test_0123456789abcdef'),
    httpClient: new Client(['handler' => HandlerStack::create($mock), 'http_errors' => false]),
    sleeper: new class implements Sleeper {
        public function sleep(int $milliseconds): void {}     // skip retry waits
    },
);

$payxy->payments()->verify('ORD-10042')->isPaid();   // true
```

Useful fixtures:

| Case               | HTTP | Body                                                                                             |
| ------------------ | ---- | ------------------------------------------------------------------------------------------------ |
| Initialize ok      | 200  | `{"responseCode":"00","data":{"paymentUrl":"https://checkout.payxy.ng/abc","accessCode":"abc"}}` |
| Duplicate id       | 400  | `{"responseCode":"04","responseMessage":"Transaction id already exists"}`                        |
| Bad key            | 401  | plain text `Invalid merchant key`                                                                |
| Verify: pending    | 200  | `{"responseCode":"05","data":{"status":"Pending","isSuccessful":false}}`                         |
| Verify: unknown id | 200  | `{"responseCode":"04","responseMessage":"Invalid transaction id.","data":null}`                  |

For money-movement tests, queue a token response first: `{"responseCode":"00","data":{"accessToken":"t1","expiresIn":900}}`.

### Sign a test webhook

To test your webhook route, sign a payload the same way Payxy does:

```php
$body = json_encode([
    'Event' => 'payment.success',
    'Mode' => 'Test',
    'Data' => ['TransactionId' => 'ORD-10042', 'IsSuccessful' => true],
]);
$timestamp = (string) time();
$signature = hash_hmac('sha512', $timestamp . '.' . $body, 'sk_test_fedcba9876543210');
// POST the exact body string with headers:
//   x-payxy-signature: <signature>
//   x-payxy-timestamp: <timestamp>
```

For deterministic tests pass `clock: fn () => $fixedUnixTime` to `new Payxy(...)`.

## Go live

1. Test the whole flow in the sandbox with `pk_test_…` and `sk_test_…`, including a signed test webhook.
2. Replace the keys with `pk_live_…` and `sk_live_…`. The prefix selects production; nothing else changes.
3. Allowlist your server's IP with Payxy if IP rules are enabled. Lists are separate for test and live. Add your backend's public outbound (egress) IP, including any NAT gateway or proxy it calls out through, before enforcement is turned on. Don't add customers' IPs, private addresses or Payxy's webhook sender addresses.
4. Register your production webhook URL in the Payxy dashboard. It must be https.
5. Confirm that you fulfil orders only after `verify` (or the checkout helper) says they are paid, and only once.

---

## Security

**What the SDK does for you**

- **Keys stay secret.** They are masked in output (`pk_test_***`), cannot be serialized, and never appear in logs, errors or responses. Read the real value only with `->value()`.
- **No redirects.** The default client never forwards your `Authorization` header to another host, and TLS verification is on.
- **The checkout URL is not taken from the gateway.** `getPaymentUrl()` is built from the official checkout origin and the access code.
- **Exact money.** Integer minor units, plus `checkAmount` against the amount you expect.
- **Webhooks.** HMAC-SHA512 over the raw bytes, constant-time comparison, a 5-minute replay window, a 1 MiB cap and a mode check when the event carries a `Mode`.
- **Safe HTML.** Rendered output is escaped, colours are hex-only, and a CSP nonce is supported.
- **Bounded parsing.** Responses are size-capped (2 MiB by default), JSON nesting is limited to 64 levels, and large integers are read as strings instead of being rounded.

**What is up to you**

- Keep `PAYXY_PUBLIC_KEY` and `PAYXY_WEBHOOK_SECRET` in a secret store, not in version control or front-end code.
- Look up the expected amount from your own data.
- Fulfil an order only after `verify` (or the checkout helper) says it is paid, and do it once per order.
- Serve your webhook and callback routes over https, and give the webhook route the raw body.
- Don't log `getRealtimeToken()` or full webhook payloads you don't need.
- Never ask customers for card data on your own pages. The SDK does not support that.

To report a vulnerability, email support@payxy.africa privately rather than opening a public issue. Include the SDK version, PHP version and reproduction steps, and never include live keys, webhook secrets or real card data.

---

## Good to know

Notes on how the Payxy gateway behaves. The SDK handles each of them, but they explain results you may see.

- **Callbacks carry no parameters.** The hosted checkout redirects to `callbackUrl` exactly as provided and adds nothing. The checkout helper puts `transactionId` in the URL for you; when you call `initialize` directly, add it yourself or use the id you stored.
- **Verifying a payment that was never attempted** returns HTTP 200 with `responseCode: "04"` and "Invalid transaction id.". The SDK throws `RequestFailedException` (code `04`); the checkout helper reports `Failed`.
- **Pending can stay pending.** An attempt that did not complete keeps `responseCode: "05"` and `status: "Pending"`, with explanatory text in `getGatewayResponse()` (for example "Transaction abandoned after 30 minutes"). The SDK reports pending; read the gateway response if you want to treat these as failed.
- **Fees are added on top.** The customer pays the order amount plus fees. `getAmount()` stays the order amount, `getCustomerPaid()` is the total charged, and `getAmountPaidToMerchant()` equals the order amount when the customer bears the fees (`FeeBearer: "Customer"`).
- **Successful bank transfers can report an `amount` that includes fees** and no fee breakdown. The SDK flags the surplus as `UnexplainedSurplus` unless you set a fee allowance.
- **Payment and settlement are separate.** `isPaid()` answers only whether the customer paid. Check the settlement status separately if you need to know the money has reached your wallet.
- **A successful payment** returns `responseCode: "00"`, `status: "Successful"`, `isSuccessful: true` and a fee breakdown. Card payments also carry the masked card number; transfers carry originator bank, name and account details in `toArray()`.
- **`getPaymentInfo`** also returns `merchantCode` and the available payment methods with their charges. `getBusinessName()` may be absent.
- **Amounts** arrive as JSON numbers (`75000.0`) or strings (`"101.51"`). The SDK accepts both.
- **`paymentUrl` from Initialize** may be relative or absent; the SDK always builds the checkout URL for the environment from the access code.
- **Webhook bodies can omit `Mode`** and can include extra fields beyond the documented ones. The verifier accepts an absent `Mode`, rejects a wrong one, and everything is available through `toArray()`.
- **Webhook retry timing** is not a guarantee; use the history and resend calls to reconcile gaps.

---

## Troubleshooting

| Symptom                                                                | Likely cause and fix                                                                                                                      |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `AuthenticationException`, HTTP 401 "Invalid merchant key"             | Wrong key, a key from the other environment, or your server's IP is not allowlisted with Payxy                                            |
| `ConfigurationException` at startup                                    | A key doesn't look like `pk_test_…`/`sk_…`, the secret and key are from different environments, or a setting is out of range              |
| `WebhookVerificationException: Webhook signature does not match.`      | A body parser changed the bytes (use the raw body), the wrong secret is configured, or the body was modified                              |
| `WebhookVerificationException: …outside the accepted window`           | Your server clock is more than 5 minutes off, or an old request was replayed                                                              |
| Callback arrives with no transaction id                                | You called `initialize` yourself without putting the id in `callbackUrl`. Use the checkout helper or add `?transactionId=…`               |
| `RequestFailedException` with code `04` from `verify`                  | Payxy has no payment for that id: the customer never reached the payment step                                                             |
| `DuplicateTransactionException`                                        | The `transactionId` was already used. Generate a new one, or `verify` the existing payment                                                |
| `TransportException` with `isOutcomeUnknown()` after `initialize`      | The request may have reached Payxy. Call `verify($transactionId)` before retrying                                                         |
| `CredentialsException` on a payout or virtual-account call             | The secret key (`webhookSecret`) is not configured; money movement needs it                                                               |
| Laravel webhook answers 419                                            | The route is not excluded from CSRF protection                                                                                            |
| Payment stays `Pending` and the checkout page shows "unexpected error" | An error on Payxy's side during card processing. Send the order id, the `PXF…` payment reference and your merchant code to Payxy support  |
| `AmountMismatch` with amount check `UnexplainedSurplus`                | The payment came without a fee breakdown and the amount is above what you expect (fees inside the amount). Set a `feeAllowance`           |

## FAQ

**Can I use this in the browser or a mobile app?** No. It needs your secret key and is meant for servers. Use Payxy's browser and mobile SDKs there, and keep the server steps here.

**Do I need webhooks?** Not strictly: the callback plus `verify` is enough for most checkouts. Webhooks cover customers who close the page before returning, so they are recommended.

**Why is `amount` an `Amount` and not a float?** Binary floating point cannot represent most decimal amounts exactly. `Amount` is exact.

**Is it safe to call `verify` repeatedly?** Yes. It is idempotent and the SDK retries it automatically on transient failures.

**Can I process cards on my own form?** No. Card entry stays on Payxy's hosted checkout.

**Does it work with queues and long-running workers (Octane, Swoole, RoadRunner)?** Yes. The client keeps no per-request state apart from a cached access token, so one instance can be shared.

**How do I get a request id for support?** Every `ApiException` has `getRequestId()`, and every response object has one too. Include it when you contact support.

---

## Compatibility

| PHP        | Laravel | Symfony       |
| ---------- | ------- | ------------- |
| 8.2 – 8.5  | 11+     | 6.4, 7 and 8  |

- The SDK follows [semantic versioning](https://semver.org).
- The `User-Agent` is `payxy-php-sdk/<version> PHP/<version>`, optionally followed by `userAgentSuffix`.

## Support

- API documentation: [docs.payxy.africa](https://docs.payxy.africa)
- Help and security reports: support@payxy.africa (include the request id from the error, the SDK version and your PHP version)

## License

MIT. Third-party notices, including Payxy's bundled browser checkout script, are in the `THIRD_PARTY_NOTICES.md` file shipped with the package.
