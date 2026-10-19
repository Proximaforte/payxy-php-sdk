<?php

declare(strict_types=1);

namespace Payxy\Tests\Bridge;

use Payxy\Bridge\Symfony\PayxyBundle;
use Payxy\Payxy;
use Payxy\PayxyConfig;
use Payxy\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class SymfonyIntegrationTest extends TestCase
{
    private const CONFIG_ALIAS = 'test.payxy_config';

    public function testRegistersAutowirableClientFromBundleConfig(): void
    {
        $container = self::boot([
            'public_key' => '%env(PAYXY_PUBLIC_KEY)%',
            'timeout' => 12,
        ]);

        $payxy = $container->get(Payxy::class);
        self::assertInstanceOf(Payxy::class, $payxy);
        self::assertSame($payxy, $container->get('payxy'));
        self::assertSame($payxy->payments(), $payxy->payments());

        $config = $container->get(self::CONFIG_ALIAS);
        self::assertInstanceOf(PayxyConfig::class, $config);
        self::assertSame('https://api.payxy.ng', $config->baseUrl);
        self::assertSame(12.0, $config->timeoutSeconds);
    }

    public function testCustomHttpClientServiceIsInjected(): void
    {
        $container = self::boot([
            'public_key' => 'pk_test_0123456789abcdef',
            'http_client' => 'app.payxy_http_client',
        ], static function (ContainerBuilder $builder): void {
            $builder->setDefinition('app.payxy_http_client', new Definition(FakeHttpClient::class));
        });

        self::assertInstanceOf(Payxy::class, $container->get(Payxy::class));
    }

    public function testPublicKeyIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        self::boot(['timeout' => 5]);
    }

    /**
     * @param array<string, mixed> $config
     * @param (callable(ContainerBuilder): void)|null $configure
     */
    private static function boot(array $config, ?callable $configure = null): ContainerBuilder
    {
        $_SERVER['PAYXY_PUBLIC_KEY'] = 'pk_test_0123456789abcdef';

        $builder = new ContainerBuilder();
        // Parameters a real kernel always provides; older BundleExtension versions read them.
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = (new PayxyBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $builder->registerExtension($extension);
        $builder->loadFromExtension('payxy', $config);
        // PayxyConfig is private by design; expose it to the test only.
        $builder->setAlias(self::CONFIG_ALIAS, PayxyConfig::class)->setPublic(true);
        if ($configure !== null) {
            $configure($builder);
        }
        $builder->compile(true);

        return $builder;
    }
}
