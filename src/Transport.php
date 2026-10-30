<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Fedoseev\Hindsight\Exceptions\ApiException;
use Fedoseev\Hindsight\Exceptions\ConnectionException;
use Fedoseev\Hindsight\Exceptions\UnexpectedResponseException;
use Fedoseev\Hindsight\Responses\Fields;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use stdClass;

/**
 * Turns method calls into HTTP requests and responses into arrays.
 *
 * @internal
 */
final class Transport
{
    private readonly string $baseUrl;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $baseUrl,
        private readonly ?string $apiKey,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly array $headers = [],
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param  array<array-key, mixed>|object|null  $body
     * @param  array<string, mixed>  $query  Null values are skipped; list values repeat the key, as FastAPI expects.
     */
    public function send(string $method, string $path, array|object|null $body = null, array $query = []): mixed
    {
        $queryString = self::queryString($query);

        $request = $this->requests
            ->createRequest($method, $this->baseUrl.$path.($queryString === '' ? '' : '?'.$queryString))
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', 'hindsight-php/'.Hindsight::VERSION);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withHeader('Authorization', 'Bearer '.$this->apiKey);
        }

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $json = json_encode($body === [] ? new stdClass : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($json));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw ConnectionException::from($this->baseUrl, $exception);
        }

        $contents = (string) $response->getBody();
        $decoded = $contents === '' ? null : json_decode($contents, true);
        $payload = $contents !== '' && json_last_error() !== JSON_ERROR_NONE ? $contents : $decoded;

        if ($response->getStatusCode() >= 400) {
            throw ApiException::fromResponse($response->getStatusCode(), $payload, $response->getReasonPhrase());
        }

        return $payload;
    }

    /**
     * Send a request to an endpoint that answers with a JSON object.
     *
     * @param  array<array-key, mixed>|object|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function json(string $method, string $path, array|object|null $body = null, array $query = []): array
    {
        $payload = $this->send($method, $path, $body, $query);

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new UnexpectedResponseException($method, $path, $payload);
        }

        return Fields::map(['payload' => $payload], 'payload') ?? [];
    }

    /**
     * Encode a path template such as "/v1/default/banks/%s/memories" with each value URL-encoded.
     */
    public static function path(string $template, string ...$values): string
    {
        return sprintf($template, ...array_map(rawurlencode(...), $values));
    }

    public static function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function queryString(array $query): string
    {
        $pairs = [];

        foreach ($query as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }

                $item = self::value($item);
                $text = match (true) {
                    is_bool($item) => $item ? 'true' : 'false',
                    is_scalar($item) => (string) $item,
                    default => throw new InvalidArgumentException("Query parameter '{$name}' must be a scalar, an enum or a date."),
                };

                $pairs[] = rawurlencode((string) $name).'='.rawurlencode($text);
            }
        }

        return implode('&', $pairs);
    }
}
