<?php

declare(strict_types=1);

namespace Payxy\Webhook\Data;

use DateTimeImmutable;
use Payxy\Support\Amount;

/**
 * `payment.received`: a verified, deduplicated provider payment. Settlement may still be pending.
 */
final class PaymentReceivedData extends EventData
{
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

    public function getProvider(): ?string
    {
        return $this->text('Provider');
    }

    public function getPaymentType(): ?string
    {
        return $this->text('PaymentType');
    }

    public function getCurrency(): ?string
    {
        return $this->text('Currency');
    }

    /**
     * What actually arrived. Credit this, not the expected amount.
     */
    public function getActualAmount(): ?Amount
    {
        return $this->money('ActualAmount');
    }

    /**
     * Null for static accounts.
     */
    public function getExpectedAmount(): ?Amount
    {
        return $this->money('ExpectedAmount');
    }

    /**
     * `EXACT`, `UNDERPAID` or `OVERPAID` (older payloads: `EXACT_AMOUNT`, `AMOUNT_MISMATCH`, `NOT_APPLICABLE`);
     * other values are kept as sent.
     */
    public function getAmountClassification(): ?string
    {
        return $this->text('AmountClassification');
    }

    /**
     * `ON_TIME` or `LATE` (older payloads: `LATE_PAYMENT`); other values are kept as sent.
     */
    public function getTimingClassification(): ?string
    {
        return $this->text('TimingClassification');
    }

    public function getSettlementStatus(): ?string
    {
        return $this->text('SettlementStatus');
    }

    public function getVirtualAccountId(): ?string
    {
        return $this->text('VirtualAccountId');
    }

    public function getAccountNumber(): ?string
    {
        return $this->text('AccountNumber');
    }

    public function getAccountType(): ?string
    {
        return $this->text('AccountType');
    }

    /**
     * Null for static accounts.
     */
    public function getAccountExpiresAt(): ?DateTimeImmutable
    {
        return $this->instant('AccountExpiresAtUtc');
    }

    public function getProviderPaymentAt(): ?DateTimeImmutable
    {
        return $this->instant('ProviderPaymentAtUtc');
    }

    public function getReceivedAt(): ?DateTimeImmutable
    {
        return $this->instant('ReceivedAtUtc');
    }

    public function getOriginatorBank(): ?string
    {
        return $this->text('OriginatorBank');
    }

    public function getOriginatorName(): ?string
    {
        return $this->text('OriginatorName');
    }

    /**
     * Masked by Payxy, e.g. `0123******89`.
     */
    public function getOriginatorAccount(): ?string
    {
        return $this->text('OriginatorAccount');
    }

    public function getSessionId(): ?string
    {
        return $this->text('SessionId');
    }

    public function isExactAmount(): bool
    {
        return \in_array($this->getAmountClassification(), ['EXACT', 'EXACT_AMOUNT'], true);
    }

    public function isUnderpaid(): bool
    {
        return $this->getAmountClassification() === 'UNDERPAID';
    }

    public function isOverpaid(): bool
    {
        return $this->getAmountClassification() === 'OVERPAID';
    }

    /**
     * Underpaid, overpaid, or the older `AMOUNT_MISMATCH`.
     */
    public function isAmountMismatch(): bool
    {
        return $this->isUnderpaid() || $this->isOverpaid() || $this->getAmountClassification() === 'AMOUNT_MISMATCH';
    }

    /**
     * `LATE`, or the older `LATE_PAYMENT`.
     */
    public function isLatePayment(): bool
    {
        return \in_array($this->getTimingClassification(), ['LATE', 'LATE_PAYMENT'], true);
    }
}
