<?php

declare(strict_types=1);

namespace Payxy\Http;

use GuzzleHttp\Client as GuzzleClient;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\HttpClient as SymfonyHttpClient;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

/**
 * Builds a hardened default client: TLS verification on, redirects off (so the bearer
 * token is never forwarded elsewhere), and bounded timeouts. Clients passed in by the
 * integrator are used as-is and must be configured the same way.
 *
 * @internal
 */
final class HttpClientFactory
{
    public static function create(float $timeoutSeconds, float $connectTimeoutSeconds): ClientInterface
    {
        if (class_exists(GuzzleClient::class)) {
            return new GuzzleClient([
                'timeout' => $timeoutSeconds,
                'connect_timeout' => $connectTimeoutSeconds,
                'verify' => true,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        }

        if (class_exists(SymfonyPsr18Client::class)) {
            return new SymfonyPsr18Client(SymfonyHttpClient::create([
                'timeout' => $connectTimeoutSeconds,
                'max_duration' => $timeoutSeconds,
                'verify_peer' => true,
                'verify_host' => true,
                'max_redirects' => 0,
            ]));
        }

        return Psr18ClientDiscovery::find();
    }
}
