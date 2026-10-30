<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use Fedoseev\Hindsight\Text;
use RuntimeException;

/**
 * The server answered with success, but not with the JSON object the endpoint documents.
 * Usually a proxy or a different service is answering at the configured URL.
 */
final class UnexpectedResponseException extends RuntimeException implements HindsightException
{
    public function __construct(string $method, string $path, public readonly mixed $body)
    {
        $got = match (true) {
            $body === null => 'an empty body',
            is_string($body) => 'a non-JSON body: '.Text::limit(trim($body), 120),
            default => 'JSON '.get_debug_type($body),
        };

        parent::__construct("Hindsight returned {$got} for {$method} {$path}; a JSON object was expected.");
    }
}
