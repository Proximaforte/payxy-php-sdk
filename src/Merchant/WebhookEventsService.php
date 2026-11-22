<?php

declare(strict_types=1);

namespace Payxy\Merchant;

use Payxy\Http\Endpoint;
use Payxy\Http\HttpMethod;
use Payxy\Merchant\Response\QueuedEventResponse;
use Payxy\Merchant\Response\WebhookEventsResponse;
use Payxy\Service\AbstractService;
use Payxy\Support\Assert;

final class WebhookEventsService extends AbstractService
{
    /**
     * Events and their delivery attempts for the current environment.
     *
     * @param array<string, string> $query
     */
    public function list(array $query = []): WebhookEventsResponse
    {
        return $this->request(
            Endpoint::machine(HttpMethod::Get, '/api/v1/webhook-events'),
            WebhookEventsResponse::class,
            query: $query,
        );
    }

    /**
     * Delivers the same event again to the currently configured URL. Never retried automatically.
     */
    public function resend(string $eventId): QueuedEventResponse
    {
        Assert::notBlank($eventId, 'eventId', 128);

        return $this->request(
            Endpoint::machine(HttpMethod::Post, '/api/v1/webhook-events/{eventId}/resend', acceptedCodes: ['05']),
            QueuedEventResponse::class,
            pathParams: ['eventId' => $eventId],
        );
    }
}
