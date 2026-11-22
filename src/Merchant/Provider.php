<?php

declare(strict_types=1);

namespace Payxy\Merchant;

/**
 * The bank partner that issues a virtual account.
 */
enum Provider: string
{
    case Vfd = 'VFD';
    case Optimus = 'OPTIMUS';
}
