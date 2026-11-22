<?php

declare(strict_types=1);

namespace Payxy;

use Closure;
use Http\Discovery\Psr17FactoryDiscovery;
use Payxy\Exception\ConfigurationException;
use Payxy\Http\HttpClientFactory;
use Payxy\Http\Sleeper;
use Payxy\Http\SystemSleeper;
use Payxy\Http\Transport;
use Payxy\Merchant\PayoutsService;
use Payxy\Merchant\ProvidersService;
use Payxy\Merchant\SimulatorService;
use Payxy\Merchant\VirtualAccountsService;
use Payxy\Merchant\WalletService;
use Payxy\Merchant\WebhookEventsService;
use Payxy\Payments\PaymentsService;
use Payxy\Webhook\WebhookVerifier;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * SDK entry point. Create one instance and reuse it; it is cheap to keep and holds no
 * per-request state.
 *
 *     $payxy = new Payxy(new PayxyConfig(getenv('PAYXY_PUBLIC_KEY')));
 *     $payxy->payments()->verify($transactionId);
 */
final class Payxy
{
    public const VERSION = '1.2.0'; // x-release-please-version

    private readonly Transport $transport;

    private ?PaymentsService $payments = null;

    private ?WebhookVerifier $webhooks = null;

    private ?ProvidersService $providers = null;

    private ?VirtualAccountsService $virtualAccounts = null;

    private ?WalletService $wallet = null;

    private ?PayoutsService $payouts = null;

    private ?SimulatorService $simulator = null;

    private ?WebhookEventsService $webhookEvents = null;

    private readonly PayxyConfig $config;

    /** @var (Closure(): int)|null */
    private readonly ?Closure $clock;

    /**
     * Every collaborator is optional: by default a hardened Guzzle/Symfony client (or any
     * discovered PSR-18 client) is used and nothing is logged.
     *
     * @param (Closure(): int)|null $clock current Unix time; injectable for tests
     */
    public function __construct(
        PayxyConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?Sleeper $sleeper = null,
        ?Closure $clock = null,
    ) {
        $this->config = $config;
        $this->transport = new Transport(
            $config,
            $httpClient ?? HttpClientFactory::create($config->timeoutSeconds, $config->connectTimeoutSeconds),
            $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            $logger ?? new NullLogger(),
            $sleeper ?? new SystemSleeper(),
            $clock,
        );
        $this->clock = $clock;
    }

    public function payments(): PaymentsService
    {
        return $this->payments ??= new PaymentsService($this->transport, $this->config);
    }

    /*
     * Money-movement services authenticate with an access token obtained from the public and
     * secret key. Nothing is checked at construction: a missing secret key is reported with a
     * CredentialsException by the first call that needs it, before any request is sent.
     */

    public function providers(): ProvidersService
    {
        return $this->providers ??= new ProvidersService($this->transport);
    }

    public function virtualAccounts(): VirtualAccountsService
    {
        return $this->virtualAccounts ??= new VirtualAccountsService($this->transport);
    }

    public function wallet(): WalletService
    {
        return $this->wallet ??= new WalletService($this->transport);
    }

    public function payouts(): PayoutsService
    {
        return $this->payouts ??= new PayoutsService($this->transport, $this->config);
    }

    /**
     * Sandbox only.
     */
    public function simulator(): SimulatorService
    {
        return $this->simulator ??= new SimulatorService($this->transport, $this->config);
    }

    public function webhookEvents(): WebhookEventsService
    {
        return $this->webhookEvents ??= new WebhookEventsService($this->transport);
    }

    /**
     * Webhook signature verification; needs the sk_ secret in PayxyConfig::$webhookSecret.
     */
    public function webhooks(): WebhookVerifier
    {
        return $this->webhooks ??= new WebhookVerifier(
            $this->config->webhookSecret
                ?? throw new ConfigurationException('Set the Payxy webhook secret (PAYXY_WEBHOOK_SECRET, sk_test_... or sk_live_...) to verify webhooks.'),
            clock: $this->clock,
        );
    }

    public function getConfig(): PayxyConfig
    {
        return $this->config;
    }
}
