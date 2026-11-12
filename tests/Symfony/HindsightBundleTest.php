<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Symfony;

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Symfony\Ai\HindsightMemory;
use Fedoseev\Hindsight\Symfony\HindsightBundle;
use Fedoseev\Hindsight\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class HindsightBundleTest extends TestCase
{
    public function test_the_client_is_built_from_the_configuration(): void
    {
        $http = (new FakeHttpClient)->json(['api_version' => '1.2.1']);
        $container = $this->container([
            'url' => 'https://hindsight.example.test',
            'api_key' => 'secret',
            'headers' => ['X-Tenant' => 'acme'],
            'http_client' => 'app.hindsight_http',
        ], ['app.hindsight_http' => $http]);

        // Private like other bundles' services: autowired by its class, or fetched by the public alias.
        $client = $container->get('hindsight');
        $this->assertInstanceOf(Hindsight::class, $client);

        $client->version();

        $request = $http->lastRequest();
        $this->assertSame('https://hindsight.example.test/version', (string) $request->getUri());
        $this->assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        $this->assertSame('acme', $request->getHeaderLine('X-Tenant'));
    }

    public function test_the_defaults_point_at_a_local_server(): void
    {
        $http = (new FakeHttpClient)->json(['api_version' => '1.2.1']);
        $container = $this->container(['http_client' => 'app.hindsight_http'], ['app.hindsight_http' => $http]);

        $container->get('hindsight')->version();

        $this->assertSame('http://localhost:8888/version', (string) $http->lastRequest()->getUri());
        $this->assertFalse($http->lastRequest()->hasHeader('Authorization'));
    }

    public function test_the_client_is_autowired(): void
    {
        $http = (new FakeHttpClient)->json(['results' => []]);
        $container = $this->container(['http_client' => 'app.hindsight_http'], ['app.hindsight_http' => $http], static function (ContainerBuilder $container): void {
            $container->register('app.memory', HindsightMemory::class)->setAutowired(true)->setArgument('$bank', 'user-1')->setPublic(true);
        });

        $memory = $container->get('app.memory');
        $this->assertInstanceOf(HindsightMemory::class, $memory);

        $memory->load(new Input('gpt-test', new MessageBag(Message::ofUser('Hi'))));
        $this->assertSame('http://localhost:8888/v1/default/banks/user-1/memories/recall', (string) $http->lastRequest()->getUri());
    }

    public function test_without_an_http_client_service_the_client_still_builds(): void
    {
        $this->assertInstanceOf(Hindsight::class, $this->container([])->get('hindsight'));
    }

    public function test_an_empty_url_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['url' => '']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, object>  $services
     */
    private function container(array $config, array $services = [], ?\Closure $register = null): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        $bundle = new HindsightBundle;
        $extension = $bundle->getContainerExtension();
        $this->assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        foreach ($services as $id => $service) {
            $container->register($id)->setSynthetic(true)->setPublic(true);
        }

        if ($register !== null) {
            $register($container);
        }

        $container->compile();

        foreach ($services as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }
}
