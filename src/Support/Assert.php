<?php

declare(strict_types=1);

namespace Payxy\Support;

use Payxy\Exception\InvalidArgumentException;

/**
 * Format-level guards for request fields. They reject obviously malformed input early
 * and never include the value in the error, since it may be card or identity data.
 *
 * @internal
 */
final class Assert
{
    private const MAX_TEXT_LENGTH = 1024;

    /**
     * Required single-line value such as an id or reference: no control characters at all.
     */
    public static function notBlank(string $value, string $field, int $maxLength = self::MAX_TEXT_LENGTH): void
    {
        if (trim($value) === '') {
            throw InvalidArgumentException::forField($field, 'must not be blank');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidArgumentException::forField($field, 'must not contain control characters or line breaks');
        }
        self::text($value, $field, $maxLength);
    }

    public static function text(?string $value, string $field, int $maxLength = self::MAX_TEXT_LENGTH): void
    {
        if ($value === null) {
            return;
        }
        if (mb_strlen($value) > $maxLength) {
            throw InvalidArgumentException::forField($field, \sprintf('must be at most %d characters', $maxLength));
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw InvalidArgumentException::forField($field, 'must not contain control characters');
        }
    }

    public static function email(?string $value, string $field): void
    {
        if ($value !== null && filter_var($value, \FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidArgumentException::forField($field, 'must be a valid email address');
        }
    }

    public static function url(?string $value, string $field): void
    {
        if ($value === null) {
            return;
        }
        $scheme = strtolower((string) parse_url($value, \PHP_URL_SCHEME));
        if (filter_var($value, \FILTER_VALIDATE_URL) === false || !\in_array($scheme, ['http', 'https'], true)) {
            throw InvalidArgumentException::forField($field, 'must be an absolute http(s) URL');
        }
    }

    public static function currency(?string $value, string $field): void
    {
        if ($value !== null && preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw InvalidArgumentException::forField($field, 'must be an ISO 4217 code such as "NGN"');
        }
    }

    public static function country(?string $value, string $field): void
    {
        if ($value !== null && preg_match('/^[A-Z]{2}$/', $value) !== 1) {
            throw InvalidArgumentException::forField($field, 'must be an ISO 3166-1 alpha-2 code such as "NG"');
        }
    }

    public static function digits(?string $value, string $field, int $min, int $max): void
    {
        if ($value === null) {
            return;
        }
        if (preg_match(\sprintf('/^\d{%d,%d}$/', $min, $max), $value) !== 1) {
            $length = $min === $max ? (string) $min : \sprintf('%d-%d', $min, $max);
            throw InvalidArgumentException::forField($field, \sprintf('must be %s digits', $length));
        }
    }

    public static function nonNegative(?int $value, string $field): void
    {
        if ($value !== null && $value < 0) {
            throw InvalidArgumentException::forField($field, 'must not be negative');
        }
    }

    public static function positiveAmount(?Amount $amount, string $field): void
    {
        if ($amount !== null && $amount->isZero()) {
            throw InvalidArgumentException::forField($field, 'must be greater than zero');
        }
    }
}
