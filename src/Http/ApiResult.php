<?php

declare(strict_types=1);

namespace Payxy\Http;

/**
 * A successful, decoded gateway response.
 *
 * @internal
 */
final readonly class ApiResult
{
    /**
     * @param array<array-key, mixed> $body
     */
    public function __construct(
        public int $statusCode,
        public array $body,
        public ?string $requestId,
    ) {}
}
