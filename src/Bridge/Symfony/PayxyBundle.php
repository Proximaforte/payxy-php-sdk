<?php

declare(strict_types=1);

namespace Payxy\Bridge\Symfony;

use Payxy\Payxy;
use Payxy\PayxyConfig;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers an autowirable {@see Payxy} service configured from `config/packages/payxy.yaml`.
 * Logs go to a dedicated `payxy` Monolog channel when MonologBundle is installed.
 */
final class PayxyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'payxy';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('public_key')->isRequired()->cannotBeEmpty()->info('pk_test_/pk_live_, e.g. %env(PAYXY_PUBLIC_KEY)%')->end()
                ->scalarNode('webhook_secret')->defaultNull()->info('sk_test_/sk_live_, e.g. %env(PAYXY_WEBHOOK_SECRET)%; only for webhook signatures.')->end()
                ->scalarNode('base_url')->defaultNull()->info('Override only for proxies; defaults to the key\'s environment.')->end()
                ->scalarNode('timeout')->defaultValue(30)->end()
                ->scalarNode('connect_timeout')->defaultValue(10)->end()
                ->scalarNode('max_retries')->defaultValue(2)->end()
                ->scalarNode('http_client')->defaultNull()->info('Optional service id of a PSR-18 client to use instead of the hardened default.')->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $httpClient = $config['http_client'] ?? null;
        unset($config['http_client']);

        $services = $container->services();

        $services->set(PayxyConfig::class)
            ->factory([PayxyConfig::class, 'fromArray'])
            ->args([$config]);

        $services->set(Payxy::class)
            ->args([
                service(PayxyConfig::class),
                \is_string($httpClient) ? service($httpClient) : null,
                null,
                null,
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'payxy'])
            ->public();

        $services->alias('payxy', Payxy::class)->public();
    }
}
