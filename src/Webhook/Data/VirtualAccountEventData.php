<?php

declare(strict_types=1);

namespace Payxy\Webhook\Data;

use DateTimeImmutable;
use Payxy\Support\Amount;

/**
 * `virtual_account.created` and `virtual_account.failed`.
 */
final class VirtualAccountEventData extends EventData
{
    public function getCreationOperationId(): ?string
    {
        return $this->text('CreationOperationId');
    }

    /**
     * Created accounts only.
     */
    public function getVirtualAccountId(): ?string
    {
        return $this->text('VirtualAccountId');
    }

    /**
     * Your own reference from the creation request.
     */
    public function getMerchantReference(): ?string
    {
        return $this->text('MerchantReference');
    }

    public function getProvider(): ?string
    {
        return $this->text('Provider');
    }

    public function getProviderReference(): ?string
    {
        return $this->text('ProviderReference');
    }

    public function getAccountType(): ?string
    {
        return $this->text('AccountType');
    }

    public function getAccountName(): ?string
    {
        return $this->text('AccountName');
    }

    public function getAccountNumber(): ?string
    {
        return $this->text('AccountNumber');
    }

    public function getBankName(): ?string
    {
        return $this->text('BankName');
    }

    public function getBankCode(): ?string
    {
        return $this->text('BankCode');
    }

    public function getCurrency(): ?string
    {
        return $this->text('Currency');
    }

    /**
     * Null for static accounts.
     */
    public function getExpectedAmount(): ?Amount
    {
        return $this->money('ExpectedAmount');
    }

    /**
     * Null for static accounts.
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->instant('ExpiresAtUtc');
    }

    public function getStatus(): ?string
    {
        return $this->text('Status');
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->instant('CreatedAtUtc');
    }

    /**
     * Failed accounts only.
     */
    public function getFailureCode(): ?string
    {
        return $this->text('FailureCode');
    }

    public function getFailureMessage(): ?string
    {
        return $this->text('FailureMessage');
    }

    public function isTerminal(): ?bool
    {
        return $this->reader->bool('IsTerminal');
    }

    public function getFailedAt(): ?DateTimeImmutable
    {
        return $this->instant('FailedAtUtc');
    }
}
