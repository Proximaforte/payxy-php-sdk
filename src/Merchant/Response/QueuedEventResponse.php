<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

/**
 * A webhook delivery that has been queued; the status is "QUEUED" once it is scheduled.
 */
class QueuedEventResponse extends StatusResponse
{
    public function getEventId(): ?string
    {
        return $this->data->string('event_id', 'eventId');
    }
}
