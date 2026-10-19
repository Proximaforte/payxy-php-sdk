<?php

declare(strict_types=1);

namespace Payxy;

use Payxy\Auth\PublicKey;
use Payxy\Auth\WebhookSecret;
use Payxy\Exception\ConfigurationException;
use Payxy\Http\RetryPolicy;
use SensitiveParameter;

/**
 * Immutable SDK configuration. The public key decides the environment (pk_test_ = sandbox,
 * pk_live_ = production) and therefore the API URL; only TLS endpoints are accepted.
 */
final readonly class PayxyConfig
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public PublicKey $publicKey;

    public ?WebhookSecret $webhookSecret;

    public Environment $environment;

    public string $baseUrl;

    /**
     * @param string|null $baseUrl override only for proxies or local mocks; defaults to the key's environment
     */
    public function __construct(
        #[SensitiveParameter]
        PublicKey|string $publicKey,
        #[SensitiveParameter]
        WebhookSecret|string|null $webhookSecret = null,
        ?string $baseUrl = null,
        public float $timeoutSeconds = 30.0,
        public float $connectTimeoutSeconds = 10.0,
        public RetryPolicy $retryPolicy = new RetryPolicy(),
        public int $maxResponseBytes = 2_097_152,
        public ?string $userAgentSuffix = null,
    ) {
        $this->publicKey = \is_string($publicKey) ? new PublicKey($publicKey) : $publicKey;
        $this->environment = $this->publicKey->environment();
        $this->webhookSecret = \is_string($webhookSecret) ? new WebhookSecret($webhookSecret) : $webhookSecret;
        if ($this->webhookSecret !== null && $this->webhookSecret->environment() !== $this->environment) {
            throw new ConfigurationException('The webhook secret and public key belong to different environments (test vs live).');
        }

        $this->baseUrl = $baseUrl === null ? $this->environment->apiBaseUrl() : self::secureUrl($baseUrl, 'baseUrl');
        foreach (Environment::cases() as $other) {
            if ($other !== $this->environment && $this->baseUrl === $other->apiBaseUrl()) {
                throw new ConfigurationException(\sprintf('A %s key cannot be used with %s.', $this->environment->name, $this->baseUrl));
            }
        }

        if ($timeoutSeconds <= 0 || $connectTimeoutSeconds <= 0 || $connectTimeoutSeconds > $timeoutSeconds) {
            throw new ConfigurationException('Timeouts must be positive and connectTimeoutSeconds must not exceed timeoutSeconds.');
        }
        if ($maxResponseBytes < 1024) {
            throw new ConfigurationException('maxResponseBytes must be at least 1024.');
        }
        if ($userAgentSuffix !== null && preg_match('/^[\x20-\x7E]{1,64}$/', $userAgentSuffix) !== 1) {
            throw new ConfigurationException('userAgentSuffix must be 1-64 printable ASCII characters.');
        }
    }

    /**
     * Builds a config from framework settings (Laravel config file, Symfony bundle config).
     * Keys: public_key, webhook_secret, base_url, timeout, connect_timeout, max_retries, user_agent_suffix.
     *
     * @param array<array-key, mixed> $settings
     */
    public static function fromArray(#[SensitiveParameter] array $settings): self
    {
        $publicKey = $settings['public_key'] ?? null;
        if (!\is_string($publicKey) || $publicKey === '') {
            throw new ConfigurationException('Payxy public_key is not configured (set PAYXY_PUBLIC_KEY).');
        }

        return new self(
            $publicKey,
            self::optionalString($settings, 'webhook_secret'),
            self::optionalString($settings, 'base_url'),
            timeoutSeconds: self::number($settings, 'timeout', 30.0),
            connectTimeoutSeconds: self::number($settings, 'connect_timeout', 10.0),
            retryPolicy: RetryPolicy::withRetries((int) self::number($settings, 'max_retries', \count(RetryPolicy::DEFAULT_SCHEDULE_MS))),
            userAgentSuffix: self::optionalString($settings, 'user_agent_suffix'),
        );
    }

    /**
     * Hosted checkout page for an access code, used when Initialize returns no paymentUrl.
     */
    public function checkoutUrlFor(string $accessCode): string
    {
        return $this->environment->checkoutOrigin() . '/' . rawurlencode($accessCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'environment' => $this->environment->name,
            'baseUrl' => $this->baseUrl,
            'publicKey' => $this->publicKey,
            'webhookSecret' => $this->webhookSecret,
            'timeoutSeconds' => $this->timeoutSeconds,
            'connectTimeoutSeconds' => $this->connectTimeoutSeconds,
            'retryPolicy' => $this->retryPolicy,
            'maxResponseBytes' => $this->maxResponseBytes,
            'userAgentSuffix' => $this->userAgentSuffix,
        ];
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function optionalString(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Accepts ints, floats and numeric strings, since env-driven config arrives as strings.
     *
     * @param array<array-key, mixed> $settings
     */
    private static function number(array $settings, string $key, float $default): float
    {
        $value = $settings[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new ConfigurationException(\sprintf('Payxy %s must be numeric.', $key));
        }

        return (float) $value;
    }

    private static function secureUrl(string $url, string $name): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException($name . ' must be an absolute URL such as "https://api.payxy.ng".');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException($name . ' must not contain credentials, a query string or a fragment.');
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $isLoopback = \in_array($host, self::LOOPBACK_HOSTS, true) || str_ends_with($host, '.localhost');
        if ($scheme !== 'https' && !($scheme === 'http' && $isLoopback)) {
            throw new ConfigurationException($name . ' must use https (plain http is only allowed for localhost).');
        }

        return $url;
    }
}
