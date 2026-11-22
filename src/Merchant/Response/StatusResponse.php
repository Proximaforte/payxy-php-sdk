<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Response\PayxyResponse;

/**
 * Base for money-movement responses that report a `status`; other values are kept as sent.
 */
abstract class StatusResponse extends PayxyResponse
{
    public function getStatus(): ?string
    {
        return $this->data->string('status');
    }
}
