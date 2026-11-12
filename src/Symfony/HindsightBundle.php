<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Symfony;

use Fedoseev\Hindsight\Hindsight;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the Hindsight client as a service, configured under the `hindsight` key:
 *
 *     hindsight:
 *         url: '%env(HINDSIGHT_URL)%'
 *         api_key: '%env(default::HINDSIGHT_API_KEY)%'
 */
final class HindsightBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('url')->defaultValue('http://localhost:8888')->cannotBeEmpty()->end()
                ->scalarNode('api_key')->defaultNull()->end()
                ->floatNode('timeout')->defaultValue(120.0)->min(0)->end()
                ->arrayNode('headers')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('http_client')
                    ->info('Service ID of a PSR-18 client; Guzzle is used without one.')
                    ->defaultNull()
                ->end()
            ->end();
    }

    /**
     * @param  array{url: string, api_key: ?string, timeout: float, headers: array<string, string>, http_client: ?string}  $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(Hindsight::class)
            ->args([
                '$baseUrl' => $config['url'],
                '$apiKey' => $config['api_key'],
                '$httpClient' => $config['http_client'] === null ? null : service($config['http_client']),
                '$timeout' => $config['timeout'],
                '$headers' => $config['headers'],
                '$logger' => service('logger')->nullOnInvalid(),
            ])
            ->alias('hindsight', Hindsight::class)
                ->public();
    }
}
