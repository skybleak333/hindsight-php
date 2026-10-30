<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Unit;

use Fedoseev\Hindsight\Hindsight;
use Fedoseev\Hindsight\Tests\Support\FakeHttpClient;
use Fedoseev\Hindsight\Tests\Support\OpenApiContract;
use PHPUnit\Framework\TestCase;

abstract class ClientTestCase extends TestCase
{
    protected FakeHttpClient $http;

    protected Hindsight $hindsight;

    /** Set to false in a test that sends a request the API does not document on purpose. */
    protected bool $checkContract = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new FakeHttpClient;
        $this->hindsight = new Hindsight('http://hindsight.test/', apiKey: 'hsk_test', httpClient: $this->http);
    }

    protected function tearDown(): void
    {
        if ($this->checkContract) {
            foreach ($this->http->requests as $request) {
                OpenApiContract::instance()->assertRequest($request);
            }
        }

        parent::tearDown();
    }

    /**
     * Queue a response after checking that the API documents exactly this body.
     *
     * @param  array<string, mixed>  $body
     */
    protected function respond(string $method, string $path, array $body, int $status = 200): void
    {
        OpenApiContract::instance()->assertResponse($method, $path, $body, $status);

        $this->http->json($body, $status);
    }

    protected function assertSent(string $method, string $pathAndQuery): void
    {
        $request = $this->http->lastRequest();
        $uri = $request->getUri();

        $this->assertSame($method, $request->getMethod());
        $this->assertSame('http://hindsight.test'.$pathAndQuery, (string) $uri);
    }
}
