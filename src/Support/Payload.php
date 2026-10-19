<?php

declare(strict_types=1);

namespace Payxy\Support;

/**
 * @internal
 */
final class Payload
{
    /**
     * Recursively drops null entries so optional fields are omitted from the wire
     * instead of being sent as explicit nulls. false, 0 and "" are kept.
     *
     * @template K of array-key
     *
     * @param array<K, mixed> $payload
     *
     * @return array<K, mixed>
     */
    public static function compact(array $payload): array
    {
        $result = [];
        foreach ($payload as $key => $value) {
            if (\is_array($value)) {
                $value = self::compact($value);
                if ($value === [] && \is_string($key)) {
                    continue;
                }
            }
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
