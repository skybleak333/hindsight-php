<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use Fedoseev\Hindsight\Text;
use RuntimeException;

/**
 * The server answered with an error status.
 */
class ApiException extends RuntimeException implements HindsightException
{
    /**
     * @param  mixed  $body  The decoded JSON body, or the raw text when the body is not JSON.
     */
    final public function __construct(string $message, public readonly int $statusCode, public readonly mixed $body)
    {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(int $statusCode, mixed $body, string $reasonPhrase = ''): self
    {
        $class = match (true) {
            $statusCode === 401, $statusCode === 403 => AuthenticationException::class,
            $statusCode === 404 => NotFoundException::class,
            $statusCode === 422 => ValidationException::class,
            default => self::class,
        };

        $detail = self::detail($body) ?? $reasonPhrase;
        $message = $detail === '' ? "Hindsight API error {$statusCode}" : "Hindsight API error {$statusCode}: {$detail}";

        return new $class($message, $statusCode, $body);
    }

    /**
     * Find the human-readable part of an error body: FastAPI's "detail", or the title of a proxy's HTML page.
     */
    private static function detail(mixed $body): ?string
    {
        if (is_array($body)) {
            $detail = $body['detail'] ?? $body['message'] ?? $body['error'] ?? null;

            if (is_string($detail)) {
                return $detail;
            }

            if (is_array($detail) && array_is_list($detail)) {
                $messages = [];

                // Keys are cast to string: PHP turns a numeric key such as "0" into an int.
                foreach (self::fieldErrors($detail) as $field => $message) {
                    $messages[] = (string) $field === '' ? $message : "{$field}: {$message}";
                }

                return $messages === [] ? null : implode('; ', $messages);
            }

            return $body === [] ? null : Text::limit((string) json_encode($body), 300);
        }

        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        if (preg_match('#<title>(.*?)</title>#is', $body, $match) === 1) {
            return trim(html_entity_decode($match[1]));
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($body)));

        return $text === '' ? null : Text::limit($text, 300);
    }

    /**
     * @param  array<array-key, mixed>  $detail
     * @return array<string, string>
     */
    protected static function fieldErrors(array $detail): array
    {
        $errors = [];

        foreach ($detail as $error) {
            if (! is_array($error)) {
                continue;
            }

            $location = array_filter((array) ($error['loc'] ?? []), static fn (mixed $part): bool => is_string($part) || is_int($part));
            $message = $error['msg'] ?? null;

            $errors[implode('.', $location)] = is_string($message) ? $message : 'Invalid value';
        }

        return $errors;
    }
}
