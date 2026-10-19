<?php

declare(strict_types=1);

namespace Payxy\Bridge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Payxy\Payments\PaymentsService;

/**
 * @method static PaymentsService payments()
 *
 * @see \Payxy\Payxy
 */
final class Payxy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Payxy\Payxy::class;
    }
}
