<?php

declare(strict_types=1);

namespace Payxy\Auth;

use LogicException;
use Payxy\Environment;
use Payxy\Exception\ConfigurationException;
use SensitiveParameter;

/**
 * A Payxy key (pk_ or sk_). Validated, environment-aware, masked in debug output and
 * refusing serialization, so it cannot leak through dumps, sessions or queues.
 *
 * @internal
 */
abstract class MerchantKey
{
    private readonly string $value;

    private readonly Environment $environment;

    final public function __construct(#[SensitiveParameter] string $value)
    {
        $value = trim($value);
        $environment = Environment::fromKey($value, static::prefix());
        if ($environment === null || preg_match('/^[A-Za-z0-9_]{12,256}$/', $value) !== 1) {
            throw new ConfigurationException(\sprintf(
                '%s must look like "%2$s_test_..." or "%2$s_live_..." (letters, digits and underscores only).',
                static::label(),
                static::prefix(),
            ));
        }

        $this->value = $value;
        $this->environment = $environment;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment->name, 'value' => static::prefix() . '_' . $this->environment->value . '_***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException(static::class . ' holds a credential and cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException(static::class . ' holds a credential and cannot be unserialized.');
    }

    abstract protected static function prefix(): string;

    abstract protected static function label(): string;
}
