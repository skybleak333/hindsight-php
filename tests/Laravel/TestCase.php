<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel;

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Laravel\HindsightServiceProvider;
use Fedoseev\Hindsight\Tests\Support\FakeHttpClient;
use Fedoseev\Hindsight\Tests\Support\OpenApiContract;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected FakeHttpClient $http;

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, HindsightServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Hindsight' => \Fedoseev\Hindsight\Laravel\Facades\Hindsight::class];
    }

    /**
     * Swap the container's client for one that talks to a fake server.
     */
    protected function fakeHindsight(): FakeHttpClient
    {
        $this->http = new FakeHttpClient;

        $this->app->instance(Hindsight::class, new Hindsight('http://hindsight.test', httpClient: $this->http));

        return $this->http;
    }

    protected function tearDown(): void
    {
        foreach (isset($this->http) ? $this->http->requests : [] as $request) {
            OpenApiContract::instance()->assertRequest($request);
        }

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    protected function queueRecall(array $results): void
    {
        $body = ['results' => $results];

        OpenApiContract::instance()->assertResponse('POST', '/v1/default/banks/b/memories/recall', $body);

        $this->http->json($body);
    }

    protected function queueRetain(string $bankId = 'b', bool $async = true): void
    {
        $body = ['success' => true, 'bank_id' => $bankId, 'items_count' => 1, 'async' => $async] + ($async ? ['operation_id' => 'op-1'] : []);

        OpenApiContract::instance()->assertResponse('POST', '/v1/default/banks/b/memories', $body);

        $this->http->json($body);
    }

    /**
     * @return list<string>
     */
    protected function sentPaths(): array
    {
        return array_map(fn ($request): string => $request->getMethod().' '.$request->getUri()->getPath(), $this->http->requests);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentJson(int $index): array
    {
        return json_decode((string) $this->http->requests[$index]->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
