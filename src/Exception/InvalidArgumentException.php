<?php

declare(strict_types=1);

namespace Payxy\Exception;

/**
 * Raised before any network call when input fails client-side validation.
 * Messages name the offending field but never echo its value, so card data cannot leak into logs.
 */
class InvalidArgumentException extends \InvalidArgumentException implements PayxyException
{
    public static function forField(string $field, string $reason): self
    {
        return new self(\sprintf('Invalid "%s": %s.', $field, $reason));
    }
}
