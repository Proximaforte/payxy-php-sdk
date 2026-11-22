<?php

declare(strict_types=1);

namespace Payxy\Bridge\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Payxy\Merchant\PayoutsService;
use Payxy\Merchant\ProvidersService;
use Payxy\Merchant\SimulatorService;
use Payxy\Merchant\VirtualAccountsService;
use Payxy\Merchant\WalletService;
use Payxy\Merchant\WebhookEventsService;
use Payxy\Payments\PaymentsService;
use Payxy\Webhook\WebhookVerifier;

/**
 * @method static PaymentsService payments()
 * @method static ProvidersService providers()
 * @method static VirtualAccountsService virtualAccounts()
 * @method static WalletService wallet()
 * @method static PayoutsService payouts()
 * @method static SimulatorService simulator()
 * @method static WebhookEventsService webhookEvents()
 * @method static WebhookVerifier webhooks()
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
