<?php

declare(strict_types=1);

namespace Payxy\Support;

use Payxy\Exception\InvalidArgumentException;

/**
 * Generates unguessable, collision-resistant transaction ids from a CSPRNG.
 * Output is at most 27 characters, inside the gateway's 6-30 character limit.
 */
final class Reference
{
    public static function generate(string $prefix = 'PXY'): string
    {
        if (preg_match('/^[A-Za-z0-9]{0,10}$/', $prefix) !== 1) {
            throw InvalidArgumentException::forField('prefix', 'must be up to 10 alphanumeric characters');
        }

        $random = strtoupper(bin2hex(random_bytes(8)));

        return $prefix === '' ? $random : $prefix . '-' . $random;
    }
}
