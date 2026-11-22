<?php

declare(strict_types=1);

namespace Payxy\Auth;

use Closure;
use Payxy\Exception\ApiException;
use Payxy\Exception\CredentialsException;
use Payxy\Exception\InvalidResponseException;
use Payxy\Exception\TransportException;
use Payxy\Http\ApiResult;
use Payxy\Http\Endpoint;
use Payxy\PayxyConfig;
use Payxy\Support\ArrayReader;

/**
 * Exchanges the public/secret key pair for short-lived access tokens. Nothing is read or
 * validated until a token is first needed, so a client that never uses it never fails here.
 *
 * @internal
 */
final class AccessTokenProvider
{
    private const TOKEN_PATH = '/api/v1/oauth/token';
    private const SKEW_SECONDS = 30;
    private const DEFAULT_LIFETIME_SECONDS = 900;

    private ?string $accessToken = null;

    private ?string $refreshToken = null;

    private int $expiresAt = 0;

    /**
     * @param Closure(Endpoint, array<string, string>): ApiResult $send sends a token request through the transport
     * @param Closure(): int $clock current Unix time
     */
    public function __construct(
        private readonly PayxyConfig $config,
        private readonly Closure $send,
        private readonly Closure $clock,
    ) {}

    /**
     * A valid access token, renewed when it is about to expire.
     */
    public function accessToken(): string
    {
        $credentials = $this->credentials();
        $token = $this->accessToken;
        if ($token !== null && $this->expiresAt - self::SKEW_SECONDS > ($this->clock)()) {
            return $token;
        }

        return $this->renew($credentials);
    }

    /**
     * Drops the cached access token, e.g. after the API rejected it as revoked.
     */
    public function invalidate(string $accessToken): void
    {
        if ($this->accessToken === $accessToken) {
            $this->accessToken = null;
        }
    }

    /**
     * @return array{clientId: string, clientSecret: string}
     */
    private function credentials(): array
    {
        $secret = $this->config->webhookSecret;
        if ($secret === null) {
            throw new CredentialsException(
                'The Payxy secret key is not configured (set PAYXY_WEBHOOK_SECRET to your sk_ key). '
                . 'It is required, together with the public key, for payouts, wallet and virtual-account calls.',
            );
        }

        return ['clientId' => $this->config->publicKey->value(), 'clientSecret' => $secret->value()];
    }

    /**
     * @param array{clientId: string, clientSecret: string} $credentials
     */
    private function renew(array $credentials): string
    {
        if ($this->refreshToken !== null) {
            try {
                return $this->exchange(['grantType' => 'refresh_token', 'refreshToken' => $this->refreshToken]);
            } catch (ApiException|TransportException) {
                // An unusable refresh token is not fatal: start a fresh session with the key pair.
                $this->accessToken = null;
                $this->refreshToken = null;
            }
        }

        return $this->exchange(['grantType' => 'client_credentials', ...$credentials]);
    }

    /**
     * @param array<string, string> $body
     */
    private function exchange(array $body): string
    {
        $result = ($this->send)(Endpoint::oauth(self::TOKEN_PATH), $body);
        $reader = new ArrayReader($result->body);
        $accessToken = $reader->string('accessToken', 'access_token');
        if ($accessToken === null || $accessToken === '') {
            throw new InvalidResponseException('Payxy did not return an access token.', $result->statusCode);
        }

        $this->accessToken = $accessToken;
        $this->refreshToken = $reader->string('refreshToken', 'refresh_token');
        $this->expiresAt = ($this->clock)() + ($reader->int('expiresIn', 'expires_in') ?? self::DEFAULT_LIFETIME_SECONDS);

        return $accessToken;
    }
}
