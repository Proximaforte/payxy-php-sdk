<?php

declare(strict_types=1);

/**
 * Demo shop for the Payxy PHP SDK. Run from the project root:
 *
 *   PAYXY_PUBLIC_KEY=pk_test_... php -S localhost:8080 examples/demo/index.php
 *
 * The key may also live in examples/demo/.env (gitignored) as PAYXY_PUBLIC_KEY=pk_test_...
 */

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Checkout\CheckoutOutcome;
use Payxy\Checkout\CheckoutRequest;
use Payxy\Checkout\CheckoutResult;
use Payxy\Demo\DemoCheckout;
use Payxy\Demo\Order;
use Payxy\Demo\OrderStore;
use Payxy\Exception\PayxyException;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Payxy\Support\Amount;
use Payxy\Support\Reference;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/OrderStore.php';
require __DIR__ . '/DemoCheckout.php';

loadEnv(__DIR__ . '/.env');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
$key = getenv('PAYXY_PUBLIC_KEY');
$baseUrl = rtrim(getenv('DEMO_BASE_URL') ?: 'http://localhost:8080', '/');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (!is_string($key) || $key === '') {
    render('Setup needed', '<p>Set <code>PAYXY_PUBLIC_KEY</code> (env or <code>examples/demo/.env</code>).</p>');
    return;
}

$payxy = new Payxy(new PayxyConfig($key));
$orders = new OrderStore();
$display = ($_POST['display'] ?? 'popup') === 'redirect' ? CheckoutDisplay::Redirect : CheckoutDisplay::Popup;
$checkout = new DemoCheckout($payxy, $orders, $baseUrl, $display);

try {
    match (true) {
        $path === '/' => shopPage($payxy),
        $path === '/checkout' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' => startCheckout($checkout, $orders),
        // The id comes from our own session (saved before redirecting), not from the query string.
        $path === '/callback' => resultPage(
            is_string($_SESSION['payxy_transaction_id'] ?? null) ? $checkout->confirm($_SESSION['payxy_transaction_id']) : $checkout->handleCallback($_GET),
            $orders,
            'Returned from Payxy checkout',
        ),
        $path === '/recheck' => resultPage($checkout->confirm((string) ($_GET['transactionId'] ?? '')), $orders, 'Re-checked with Verify Payment'),
        default => notFound(),
    };
} catch (PayxyException $e) {
    render('Payxy error', '<div class="alert">' . e($e::class) . '<br>' . e($e->getMessage()) . '</div><p><a href="/">Back to shop</a></p>');
}

function shopPage(Payxy $payxy): void
{
    $env = $payxy->getConfig()->environment;
    render('Payxy Demo Shop', <<<HTML
        <p class="muted">Environment: <b>{$env->name}</b> · API {$payxy->getConfig()->baseUrl}</p>
        <form method="post" action="/checkout" class="card">
          <div class="product"><div class="thumb">PX</div><div><b>SDK test order</b><br><span class="muted">Sandbox purchase</span></div></div>
          <label>Amount (NGN)<input name="amount" value="100.00" inputmode="decimal" required></label>
          <label>Email<input name="email" type="email" value="sdk-demo@example.com" required></label>
          <fieldset><legend>Checkout display</legend>
            <label class="inline"><input type="radio" name="display" value="popup" checked> Popup (official overlay)</label>
            <label class="inline"><input type="radio" name="display" value="redirect"> Redirect</label>
          </fieldset>
          <button type="submit">Checkout</button>
        </form>
        HTML);
}

function startCheckout(DemoCheckout $checkout, OrderStore $orders): void
{
    $order = new Order(Reference::generate('DEMO'), Amount::of((string) ($_POST['amount'] ?? '')), (string) ($_POST['email'] ?? ''));
    $orders->save($order);   // persist before sending the customer to Payxy
    $_SESSION['payxy_transaction_id'] = $order->transactionId;

    echo $checkout->page(new CheckoutRequest(
        email: $order->email,
        amount: $order->amount,
        transactionId: $order->transactionId,
        metadata: ['source' => 'php-sdk-demo'],
        productDescription: 'SDK test order',
    ));
}

function resultPage(CheckoutResult $result, OrderStore $orders, string $heading): void
{
    $order = $orders->find($result->transactionId);
    if ($order !== null) {
        $order->status = $result->outcome->value;
        $orders->save($order);
    }

    $badge = match ($result->outcome) {
        CheckoutOutcome::Successful => 'ok',
        CheckoutOutcome::Pending => 'warn',
        default => 'bad',
    };
    $v = $result->verification;
    $rows = [
        'Outcome' => '<span class="badge ' . $badge . '">' . e($result->outcome->name) . '</span>',
        'Transaction ID' => e($result->transactionId),
        'Order amount (ours)' => $order !== null ? e((string) $order->amount) : '<i>unknown order</i>',
        'Payxy status' => e($v?->getStatus() ?? '— (no payment attempted)'),
        'isSuccessful' => e(var_export($v?->isSuccessful(), true)),
        'Amount (Payxy)' => e((string) ($v?->getAmount() ?? '—')),
        'Payment reference' => e($v?->getPaymentReference() ?? '—'),
        'Gateway response' => e($v?->getGatewayResponse() ?? '—'),
        'Payment type' => e($v?->getPaymentType() ?? '—'),
        'Card' => e(trim(($v?->getCardType() ?? '') . ' ' . ($v?->getMaskedCardPan() ?? '')) ?: '—'),
    ];
    $table = '';
    foreach ($rows as $label => $value) {
        $table .= '<tr><th>' . e($label) . '</th><td>' . $value . '</td></tr>';
    }

    render('Payment result', '<p class="muted">' . e($heading) . '</p><table>' . $table . '</table>'
        . '<p><a href="/recheck?transactionId=' . rawurlencode($result->transactionId) . '">Re-check status</a> · <a href="/">New order</a></p>');
}

function notFound(): void
{
    http_response_code(404);
    render('Not found', '<p><a href="/">Back to shop</a></p>');
}

function render(string $title, string $body): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title><style>'
        . 'body{margin:0;background:#f4f6fa;font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#101828}'
        . 'main{max-width:560px;margin:40px auto;padding:0 16px}h1{font-size:22px;margin:0 0 4px}.muted{color:#667085}'
        . '.card,table{background:#fff;border-radius:14px;box-shadow:0 8px 24px rgba(16,24,40,.06);padding:24px;width:100%;box-sizing:border-box}'
        . 'label{display:block;margin:14px 0 0;font-weight:600}input:not([type=radio]){display:block;width:100%;box-sizing:border-box;margin-top:6px;padding:10px 12px;border:1px solid #d0d5dd;border-radius:8px;font:inherit}'
        . 'fieldset{border:0;padding:0;margin:16px 0 0}legend{font-weight:600}.inline{display:inline-flex;gap:6px;font-weight:400;margin-right:16px}'
        . 'button{margin-top:20px;width:100%;min-height:46px;border:0;border-radius:10px;background:#17c96e;color:#fff;font:600 16px system-ui;cursor:pointer}'
        . '.product{display:flex;gap:12px;align-items:center}.thumb{width:48px;height:48px;border-radius:10px;background:#e8f8ef;color:#0b7a43;display:grid;place-items:center;font-weight:700}'
        . 'table{border-collapse:separate;margin-top:12px}th{text-align:left;color:#667085;font-weight:500;padding:6px 12px 6px 0;white-space:nowrap}td{padding:6px 0;word-break:break-all}'
        . '.badge{padding:2px 10px;border-radius:999px;font-weight:600}.ok{background:#e8f8ef;color:#0b7a43}.warn{background:#fff4e5;color:#8a5300}.bad{background:#fdecec;color:#a4161a}'
        . '.alert{background:#fdecec;color:#a4161a;padding:14px 16px;border-radius:10px;word-break:break-word}a{color:#0b5fff}'
        . '</style></head><body><main><h1>' . e($title) . '</h1>' . $body . '</main></body></html>';
}

function loadEnv(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/', $line, $m) === 1 && getenv($m[1]) === false) {
            putenv($m[1] . '=' . $m[2]);
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
