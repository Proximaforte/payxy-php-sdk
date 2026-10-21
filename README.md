# Payxy PHP SDK

A PHP SDK for the **Payxy payment gateway**, aligned with the current Payxy Core service. It covers
**Hosted Checkout** (initialize a payment and redirect the customer to Payxy), **payment verification**, and
**webhook verification**, with Laravel and Symfony integrations.

> **Card details are collected by Payxy Hosted Checkout. They should not pass through the merchant's PHP application.**
> Direct card, PIN and OTP handling is intentionally not part of this SDK; see [docs/direct-api.md](docs/direct-api.md).

---

## What's included

| Included | Not included (yet) |
|---|---|
| Initialize a payment → `paymentUrl` + `accessCode` | `MakePayment` / `ValidatePayment` / direct charging |
| Hosted Checkout redirect, plus an optional Pay-button / checkout-page helper | Collecting PAN, CVV, PIN or OTP |
| VerifyPayment with the full financial breakdown | SignalR realtime client (browser concern) |
| Webhook signature verification and event parsing | |
| Exact money handling (no floats) | |
| Test/live public-key auth; mode-specific webhook secrets | |
| Safe error handling and bounded retries | |
| Laravel service provider + facade, Symfony bundle | |

## Requirements

- PHP 8.2+ (tested on 8.2 and 8.5), `ext-json`
- A PSR-18 HTTP client. Guzzle (bundled with Laravel) or `symfony/http-client` is detected and hardened automatically.

```bash
composer require payxy/payxy-php-sdk guzzlehttp/guzzle
```

The package is published on [Packagist](https://packagist.org/packages/payxy/payxy-php-sdk) and follows semantic
versioning. Upgrade within the 1.x line with `composer update payxy/payxy-php-sdk`, then commit `composer.lock`.

---

## Quick start

### 1. Configure

```dotenv
PAYXY_PUBLIC_KEY=pk_test_xxx# API authentication; pk_live_ for production
PAYXY_WEBHOOK_SECRET=sk_test_xxx# webhook signatures only; never sent to Payxy
```

```php
use Payxy\Payxy;
use Payxy\PayxyConfig;

$payxy = new Payxy(new PayxyConfig(
    publicKey: $_ENV['PAYXY_PUBLIC_KEY'],
    webhookSecret: $_ENV['PAYXY_WEBHOOK_SECRET'] ?? null,
));
```

| Credential | Purpose |
|---|---|
| `pk_test_…` / `pk_live_…` | Authenticate test / production API requests (`Authorization: Bearer pk_…`) |
| `sk_test_…` / `sk_live_…` | Verify signatures on test / production webhooks. **Not** accepted as API credentials. |

The mode comes from the public-key prefix (`pk_test_` = Test, `pk_live_` = Production). An `sk_` key passed as
the public key is rejected. → [Authentication](docs/authentication.md)

### 2. Initialize and redirect

```php
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Support\{Amount, Reference};

$transactionId = Reference::generate('ORD');                  // ≤ 30 chars, CSPRNG
$_SESSION['payxy_transaction_id'] = $transactionId;           // persist before redirecting

$response = $payxy->payments()->initialize(new InitializePaymentRequest(
    email: 'customer@example.com',
    amount: Amount::fromString('75000.00'),                   // order amount, before Payxy charges
    transactionId: $transactionId,
    currency: 'NGN',
    callbackUrl: 'https://merchant.example.com/payments/payxy/return',
));

header('Location: ' . $response->getPaymentUrl());             // always an absolute https:// checkout URL
exit;
```

`getPaymentUrl()` never returns a relative path. If Payxy's `paymentUrl` isn't an absolute `https://` URL, the SDK
uses the environment's hosted checkout for the access code instead (`checkout.payxy.ng` for test keys,
`checkout.payxy.africa` for live keys). `getRawPaymentUrl()` keeps Payxy's original value for logging.
→ [Payment initialization](docs/payment-initialization.md) · [Hosted Checkout](docs/hosted-checkout.md)

### 3. Verify on the callback

The callback URL is a browser return URL, **not** proof of payment.

```php
$verification = $payxy->payments()->verify($_SESSION['payxy_transaction_id']);   // your id, not the query string

if ($verification->isPaid() && $verification->checkAmount(Amount::fromString('75000.00'), 'NGN')->isAcceptable()) {
    $order->markAsPaid();             // isPaid() = responseCode "00" && data.isSuccessful === true
}
```

Pending (`05`) and failed (`02`) transactions come back as results (`isPending()`, `getStatus()`), not exceptions.
`checkAmount()` returns the reason for a mismatch (`Underpaid`, `UnexplainedSurplus`, …). Successful bank transfers can
report an `amount` that **includes fees**; see [Transfers](docs/payment-verification.md#transfers-amount-can-include-fees).
→ [Payment verification](docs/payment-verification.md)

### 4. Handle webhooks

```php
use Payxy\Exception\WebhookVerificationException;

try {
    $event = $payxy->webhooks()->constructEventFromGlobals();     // HMAC-SHA512, 300 s window, rotation-aware
} catch (WebhookVerificationException) {
    http_response_code(401);
    exit;
}

// Deduplicate on $event->idempotencyKey() ("payment.success:ORD-…"), then:
if ($event->isPaymentSuccess()) { /* confirm + fulfil once */ }
http_response_code(200);
```

→ [Webhooks](docs/webhooks.md)

---

## Optional: the checkout helper class

To render a Pay button or a full checkout page, extend one class with your settings:

```php
final class ShopCheckout extends \Payxy\Checkout\PayxyCheckout
{
    protected string $callbackUrl = 'https://merchant.example.com/payments/payxy/return';
    protected ?string $buttonColor = '#0066FF';

    protected function expectedAmount(string $transactionId): ?\Payxy\Support\Amount
    {
        return Order::findByPayxyId($transactionId)?->amount;
    }

    // Optional business decision: how far a payment may exceed the order when Payxy sends no fee
    // breakdown (successful bank transfers include fees in the amount). Omit it to stay strict.
    protected function feeAllowance(string $transactionId, \Payxy\Support\Amount $expected): ?\Payxy\Support\Amount
    {
        return \Payxy\Support\Amount::fromMinorUnits((int) ceil($expected->toMinorUnits() * 0.02));   // 2%
    }
}

$session = $checkout->start(new CheckoutRequest(email: $email, amount: $amount));   // Initialize
$_SESSION['payxy_transaction_id'] = $session->transactionId;
echo $checkout->renderPage($session);                                               // or ->renderButton()

// callback:
$result = $checkout->confirm($_SESSION['payxy_transaction_id']);   // Successful / Pending / Failed / AmountMismatch
$result->amountCheck;                                              // why: Exact, WithinFeeAllowance, Underpaid, …
```

`feeAllowance()` defaults to `null`: any surplus without a fee breakdown is an `AmountMismatch`, and underpayment is
never accepted. Returning an allowance means accepting a payment that exceeds the order by up to that amount, whether
the surplus is fees or an overpayment. → [Transfers](docs/payment-verification.md#transfers-amount-can-include-fees)

Redirect display is supported. Popup display (Payxy's browser overlay) is **experimental**: it requires an HTTPS page,
and its framing contract isn't confirmed. → [Hosted Checkout](docs/hosted-checkout.md)

---

## Documentation

| Guide | Covers |
|---|---|
| [Authentication](docs/authentication.md) | `pk_` / `sk_` keys, configuration, mode selection, base URLs |
| [Hosted Checkout](docs/hosted-checkout.md) | End-to-end flow, the `PayxyCheckout` helper, callbacks, SPAs and mobile |
| [Payment initialization](docs/payment-initialization.md) | Request contract, validation, responses, duplicate IDs, ambiguous failures |
| [Payment verification](docs/payment-verification.md) | `00` / `05` / `02` / `04`, financial fields, settlement, amount comparison, GetPaymentInfo |
| [Webhooks](docs/webhooks.md) | Events, PascalCase payloads, signatures, idempotent processing |
| [IP allowlisting](docs/ip-allowlisting.md) | Mode-specific allowlists, what to allowlist |
| [Errors and retries](docs/errors-and-retries.md) | Exception hierarchy, response codes, retry policy, unknown outcomes |
| [Security and PCI](docs/security-and-pci.md) | Card data stays on Payxy, log redaction, threat model |
| [Laravel](docs/laravel.md) · [Symfony](docs/symfony.md) | Framework setup, callback and webhook routes |
| [Direct API](docs/direct-api.md) | Why direct payment isn't part of the public SDK |

---

## Status: confirmed vs pending

**Confirmed from Payxy Core** (and exercised live against the sandbox where possible):

- Public keys authenticate Initialize and VerifyPayment; secret keys sign webhooks; the mode comes from the public key.
- Initialize: transaction IDs are 6–30 characters; amounts are decimal strings with ≤ 2 decimals; `mode` is deprecated.
- VerifyPayment reports state in `responseCode` (`00` / `05` / `02`), with `04` for an unknown ID, all over HTTP 200.
- GetPaymentInfo returns separate checkout and realtime tokens.
- Webhooks: `payment.success` / `payment.failed`, HMAC-SHA512 over `timestamp.rawBody`, PascalCase JSON, dual signatures during the 24-hour rotation grace period.
- IP allowlisting is mode-specific. MakePayment takes an encrypted `payload` wrapper (hence no direct payment here).
- The API domains are `https://api.payxy.ng` for Test and `https://api.payxy.africa` for Production; hosted checkout uses the corresponding `checkout` subdomains.

**Not yet guaranteed** (the SDK doesn't depend on these, or marks them as experimental):

- That VerifyPayment always returns the fee breakdown with `amount` as the order amount: successful Transfers have been
  seen with fees included in `amount` and no breakdown (an open question for Payxy; the SDK flags these as
  `UnexplainedSurplus` unless you set a fee allowance).
- Initialize's `paymentUrl` always being absolute: the SDK falls back to the environment's checkout URL if it isn't.
- Hosted Checkout redirect query parameters (use the ID from your session).
- iframe/popup support and the hosted page's `postMessage` format (popup display is experimental).
- Webhook retry timing as an SLA.

---

## License and security

The Payxy PHP SDK is released under the [MIT License](LICENSE). The bundled browser checkout component and its licenses are documented in [Third-Party Notices](THIRD_PARTY_NOTICES.md).

Please report suspected vulnerabilities privately as described in the [Security Policy](SECURITY.md). Do not open a public issue containing credentials, payment data, customer information, or vulnerability details.

---

## Development

No local PHP is needed. Docker runs the full gate (code style, PHPStan at max level, PHPUnit on each PHP version):

```bash
sh docker-check.sh
PHP_VERSIONS="8.2 8.5" sh docker-check.sh
```

With PHP installed: `composer install && composer check`.

Live sandbox smoke test (Initialize → GetPaymentInfo → Verify):

```bash
PAYXY_PUBLIC_KEY=pk_test_xxx php examples/live-smoke-test.php
PAYXY_PUBLIC_KEY=pk_test_xxx PAYXY_TRANSACTION_ID=ORD-… php examples/live-smoke-test.php   # verify only
```

Demo shop (product → checkout page → Payxy → callback/re-check page):

```bash
echo "PAYXY_PUBLIC_KEY=pk_test_xxx" > examples/demo/.env       # gitignored
php -S localhost:8080 examples/demo/index.php
```

### Layout

```text
src/
├── Payxy.php, PayxyConfig.php, Environment.php, ResponseCode.php
├── Auth/          PublicKey (pk_), WebhookSecret (sk_): validated, masked, unserializable
├── Http/          Transport (the one request pipeline), Endpoint, RetryPolicy, hardened client factory
├── Payments/      PaymentsService (initialize, verify, getPaymentInfo), requests, responses, SettlementStatus
├── Webhook/       WebhookVerifier (verify / constructEvent), WebhookEvent
├── Checkout/      PayxyCheckout helper, sessions, outcomes, HTML renderer
├── Exception/     Exception hierarchy
├── Support/       Amount, Reference, Assert, ArrayReader, Payload
└── Bridge/        Laravel and Symfony integrations
```

Adding an endpoint takes a request DTO, a response class, and one `$this->request(Endpoint::…, Response::class, $dto)`
call. Auth, `responseCode` handling, retries, error mapping and logging all come from `Transport`.
