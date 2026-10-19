<?php

declare(strict_types=1);

namespace Payxy\Payments\Response;

final class PaymentInfoResponse extends PaymentResponse
{
    public function getEmail(): ?string
    {
        return $this->data->string('email');
    }

    public function getPaymentUrl(): ?string
    {
        return $this->data->string('link', 'paymentUrl');
    }

    public function getBusinessName(): ?string
    {
        return $this->data->string('businessName');
    }

    /**
     * Short-lived, transaction-scoped token for the realtime status hub (SignalR). Pass it to a
     * browser only for live UI updates; fulfilment still requires Verify Payment.
     */
    public function getRealtimeToken(): ?string
    {
        return $this->data->string('paymentRealtimeToken');
    }

    public function getRealtimeTokenExpiresAt(): ?string
    {
        return $this->data->string('paymentRealtimeTokenExpiresAtUtc');
    }
}
