<?php

declare(strict_types=1);

namespace Payxy\Merchant;

/**
 * A static account belongs to one customer for good; a dynamic one expects a single payment
 * of a fixed amount before it expires.
 */
enum AccountType: string
{
    case Static = 'STATIC';
    case Dynamic = 'DYNAMIC';
}
