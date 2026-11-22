<?php

declare(strict_types=1);

namespace Payxy\Webhook;

use DateTimeImmutable;
use Payxy\Payments\SettlementStatus;
use Payxy\Support\Amount;
use Payxy\Support\ArrayReader;
use Payxy\Support\Wire;
use Payxy\Webhook\Data\PaymentReceivedData;
use Payxy\Webhook\Data\SettlementData;
use Payxy\Webhook\Data\VirtualAccountEventData;

/**
 * A signature-verified webhook notification (PascalCase JSON from Payxy Core). It is a
 * notification only: confirm a card or transfer payment with Verify Payment before giving value.
 */
final readonly class WebhookEvent
{
    public const PAYMENT_SUCCESS = 'payment.success';
    public const PAYMENT_FAILED = 'payment.failed';
    public const PAYMENT_RECEIVED = 'payment.received';
    public const SETTLEMENT_COMPLETED = 'settlement.completed';
    public const SETTLEMENT_FAILED = 'settlement.failed';
    public const VIRTUAL_ACCOUNT_CREATED = 'virtual_account.created';
    public const VIRTUAL_ACCOUNT_FAILED = 'virtual_account.failed';

    private ArrayReader $data;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(private array $payload, public int $attempt)
    {
        $this->data = new ArrayReader($payload);
    }

    /**
     * Raw event name, e.g. "payment.success"; future event types are kept as sent.
     */
    public function getEvent(): ?string
    {
        return $this->data->string('Event');
    }

    /**
     * Stable across every delivery and resend of the same event. Absent on older payloads.
     */
    public function getEventId(): ?string
    {
        return $this->data->string('EventId');
    }

    /**
     * Unique per HTTP delivery attempt; differs between retries of the same event.
     */
    public function getDeliveryId(): ?string
    {
        return $this->data->string('DeliveryId');
    }

    /**
     * Payload schema version, e.g. "1.0".
     */
    public function getVersion(): ?string
    {
        return $this->data->string('Version');
    }

    /**
     * When the event happened, as sent (UTC ISO 8601).
     */
    public function getOccurredAtUtc(): ?string
    {
        return $this->data->string('OccurredAtUtc');
    }

    public function getOccurredAt(): ?DateTimeImmutable
    {
        return Wire::parseInstant($this->getOccurredAtUtc());
    }

    public function isPaymentSuccess(): bool
    {
        return $this->getEvent() === self::PAYMENT_SUCCESS;
    }

    public function isPaymentFailed(): bool
    {
        return $this->getEvent() === self::PAYMENT_FAILED;
    }

    public function isPaymentReceived(): bool
    {
        return $this->getEvent() === self::PAYMENT_RECEIVED;
    }

    public function isSettlementCompleted(): bool
    {
        return $this->getEvent() === self::SETTLEMENT_COMPLETED;
    }

    public function isSettlementFailed(): bool
    {
        return $this->getEvent() === self::SETTLEMENT_FAILED;
    }

    public function isVirtualAccountCreated(): bool
    {
        return $this->getEvent() === self::VIRTUAL_ACCOUNT_CREATED;
    }

    public function isVirtualAccountFailed(): bool
    {
        return $this->getEvent() === self::VIRTUAL_ACCOUNT_FAILED;
    }

    /**
     * False when the payload carries neither an EventId nor a transaction reference, so there is
     * nothing to deduplicate on or act on. Payxy sends such an empty older-format copy beside some
     * full events (for example virtual_account.created); ignore it and handle the full event.
     */
    public function hasIdentity(): bool
    {
        return ($this->getEventId() ?? '') !== '' || ($this->getTransactionId() ?? '') !== '';
    }

    /**
     * Stable across redeliveries of the same event; use it to deduplicate. It is the EventId when
     * the payload has one, otherwise the event name and your transaction reference.
     */
    public function idempotencyKey(): string
    {
        $eventId = $this->getEventId();
        if ($eventId !== null && $eventId !== '') {
            return $eventId;
        }

        return ($this->getEvent() ?? 'unknown') . ':' . ($this->getTransactionId() ?? '');
    }

    /**
     * "Test" or "Production"; null on older payloads that carry no Mode.
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

    public function getProcessingFee(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.ProcessingFee'));
    }

    public function getPlatformVat(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.PlatformVat'));
    }

    public function getMerchantVat(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.MerchantVat'));
    }

    public function getTotalCharges(): ?Amount
    {
        return Amount::tryFromApi($this->data->get('Data.TotalCharges'));
    }

    public function getFeeBearer(): ?string
    {
        return $this->data->string('Data.FeeBearer');
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

    /**
     * Typed settlement status; null when absent or not yet known to this SDK.
     */
    public function settlementStatus(): ?SettlementStatus
    {
        $raw = $this->getSettlementStatus();

        return $raw === null ? null : SettlementStatus::tryFrom($raw);
    }

    public function getSessionId(): ?string
    {
        return $this->data->string('Data.SessionId');
    }

    /**
     * The `Data` object exactly as received; empty when absent.
     *
     * @return array<array-key, mixed>
     */
    public function data(): array
    {
        $data = $this->payload['Data'] ?? null;

        return \is_array($data) ? $data : [];
    }

    /**
     * Typed `Data` of a `payment.received` event; null for any other event.
     */
    public function paymentReceived(): ?PaymentReceivedData
    {
        return $this->isPaymentReceived() ? new PaymentReceivedData($this->data()) : null;
    }

    /**
     * Typed `Data` of `settlement.completed` or `settlement.failed`; null for any other event.
     */
    public function settlement(): ?SettlementData
    {
        return $this->isSettlementCompleted() || $this->isSettlementFailed() ? new SettlementData($this->data()) : null;
    }

    /**
     * Typed `Data` of `virtual_account.created` or `virtual_account.failed`; null for any other event.
     */
    public function virtualAccount(): ?VirtualAccountEventData
    {
        return $this->isVirtualAccountCreated() || $this->isVirtualAccountFailed() ? new VirtualAccountEventData($this->data()) : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
