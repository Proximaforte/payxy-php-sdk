<?php

declare(strict_types=1);

namespace Payxy\Tests\Support;

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Checkout\PayxyCheckout;
use Payxy\Support\Amount;

/**
 * Example integrator subclass, configured the way the README shows.
 */
final class ShopCheckout extends PayxyCheckout
{
    protected string $callbackUrl = 'https://shop.example.com/payxy/callback';

    protected ?string $buttonColor = '#0A84FF';

    protected ?string $bodyColor = '#F7F9FC';

    protected ?string $footerText = 'Thanks for shopping <3';

    protected string $merchantName = 'Ada\'s Shop';

    /** @var array<string, Amount> */
    public array $orders = [];

    public function useDisplay(CheckoutDisplay $display): self
    {
        $this->display = $display;

        return $this;
    }

    public function useButtonColor(?string $color): self
    {
        $this->buttonColor = $color;

        return $this;
    }

    /** Business decision under test: how far a no-breakdown payment may exceed the order. */
    public ?Amount $allowance = null;

    protected function expectedAmount(string $transactionId): ?Amount
    {
        return $this->orders[$transactionId] ?? null;
    }

    protected function feeAllowance(string $transactionId, Amount $expected): ?Amount
    {
        return $this->allowance;
    }
}
