<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * The server could not be reached, or the connection broke before a response arrived.
 */
final class ConnectionException extends RuntimeException implements HindsightException
{
    public static function from(string $baseUrl, ClientExceptionInterface $previous): self
    {
        return new self("Could not reach Hindsight at {$baseUrl}: {$previous->getMessage()}", 0, $previous);
    }
}
