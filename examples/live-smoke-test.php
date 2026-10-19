<?php

declare(strict_types=1);

/**
 * Runs Initialize → GetPaymentInfo → Verify against a real Payxy environment and prints the
 * responses. The key comes from the environment only; pk_test_ targets the sandbox.
 *
 *   PAYXY_PUBLIC_KEY=pk_test_... php examples/live-smoke-test.php
 *   PAYXY_PUBLIC_KEY=pk_test_... PAYXY_TRANSACTION_ID=SDK-... php examples/live-smoke-test.php   (verify only)
 */

use Payxy\Exception\ApiException;
use Payxy\Exception\PayxyException;
use Payxy\Payments\Request\InitializePaymentRequest;
use Payxy\Payments\Response\InitializePaymentResponse;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Payxy\Response\PayxyResponse;
use Payxy\Support\Amount;
use Payxy\Support\Reference;

require __DIR__ . '/../vendor/autoload.php';

$key = getenv('PAYXY_PUBLIC_KEY') ?: exit("Set PAYXY_PUBLIC_KEY\n");
$payxy = new Payxy(new PayxyConfig($key));
echo 'Environment: ', $payxy->getConfig()->environment->name, ' (', $payxy->getConfig()->baseUrl, ")\n";

/**
 * @param callable(): PayxyResponse $call
 */
function step(string $name, callable $call): ?PayxyResponse
{
    echo "\n=== $name\n";
    try {
        $response = $call();
        echo 'HTTP ', $response->getStatusCode(), "\n", json_encode($response->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

        return $response;
    } catch (ApiException $e) {
        echo $e::class, ': ', $e->getMessage(), "\n";
    } catch (PayxyException $e) {
        echo $e::class, ': ', $e->getMessage(), "\n";
    }

    return null;
}

// Re-check a transaction you already paid on the hosted checkout, without creating a new one.
$existing = getenv('PAYXY_TRANSACTION_ID');
if (is_string($existing) && $existing !== '') {
    step('VerifyPayment', fn () => $payxy->payments()->verify($existing));
    exit(0);
}

$transactionId = Reference::generate('SDK');
echo "transactionId: $transactionId\n";

$init = step('Initialize', fn () => $payxy->payments()->initialize(new InitializePaymentRequest(
    email: 'sdk-smoke-test@example.com',
    amount: Amount::of('100.00'),
    transactionId: $transactionId,
    callbackUrl: 'https://example.com/payxy/callback',
    metadata: ['source' => 'php-sdk-smoke-test'],
)));

if ($init instanceof InitializePaymentResponse && ($accessCode = $init->getAccessCode()) !== null) {
    echo "\npaymentUrl: ", $init->getPaymentUrl() ?? '(none)', "\n";
    step('GetPaymentInfo', fn () => $payxy->payments()->getPaymentInfo($accessCode));
}

step('VerifyPayment', fn () => $payxy->payments()->verify($transactionId));
