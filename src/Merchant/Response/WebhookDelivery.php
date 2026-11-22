<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use DateTimeImmutable;
use Payxy\Support\Wire;

/**
 * One HTTP delivery attempt of a webhook event.
 */
final readonly class WebhookDelivery extends RawRecord
{
    /**
     * Different for every attempt, unlike the event id.
     */
    public function getDeliveryId(): ?string
    {
        return $this->data->string('deliveryId', 'delivery_id');
    }

    public function getAttempt(): ?int
    {
        return $this->data->int('attempt');
    }

    /**
     * e.g. "Delivered"; other values are kept as sent.
     */
    public function getStatus(): ?string
    {
        return $this->data->string('status');
    }

    /**
     * The HTTP status your endpoint answered with, when it answered.
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->data->int('httpStatusCode', 'http_status_code');
    }

    public function getCompletedAtUtc(): ?string
    {
        return $this->data->string('completedAtUtc', 'completed_at_utc');
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return Wire::parseInstant($this->getCompletedAtUtc());
    }

    public function isDelivered(): bool
    {
        return strtolower((string) $this->getStatus()) === 'delivered';
    }
}
