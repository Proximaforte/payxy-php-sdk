<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use DateTimeImmutable;
use Payxy\Support\Wire;

/**
 * A stored webhook event and the delivery attempts made for it.
 */
final readonly class WebhookEventRecord extends RawRecord
{
    /**
     * Stable across every delivery and resend. Pass it to `webhookEvents()->resend()`.
     */
    public function getEventId(): ?string
    {
        return $this->data->string('eventId', 'event_id');
    }

    /**
     * e.g. "settlement.completed".
     */
    public function getEventType(): ?string
    {
        return $this->data->string('eventType', 'event_type', 'event');
    }

    public function getVersion(): ?string
    {
        return $this->data->string('version');
    }

    public function getOccurredAtUtc(): ?string
    {
        return $this->data->string('occurredAtUtc', 'occurred_at_utc', 'occurredAt');
    }

    /**
     * Where the event stands, e.g. "DELIVERED" or "RETRYING"; other values are kept as sent.
     */
    public function getDeliveryStatus(): ?string
    {
        return $this->data->string('deliveryStatus', 'delivery_status');
    }

    /**
     * How many delivery attempts have been made so far.
     */
    public function getAttemptCount(): ?int
    {
        return $this->data->int('attemptCount', 'attempt_count');
    }

    /**
     * When the next retry is due, as sent. Present while the event is still being retried.
     */
    public function getNextAttemptAt(): ?string
    {
        return $this->data->string('nextAttemptAt', 'next_attempt_at');
    }

    /**
     * The resource the event is about, e.g. a simulated payment reference.
     */
    public function getResourceReference(): ?string
    {
        return $this->data->string('resourceReference', 'resource_reference');
    }

    /**
     * The most recent delivery attempt, or null when none was made yet.
     */
    public function lastAttempt(): ?WebhookDelivery
    {
        $raw = $this->data->record('lastAttempt', 'last_attempt');

        return $raw === null ? null : new WebhookDelivery($raw);
    }

    public function getOccurredAt(): ?DateTimeImmutable
    {
        return Wire::parseInstant($this->getOccurredAtUtc());
    }

    /**
     * The delivery attempts, when the API lists them one by one.
     *
     * @return list<WebhookDelivery>
     */
    public function getDeliveries(): array
    {
        $raw = $this->data->get('deliveries');

        return \is_array($raw)
            ? array_values(array_map(
                static fn(array $delivery): WebhookDelivery => new WebhookDelivery($delivery),
                array_filter($raw, static fn(mixed $item): bool => \is_array($item)),
            ))
            : [];
    }

    /**
     * True once an attempt reached your endpoint successfully.
     */
    public function wasDelivered(): bool
    {
        if (strtolower((string) $this->getDeliveryStatus()) === 'delivered' || $this->lastAttempt()?->isDelivered() === true) {
            return true;
        }
        foreach ($this->getDeliveries() as $delivery) {
            if ($delivery->isDelivered()) {
                return true;
            }
        }

        return false;
    }
}
