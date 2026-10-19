<?php

declare(strict_types=1);

namespace Payxy\Support;

use Payxy\Exception\InvalidArgumentException;
use Stringable;

/**
 * Exact monetary amount held in minor units (kobo, cents). Floats are never accepted,
 * so there is no rounding drift between what the merchant charges and what is sent.
 */
final readonly class Amount implements Stringable
{
    private const SCALE = 100;
    private const DECIMAL_PATTERN = '/^(0|[1-9]\d{0,15})(?:\.(\d{1,2}))?$/';

    private function __construct(private int $minorUnits) {}

    /**
     * @param int|string $majorUnits e.g. 1500, "1500", "1500.5", "1500.50"
     */
    public static function of(int|string $majorUnits): self
    {
        if (\is_int($majorUnits)) {
            if ($majorUnits < 0 || $majorUnits > intdiv(\PHP_INT_MAX, self::SCALE)) {
                throw InvalidArgumentException::forField('amount', 'is out of range');
            }

            return new self($majorUnits * self::SCALE);
        }

        if (preg_match(self::DECIMAL_PATTERN, trim($majorUnits), $m) !== 1) {
            throw InvalidArgumentException::forField('amount', 'must be a non-negative decimal with at most 2 fraction digits');
        }

        return new self((int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '', 2, '0'));
    }

    /**
     * Exact decimal string, e.g. "75000.00". Alias of {@see of()} for string input.
     */
    public static function fromString(string $majorUnits): self
    {
        return self::of($majorUnits);
    }

    public static function fromMinorUnits(int $minorUnits): self
    {
        if ($minorUnits < 0) {
            throw InvalidArgumentException::forField('amount', 'must not be negative');
        }

        return new self($minorUnits);
    }

    /**
     * Lenient parse for values coming back from the API; returns null when unparseable.
     */
    public static function tryFromApi(mixed $value): ?self
    {
        try {
            return match (true) {
                \is_int($value), \is_string($value) => self::of($value),
                \is_float($value) && is_finite($value) => self::of(number_format($value, 2, '.', '')),
                default => null,
            };
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function toMinorUnits(): int
    {
        return $this->minorUnits;
    }

    /**
     * Wire format, e.g. "1500.00".
     */
    public function toDecimalString(): string
    {
        return \sprintf('%d.%02d', intdiv($this->minorUnits, self::SCALE), $this->minorUnits % self::SCALE);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits;
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
