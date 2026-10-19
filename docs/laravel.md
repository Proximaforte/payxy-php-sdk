# Laravel

[← Back to README](../README.md)

The service provider and the `Payxy` facade are auto-discovered (Laravel 11+).

## Configuration

```dotenv
PAYXY_PUBLIC_KEY=pk_test_xxx
PAYXY_WEBHOOK_SECRET=sk_test_xxx
# Optional
PAYXY_BASE_URL=
PAYXY_TIMEOUT=30
PAYXY_CONNECT_TIMEOUT=10
PAYXY_MAX_RETRIES=3
PAYXY_LOG_CHANNEL=payments
```

Publishing the config file (`config/payxy.php`) is optional:

```bash
php artisan vendor:publish --tag=payxy-config
```

Credentials are read only when `Payxy` is first resolved, so the app (and `artisan`) still boots before `.env` is filled in.

## Usage

```php
use Payxy\Payxy;

final class PaymentController
{
    public function __construct(private Payxy $payxy) {}       // singleton
}

\Payxy::payments()->verify($transactionId);                    // facade
```

## Hosted Checkout with a callback and webhook

```php
// app/Payments/ShopCheckout.php
final class ShopCheckout extends \Payxy\Checkout\PayxyCheckout
{
    protected ?string $buttonColor = '#0066FF';

    protected function callbackUrl(): string
    {
        return route('payxy.return');
    }

    protected function expectedAmount(string $transactionId): ?\Payxy\Support\Amount
    {
        $order = \App\Models\Order::firstWhere('payxy_transaction_id', $transactionId);

        return $order ? \Payxy\Support\Amount::fromMinorUnits($order->total_kobo) : null;
    }
}
```

```php
// routes/web.php
use App\Payments\ShopCheckout;
use Payxy\Checkout\{CheckoutOutcome, CheckoutRequest};
use Payxy\Exception\WebhookVerificationException;
use Payxy\Support\Amount;

Route::post('/orders/{order}/pay', function (Order $order, ShopCheckout $checkout) {
    $session = $checkout->start(new CheckoutRequest(
        email: $order->email,
        amount: Amount::fromMinorUnits($order->total_kobo),
    ));
    $order->update(['payxy_transaction_id' => $session->transactionId]);
    session(['payxy_transaction_id' => $session->transactionId]);

    return redirect()->away($session->paymentUrl);
});

Route::get('/payments/payxy/return', function (ShopCheckout $checkout) {
    $result = $checkout->confirm(session('payxy_transaction_id'));     // our id, not the query string

    return match ($result->outcome) {
        CheckoutOutcome::Successful => redirect()->route('orders.thanks'),
        CheckoutOutcome::Pending    => view('orders.processing'),
        default                     => redirect()->route('orders.failed'),
    };
})->name('payxy.return');

Route::post('/webhooks/payxy', function (Request $request, Payxy\Payxy $payxy) {
    try {
        $event = $payxy->webhooks()->constructEvent(
            $request->getContent(),                                     // raw body
            $request->header('x-payxy-signature'),
            $request->header('x-payxy-timestamp'),
            $request->header('x-payxy-attempt'),
        );
    } catch (WebhookVerificationException) {
        return response('Invalid signature', 401);
    }

    DB::transaction(function () use ($event) {
        if (DB::table('payxy_webhooks')->insertOrIgnore(['key' => $event->idempotencyKey()]) === 0) {
            return;                                                     // duplicate delivery
        }
        if ($event->isPaymentSuccess()) {
            Order::firstWhere('payxy_transaction_id', $event->getTransactionId())?->markPaid();
        }
    });

    return response('OK');
})->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
```
