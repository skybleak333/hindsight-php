<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\Facades\Hindsight as HindsightFacade;
use Fedoseev\Hindsight\Laravel\HindsightServiceProvider;
use Fedoseev\Hindsight\Tests\Support\FakeHttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use ReflectionMethod;

final class ServiceProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('hindsight.url', 'https://hindsight.example.com');
        $app['config']->set('hindsight.api_key', 'hsk_from_config');
    }

    public function test_the_client_is_a_configured_singleton(): void
    {
        $client = $this->app->make(Hindsight::class);

        $this->assertSame($client, $this->app->make(Hindsight::class));
        $this->assertSame($client, $this->app->make('hindsight'));
    }

    public function test_the_config_reaches_the_client(): void
    {
        $http = (new FakeHttpClient)->json(['api_version' => '1.1.0', 'features' => []]);
        $this->app->instance('hindsight.http', $http);

        $this->app->make(Hindsight::class)->version();

        $this->assertSame('https://hindsight.example.com/version', (string) $http->lastRequest()->getUri());
        $this->assertSame('Bearer hsk_from_config', $http->lastRequest()->getHeaderLine('Authorization'));
        $this->assertSame(120.0, config('hindsight.timeout'));
    }

    public function test_the_facade_calls_the_bound_client(): void
    {
        $http = $this->fakeHindsight();
        $http->json(['results' => [['id' => 'f1', 'text' => 'Alice works at Google']]]);

        $this->assertSame(['Alice works at Google'], HindsightFacade::recall('b', 'Alice')->texts());
    }

    public function test_the_facade_documents_every_client_method(): void
    {
        $documented = [];
        preg_match_all('/@method static .+? (\w+)\(/', (string) (new ReflectionClass(HindsightFacade::class))->getDocComment(), $matches);

        foreach ((new ReflectionClass(Hindsight::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isConstructor() && ! $method->isStatic()) {
                $documented[] = $method->getName();
            }
        }

        $this->assertSame($documented, $matches[1]);
    }

    public function test_the_config_can_be_published(): void
    {
        $sources = array_keys(ServiceProvider::pathsToPublish(HindsightServiceProvider::class, 'hindsight-config'));

        $this->assertSame([realpath(__DIR__.'/../../config/hindsight.php')], array_map('realpath', $sources));
    }

    public function test_status_reports_the_server_version(): void
    {
        $http = $this->fakeHindsight();
        $http->json(['api_version' => '1.1.0', 'features' => ['observations' => true, 'mcp' => true, 'worker' => false]]);
        $http->json(['bank_id' => 'b', 'total_nodes' => 12, 'total_links' => 3, 'total_documents' => 4, 'nodes_by_fact_type' => [], 'links_by_link_type' => [], 'links_by_fact_type' => [], 'links_breakdown' => [], 'pending_operations' => 1, 'failed_operations' => 0]);

        $this->artisan('hindsight:status', ['--bank' => 'b'])
            ->expectsOutputToContain('1.1.0')
            ->expectsOutputToContain('observations, mcp')
            ->expectsOutputToContain('12 memories, 4 documents, 1 pending operations')
            ->expectsOutputToContain('Hindsight is reachable.')
            ->assertSuccessful();
    }

    public function test_status_fails_when_the_server_is_unreachable(): void
    {
        $this->fakeHindsight()->fail(new ConnectException('Connection refused', new Request('GET', 'http://hindsight.test/version')));

        $this->artisan('hindsight:status')
            ->expectsOutputToContain('Could not reach Hindsight at http://hindsight.test: Connection refused')
            ->assertFailed();
    }
}
