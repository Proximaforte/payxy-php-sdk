<?php

declare(strict_types=1);

namespace Payxy\Request;

/**
 * A request DTO that knows its own wire representation.
 */
interface RequestPayload
{
    /**
     * JSON-ready body with gateway field names. Null fields are already stripped.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
