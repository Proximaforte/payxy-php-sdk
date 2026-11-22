<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

/**
 * Base for responses to calls that take an idempotency key.
 */
abstract class IdempotentResponse extends StatusResponse
{
    /**
     * True when this idempotency key was already used and the original result is returned.
     */
    public function isIdempotentReplay(): bool
    {
        return $this->data->bool('idempotent_replay', 'idempotentReplay') === true;
    }
}
