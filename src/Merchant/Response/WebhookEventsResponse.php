<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Response\PayxyResponse;

final class WebhookEventsResponse extends PayxyResponse
{
    /**
     * Up to the latest 100 events for this merchant and environment.
     *
     * @return list<WebhookEventRecord>
     */
    public function getEvents(): array
    {
        return array_map(
            static fn(array $event): WebhookEventRecord => new WebhookEventRecord($event),
            ListOf::records($this->data, 'events', 'items'),
        );
    }
}
