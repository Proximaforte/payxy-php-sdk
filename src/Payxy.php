<?php

declare(strict_types=1);

namespace Payxy;

use Http\Discovery\Psr17FactoryDiscovery;
use Payxy\Exception\ConfigurationException;
use Payxy\Http\HttpClientFactory;
use Payxy\Http\Sleeper;
use Payxy\Http\SystemSleeper;
use Payxy\Http\Transport;
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
    public const VERSION = '1.0.0';

    private readonly Transport $transport;

    private ?PaymentsService $payments = null;

    private ?WebhookVerifier $webhooks = null;

    private readonly PayxyConfig $config;

    /**
     * Every collaborator is optional: by default a hardened Guzzle/Symfony client (or any
     * discovered PSR-18 client) is used and nothing is logged.
     */
    public function __construct(
        PayxyConfig $config,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->config = $config;
        $this->transport = new Transport(
            $config,
            $httpClient ?? HttpClientFactory::create($config->timeoutSeconds, $config->connectTimeoutSeconds),
            $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            $logger ?? new NullLogger(),
            $sleeper ?? new SystemSleeper(),
        );
    }

    public function payments(): PaymentsService
    {
        return $this->payments ??= new PaymentsService($this->transport);
    }

    /**
     * Webhook signature verification; needs the sk_ secret in PayxyConfig::$webhookSecret.
     */
    public function webhooks(): WebhookVerifier
    {
        return $this->webhooks ??= new WebhookVerifier(
            $this->config->webhookSecret
                ?? throw new ConfigurationException('Set the Payxy webhook secret (PAYXY_WEBHOOK_SECRET, sk_test_... or sk_live_...) to verify webhooks.'),
        );
    }

    public function getConfig(): PayxyConfig
    {
        return $this->config;
    }
}
