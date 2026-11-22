<?php

declare(strict_types=1);

namespace Payxy\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Payxy\Exception\InvalidArgumentException;

/**
 * Shared input checks and conversions for the money-movement requests.
 *
 * @internal
 */
final class Wire
{
    private const MAX_WIRE_MINOR = 999_999_999_999_999;
    private const MAX_METADATA_ENTRIES = 50;

    public static function amount(Amount|string|int $value, string $field): Amount
    {
        if ($value instanceof Amount) {
            return $value;
        }
        try {
            return Amount::of($value);
        } catch (InvalidArgumentException) {
            throw InvalidArgumentException::forField($field, 'must be a decimal string with at most 2 digits');
        }
    }

    /**
     * The API takes JSON numbers; 15 digits keeps every 2-decimal value exact.
     */
    public static function number(Amount $amount, string $field): int|float
    {
        $minor = $amount->toMinorUnits();
        if ($minor > self::MAX_WIRE_MINOR) {
            throw InvalidArgumentException::forField($field, 'is too large');
        }

        return $minor % 100 === 0 ? intdiv($minor, 100) : $minor / 100;
    }

    public static function ngn(?string $currency, string $field): string
    {
        Assert::currency($currency ?? 'NGN', $field);
        if (($currency ?? 'NGN') !== 'NGN') {
            throw InvalidArgumentException::forField($field, 'only NGN is supported');
        }

        return 'NGN';
    }

    public static function dateTime(DateTimeInterface|string $value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        Assert::notBlank($value, $field, 40);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1 || date_create($value) === false) {
            throw InvalidArgumentException::forField($field, 'must be an ISO 8601 date-time');
        }

        return $value;
    }

    public static function date(string $value, string $field): string
    {
        Assert::notBlank($value, $field, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw InvalidArgumentException::forField($field, 'must be a date as YYYY-MM-DD');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return array<string, string>
     */
    public static function stringMap(array $metadata, string $field): array
    {
        if (\count($metadata) > self::MAX_METADATA_ENTRIES) {
            throw InvalidArgumentException::forField($field, \sprintf('must have at most %d entries', self::MAX_METADATA_ENTRIES));
        }
        $out = [];
        foreach ($metadata as $name => $value) {
            Assert::notBlank((string) $name, $field . ' name', 100);
            if (!\is_string($value)) {
                throw InvalidArgumentException::forField($field . ' value', 'must be a string');
            }
            Assert::text($value, $field . ' value', 500);
            $out[(string) $name] = $value;
        }

        return $out;
    }

    /**
     * An ISO 8601 date-time from the API, or null when absent or unparseable.
     */
    public static function parseInstant(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $date = date_create_immutable($raw);

        return $date === false ? null : $date;
    }
}
