<?php

declare(strict_types=1);

namespace Payxy;

/**
 * Payxy environment, decided by the key prefix (pk_test_ / pk_live_), never by request data.
 */
enum Environment: string
{
    case Sandbox = 'test';
    case Production = 'live';

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::Sandbox => 'https://api.payxy.ng',
            self::Production => 'https://api.payxy.africa',
        };
    }

    /**
     * Origin of the hosted checkout, as used by the official browser SDK.
     */
    public function checkoutOrigin(): string
    {
        return match ($this) {
            self::Sandbox => 'https://checkout.payxy.ng',
            self::Production => 'https://checkout.payxy.africa',
        };
    }

    /**
     * Value for the official browser SDK's `environment` option.
     */
    public function browserSdkEnvironment(): string
    {
        return match ($this) {
            self::Sandbox => 'staging',
            self::Production => 'production',
        };
    }

    public static function fromKey(string $key, string $type): ?self
    {
        return match (true) {
            str_starts_with($key, $type . '_test_') => self::Sandbox,
            str_starts_with($key, $type . '_live_') => self::Production,
            default => null,
        };
    }
}
