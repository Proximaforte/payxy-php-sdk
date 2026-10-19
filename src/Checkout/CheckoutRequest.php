<?php

declare(strict_types=1);

namespace Payxy\Checkout;

use Payxy\Support\Amount;

/**
 * The per-order values. Everything that stays the same across orders (colors, callback,
 * currency) lives on your {@see PayxyCheckout} subclass instead.
 */
final readonly class CheckoutRequest
{
    /**
     * @param string|null $transactionId your order reference; generated when omitted
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $email,
        public Amount $amount,
        public ?string $transactionId = null,
        public array $metadata = [],
        public ?string $productId = null,
        public ?string $productDescription = null,
    ) {}
}
