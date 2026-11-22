<?php

declare(strict_types=1);

namespace Payxy\Merchant\Response;

use Payxy\Support\ArrayReader;

/**
 * @internal
 */
final class ListOf
{
    /**
     * The first list found under one of `$keys`, or the `data` envelope itself when it is a list.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function records(ArrayReader $reader, string ...$keys): array
    {
        $found = $reader->get('data');
        $raw = \is_array($found) && array_is_list($found) ? $found : $reader->get(...$keys);

        return \is_array($raw)
            ? array_values(array_filter($raw, static fn(mixed $item): bool => \is_array($item)))
            : [];
    }
}
