<?php

declare(strict_types=1);

namespace Payxy\Auth;

/**
 * sk_test_ / sk_live_ key. Used only to check webhook signatures; never sent to Payxy.
 */
final class WebhookSecret extends MerchantKey
{
    protected static function prefix(): string
    {
        return 'sk';
    }

    protected static function label(): string
    {
        return 'The Payxy webhook secret';
    }
}
