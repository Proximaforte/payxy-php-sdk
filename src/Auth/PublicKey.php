<?php

declare(strict_types=1);

namespace Payxy\Auth;

/**
 * pk_test_ / pk_live_ key, sent as the Bearer token for Initialize and Verify.
 */
final class PublicKey extends MerchantKey
{
    protected static function prefix(): string
    {
        return 'pk';
    }

    protected static function label(): string
    {
        return 'The Payxy public key';
    }
}
