<?php

declare(strict_types=1);

namespace Payxy\Bridge\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Psr\Log\LoggerInterface;

/**
 * Auto-discovered by Laravel. Credentials are only read when Payxy is first resolved,
 * so the app still boots (and runs artisan) before PAYXY_* is configured.
 */
final class PayxyServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__ . '/../../../config/payxy.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'payxy');

        $this->app->singleton(PayxyConfig::class, static function (Application $app): PayxyConfig {
            return PayxyConfig::fromArray(self::settings($app));
        });

        $this->app->singleton(Payxy::class, static function (Application $app): Payxy {
            return new Payxy($app->make(PayxyConfig::class), logger: self::logger($app));
        });

        $this->app->alias(Payxy::class, 'payxy');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG_PATH => $this->app->configPath('payxy.php')], 'payxy-config');
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function settings(Application $app): array
    {
        $config = $app->make(ConfigRepository::class)->get('payxy', []);

        return \is_array($config) ? $config : [];
    }

    private static function logger(Application $app): ?LoggerInterface
    {
        $channel = self::settings($app)['log_channel'] ?? null;
        $log = $app->make('log');

        if ($log instanceof LogManager && \is_string($channel) && $channel !== '') {
            return $log->channel($channel);
        }

        return $log instanceof LoggerInterface ? $log : null;
    }
}
