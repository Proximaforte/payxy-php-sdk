<?php

declare(strict_types=1);

namespace Payxy\Tests\Bridge;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Payxy\Bridge\Laravel\Facades\Payxy as PayxyFacade;
use Payxy\Bridge\Laravel\PayxyServiceProvider;
use Payxy\Exception\ConfigurationException;
use Payxy\Payments\PaymentsService;
use Payxy\Payxy;
use Payxy\PayxyConfig;

final class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PayxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('payxy.base_url', null);
        $config->set('payxy.public_key', 'pk_test_0123456789abcdef');
        $config->set('payxy.timeout', '15');
        $config->set('payxy.max_retries', '1');
    }

    public function testResolvesASingletonFromConfig(): void
    {
        $payxy = $this->application()->make(Payxy::class);

        self::assertSame($payxy, $this->application()->make('payxy'));
        self::assertSame($payxy->payments(), $payxy->payments());

        $config = $this->application()->make(PayxyConfig::class);
        self::assertSame('https://api.payxy.ng', $config->baseUrl);
        self::assertSame(15.0, $config->timeoutSeconds);
        self::assertSame(1, $config->retryPolicy->maxRetries);
    }

    public function testFacadeProxiesToTheClient(): void
    {
        self::assertInstanceOf(PaymentsService::class, PayxyFacade::payments());
    }

    public function testMissingPublicKeyFailsOnlyWhenResolved(): void
    {
        $this->application()->make(Repository::class)->set('payxy.public_key', null);
        $this->application()->forgetInstance(PayxyConfig::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PAYXY_PUBLIC_KEY');
        $this->application()->make(PayxyConfig::class);
    }

    private function application(): Application
    {
        return $this->app ?? self::fail('Laravel application was not booted.');
    }
}
