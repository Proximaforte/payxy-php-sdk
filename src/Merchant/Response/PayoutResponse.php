<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Support\Amount;

final class PayoutResponse extends IdempotentResponse
{
    public function getId(): ?string
    {
        return $this->data->string('id', 'payoutId', 'payout_id');
    }

    public function getAmount(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('amount'));
    }
}
