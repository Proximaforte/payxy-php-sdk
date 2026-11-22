<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

final class SimulatedCreditResponse extends QueuedEventResponse
{
    public function getPaymentReference(): ?string
    {
        return $this->data->string('payment_reference', 'paymentReference');
    }
}
