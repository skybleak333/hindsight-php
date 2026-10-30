<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A PSR-18 client that replays queued responses and records every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|Throwable> */
    private array $queue = [];

    /**
     * Queue a JSON response.
     */
    public function json(mixed $body, int $status = 200): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));

        return $this;
    }

    public function respond(ResponseInterface $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function fail(Throwable $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue) ?? throw new RuntimeException(
            "No response queued for {$request->getMethod()} {$request->getUri()}"
        );

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)] ?? throw new RuntimeException('No requests were sent.');
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    public function lastJson(): ?array
    {
        $body = (string) $this->lastRequest()->getBody();

        return $body === '' ? null : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }
}
