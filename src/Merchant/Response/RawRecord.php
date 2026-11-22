<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Support\ArrayReader;

/**
 * A list item returned as-is, with typed accessors added by the subclass.
 */
abstract readonly class RawRecord
{
    protected ArrayReader $data;

    /**
     * @param array<array-key, mixed> $raw
     */
    final public function __construct(private array $raw)
    {
        $this->data = new ArrayReader($raw);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
