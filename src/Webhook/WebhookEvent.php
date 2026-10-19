<?php

declare(strict_types=1);

namespace Payxy\Webhook;

use Payxy\Support\Amount;
use Payxy\Support\ArrayReader;

/**
 * A signature-verified webhook notification (PascalCase JSON from Payxy Core). It is a
 * notification only: confirm the transaction with Verify Payment before giving value.
 */
final readonly class WebhookEvent
{
    public const PAYMENT_SUCCESS = 'payment.success';
    public const PAYMENT_FAILED = 'payment.failed';

    private ArrayReader $data;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(private array $payload, public int $attempt)
    {
        $this->data = new ArrayReader($payload);
    }

    /**
     * "payment.success" or "payment.failed" (raw, so future events are preserved).
     */
    public function getEvent(): ?string
    {
        return $this->data->string('Event');
    }

    public function isPaymentSuccess(): bool
    {
        return $this->getEvent() === self::PAYMENT_SUCCESS;
    }

    public function isPaymentFailed(): bool
    {
        return $this->getEvent() === self::PAYMENT_FAILED;
    }

    /**
     * Unique processing key for deduplication: Event + Data.TransactionId.
     */
    public function idempotencyKey(): string
    {
        return ($this->getEvent() ?? 'unknown') . ':' . ($this->getTransactionId() ?? '');
    }

    /**
     * "Test" or "Production".
     */
    public function getMode(): ?string
    {
        return $this->data->string('Mode');
    }

    public function getMerchantId(): ?int
    {
        return $this->data->int('MerchantId');
    }

    public function getTransactionId(): ?string
    {
        return $this->data->string('Data.TransactionId');
    }

    public function getPaymentReference(): ?string
    {
        return $this->data->string('Data.PaymentReference');
    }

    public function getProviderReference(): ?string
    {
        return $this->data->string('Data.ProviderReference');
    }

    public function getStatus(): ?string
    {
        return $this->data->string('Data.Status');
    }

    public function isSuccessful(): ?bool
    {
        return $this->data->bool('Data.IsSuccessful');
    }

    /**
     * Commercial / order amount.
     */
    public function getAmount(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.Amount'));
    }

    public function getCustomerPaid(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.CustomerPaid'));
    }

    public function getAmountPaidToMerchant(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.AmountPaidToMerchant'));
    }

    public function getTotalCharges(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.TotalCharges'));
    }

    public function getCurrency(): ?string
    {
        return $this->data->string('Data.Currency');
    }

    public function getPaymentType(): ?string
    {
        return $this->data->string('Data.PaymentType');
    }

    public function getGatewayResponse(): ?string
    {
        return $this->data->string('Data.GatewayResponse');
    }

    public function getSettlementStatus(): ?string
    {
        return $this->data->string('Data.SettlementStatus');
    }

    public function getSessionId(): ?string
    {
        return $this->data->string('Data.SessionId');
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
