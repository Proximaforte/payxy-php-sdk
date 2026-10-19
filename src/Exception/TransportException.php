<?php

declare(strict_types=1);

namespace Payxy\Exception;

use RuntimeException;
use Throwable;

/**
 * The request failed at transport level (DNS, TLS, connect, timeout) and no HTTP response was received.
 *
 * When {@see isOutcomeUnknown()} is true the gateway may still have processed the call (e.g. charged the card),
 * so verify the payment before trying again. Never blindly resend a payment request.
 */
final class TransportException extends RuntimeException implements PayxyException
{
    public function __construct(
        string $message,
        private readonly bool $outcomeUnknown,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }
}
