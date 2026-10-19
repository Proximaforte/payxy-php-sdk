<?php

declare(strict_types=1);

namespace Payxy\Support;

/**
 * Read-only, case-insensitive view over decoded JSON.
 *
 * Lookups check the top level first, then a `data` envelope, so callers don't care whether
 * the gateway wraps its payload. Dot paths (`data.card.last4`) address nested keys explicitly.
 *
 * @internal
 */
final readonly class ArrayReader
{
    private const ENVELOPE_KEY = 'data';

    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private array $data) {}

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * First non-null value found under any of the given keys.
     */
    public function get(string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = str_contains($key, '.')
                ? self::path($this->data, explode('.', $key))
                : self::find($this->data, $key) ?? self::find(self::envelope($this->data), $key);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public function string(string ...$keys): ?string
    {
        $value = $this->get(...$keys);

        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? 'true' : 'false',
            default => null,
        };
    }

    public function int(string ...$keys): ?int
    {
        $value = $this->get(...$keys);

        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => null,
        };
    }

    public function bool(string ...$keys): ?bool
    {
        $value = $this->get(...$keys);
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_string($value)) {
            return match (strtolower((string) $value)) {
                '1', 'true', 'yes' => true,
                '0', 'false', 'no' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function find(array $data, string $key): mixed
    {
        if (\array_key_exists($key, $data)) {
            return $data[$key];
        }
        $needle = strtolower($key);
        foreach ($data as $candidate => $value) {
            if (strtolower((string) $candidate) === $needle) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $segments
     */
    private static function path(array $data, array $segments): mixed
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!\is_array($current)) {
                return null;
            }
            $current = self::find($current, $segment);
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function envelope(array $data): array
    {
        $inner = self::find($data, self::ENVELOPE_KEY);

        return \is_array($inner) ? $inner : [];
    }
}
