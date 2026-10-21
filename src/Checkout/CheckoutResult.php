<?php

declare(strict_types=1);

namespace Payxy\Checkout;

use Payxy\Payments\AmountCheck;
use Payxy\Payments\Response\VerifyPaymentResponse;

final readonly class CheckoutResult
{
    /**
     * @param VerifyPaymentResponse|null $verification null when Payxy has no payment attempt for the id
     * @param AmountCheck|null $amountCheck why the amount was (not) accepted; null unless the payment was paid
     */
    public function __construct(
        public CheckoutOutcome $outcome,
        public string $transactionId,
        public ?VerifyPaymentResponse $verification,
        public ?AmountCheck $amountCheck = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->outcome === CheckoutOutcome::Successful;
    }
}
