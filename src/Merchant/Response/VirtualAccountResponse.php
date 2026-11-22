<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Support\Amount;

final class VirtualAccountResponse extends IdempotentResponse
{
    public function getId(): ?string
    {
        return $this->data->string('id');
    }

    /**
     * The customer reference you sent.
     */
    public function getCustomerReference(): ?string
    {
        return $this->data->string('customerReference', 'customer_reference');
    }

    public function getBankCode(): ?string
    {
        return $this->data->string('bankCode', 'bank_code');
    }

    public function getAccountNumber(): ?string
    {
        return $this->data->string('accountNumber', 'account_number');
    }

    public function getAccountName(): ?string
    {
        return $this->data->string('accountName', 'account_name');
    }

    public function getBankName(): ?string
    {
        return $this->data->string('bankName', 'bank_name');
    }

    public function getProvider(): ?string
    {
        return $this->data->string('provider');
    }

    public function getAccountType(): ?string
    {
        return $this->data->string('accountType', 'account_type');
    }

    public function getExpiresAt(): ?string
    {
        return $this->data->string('expiresAt', 'expires_at');
    }

    /**
     * Dynamic accounts only.
     */
    public function getExpectedAmount(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('expectedAmount', 'expected_amount'));
    }
}
