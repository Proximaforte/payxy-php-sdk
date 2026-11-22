<?php

declare(strict_types=1);

namespace Payxy\Merchant\Request;

use Payxy\Request\RequestPayload;
use Payxy\Support\Assert;

/**
 * @internal built by {@see \Payxy\Merchant\PayoutsService::tsq()}
 */
final readonly class PayoutStatusRequest implements RequestPayload
{
    public function __construct(public string $reference)
    {
        Assert::notBlank($reference, 'reference', 100);
    }

    public function toPayload(): array
    {
        return ['reference' => $this->reference];
    }
}
