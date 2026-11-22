<?php

declare(strict_types=1);

namespace Payxy\Webhook\Data;

use DateTimeImmutable;
use Payxy\Support\Amount;
use Payxy\Support\ArrayReader;
use Payxy\Support\Wire;

/**
 * Read-only, typed view of an event's `Data` object. Missing or null fields read as null.
 */
abstract class EventData
{
    protected readonly ArrayReader $reader;

    /**
     * @param array<array-key, mixed> $raw
     */
    final public function __construct(private readonly array $raw)
    {
        $this->reader = new ArrayReader($raw);
    }

    /**
     * The `Data` object exactly as received.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    protected function text(string $field): ?string
    {
        return $this->reader->string($field);
    }

    protected function money(string $field): ?Amount
    {
        return Amount::tryFromApi($this->reader->get($field));
    }

    protected function instant(string $field): ?DateTimeImmutable
    {
        return Wire::parseInstant($this->reader->string($field));
    }
}
