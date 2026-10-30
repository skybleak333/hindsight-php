<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Passes requests to a real client and keeps the raw response bodies.
 *
 * Decoding JSON into PHP arrays turns {} into [], so schema checks need the body as the server sent it.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<string> */
    private array $bodies = [];

    public function __construct(private readonly ClientInterface $client) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->client->sendRequest($request);
        $this->bodies[] = (string) $response->getBody();
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * The last response body, decoded with JSON objects kept as objects.
     */
    public function lastBody(): mixed
    {
        $body = $this->bodies[array_key_last($this->bodies)] ?? throw new RuntimeException('No responses were recorded.');

        return json_decode($body, flags: JSON_THROW_ON_ERROR);
    }
}
