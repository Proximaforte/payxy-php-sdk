<?php

declare(strict_types=1);

namespace Payxy\Payments\Request;

use Payxy\Request\RequestPayload;
use Payxy\Support\Assert;

/**
 * @internal built by {@see \Payxy\Payments\PaymentsService::verify()}
 */
final readonly class VerifyPaymentRequest implements RequestPayload
{
    public function __construct(public string $transactionId)
    {
        Assert::notBlank($transactionId, 'transactionId', 128);
    }

    public function toPayload(): array
    {
        return ['transactionId' => $this->transactionId];
    }
}
