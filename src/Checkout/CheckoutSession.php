<?php

declare(strict_types=1);

namespace Payxy\Checkout;

use Payxy\Environment;
use Payxy\Support\Amount;

/**
 * A started checkout. Store the transaction id against your order before sending the
 * customer on, so the callback can be matched back to it.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $transactionId,
        public string $accessCode,
        public string $paymentUrl,
        public Amount $amount,
        public string $currency,
        public string $email,
        public Environment $environment,
    ) {}

    /**
     * Safe to return to a browser or mobile app as JSON: contains no credentials. `environment`
     * is the value the official browser SDK (@proximafortedev/payxy) expects.
     *
     * @return array{transactionId: string, accessCode: string, paymentUrl: string, amount: string, currency: string, environment: string}
     */
    public function toArray(): array
    {
        return [
            'transactionId' => $this->transactionId,
            'accessCode' => $this->accessCode,
            'paymentUrl' => $this->paymentUrl,
            'amount' => $this->amount->toDecimalString(),
            'currency' => $this->currency,
            'environment' => $this->environment->browserSdkEnvironment(),
        ];
    }
}
