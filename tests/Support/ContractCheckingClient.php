<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Checks every request, and every 200 response, against Hindsight's OpenAPI document.
 */
final class ContractCheckingClient implements ClientInterface
{
    public function __construct(private readonly ClientInterface $client) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        OpenApiContract::instance()->assertRequest($request);

        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() === 200) {
            $body = (string) $response->getBody();
            $response->getBody()->rewind();

            OpenApiContract::instance()->assertResponse(
                $request->getMethod(),
                $request->getUri()->getPath(),
                json_decode($body, flags: JSON_THROW_ON_ERROR),
            );
        }

        return $response;
    }
}
