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
        return $this->data->string('eventType', 'event_type');
    }

    public function getVersion(): ?string
    {
        return $this->data->string('version');
    }

    public function getOccurredAtUtc(): ?string
    {
        return $this->data->string('occurredAtUtc', 'occurred_at_utc');
    }

    public function getOccurredAt(): ?DateTimeImmutable
    {
        return Wire::parseInstant($this->getOccurredAtUtc());
    }

    /**
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
     * True once at least one attempt reached your endpoint successfully.
     */
    public function wasDelivered(): bool
    {
        foreach ($this->getDeliveries() as $delivery) {
            if ($delivery->isDelivered()) {
                return true;
            }
        }

        return false;
    }
}
