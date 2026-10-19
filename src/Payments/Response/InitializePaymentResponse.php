<?php

declare(strict_types=1);

namespace Payxy\Payments\Response;

final class InitializePaymentResponse extends PaymentResponse
{
    /**
     * Pass to the official browser / mobile SDKs, or store to look the session up later.
     */
    public function getAccessCode(): ?string
    {
        return $this->data->string('accessCode');
    }

    /**
     * Payxy's hosted checkout page: redirect the customer here.
     */
    public function getPaymentUrl(): ?string
    {
        return $this->data->string('paymentUrl', 'checkoutUrl', 'authorizationUrl');
    }
}
