<?php

declare(strict_types=1);

namespace Payxy\Exception;

use RuntimeException;

/**
 * A successful HTTP status came back with a body the SDK cannot trust (not JSON, oversized, wrong shape).
 */
final class InvalidResponseException extends RuntimeException implements PayxyException
{
    public function __construct(string $message, private readonly int $statusCode)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
