<?php

declare(strict_types=1);

namespace Payxy\Demo;

use Payxy\Checkout\CheckoutDisplay;
use Payxy\Checkout\PayxyCheckout;
use Payxy\Payxy;
use Payxy\Support\Amount;

/**
 * The integrator-side class from README §6: settings as properties, expectedAmount() from "our" orders.
 */
final class DemoCheckout extends PayxyCheckout
{
    protected string $merchantName = 'Payxy Demo Shop';

    protected ?string $buttonColor = '#17C96E';

    protected ?string $bodyColor = '#F4F6FA';

    protected ?string $footerText = 'PHP SDK demo · sandbox';

    public function __construct(Payxy $payxy, private readonly OrderStore $orders, private readonly string $baseUrl, CheckoutDisplay $display)
    {
        parent::__construct($payxy);
        $this->display = $display;
    }

    protected function callbackUrl(): string
    {
        return $this->baseUrl . '/callback';
    }

    protected function expectedAmount(string $transactionId): ?Amount
    {
        return $this->orders->find($transactionId)?->amount;
    }
}
