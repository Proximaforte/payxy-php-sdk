<?php

declare(strict_types=1);

namespace Payxy\Webhook\Data;

use DateTimeImmutable;
use Payxy\Support\Amount;

/**
 * `settlement.completed` and `settlement.failed`. A failed settlement does not mean the
 * customer's payment failed: the payment may already have been received.
 */
final class SettlementData extends EventData
{
    public function getSettlementReference(): ?string
    {
        return $this->text('SettlementReference');
    }

    public function getPaymentReference(): ?string
    {
        return $this->text('PaymentReference');
    }

    public function getTransactionId(): ?string
    {
        return $this->text('TransactionId');
    }

    public function getProviderReference(): ?string
    {
        return $this->text('ProviderReference');
    }

    public function getWalletCreditReference(): ?string
    {
        return $this->text('WalletCreditReference');
    }

    public function getWalletId(): ?string
    {
        return $this->text('WalletId');
    }

    public function getCurrency(): ?string
    {
        return $this->text('Currency');
    }

    public function getReceivedAmount(): ?Amount
    {
        return $this->money('ReceivedAmount');
    }

    public function getProcessorChargeAmount(): ?Amount
    {
        return $this->money('ProcessorChargeAmount');
    }

    public function getPayxyChargeAmount(): ?Amount
    {
        return $this->money('PayxyChargeAmount');
    }

    public function getVatAmount(): ?Amount
    {
        return $this->money('VatAmount');
    }

    /**
     * What was credited to the Payxy Wallet.
     */
    public function getMerchantSettlementAmount(): ?Amount
    {
        return $this->money('MerchantSettlementAmount');
    }

    public function getSettlementStatus(): ?string
    {
        return $this->text('SettlementStatus');
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->instant('CompletedAtUtc');
    }

    /**
     * Failed settlements only.
     */
    public function getFailureCode(): ?string
    {
        return $this->text('FailureCode');
    }

    public function getFailureMessage(): ?string
    {
        return $this->text('FailureMessage');
    }

    public function getRetryCount(): ?int
    {
        return $this->reader->int('RetryCount');
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
