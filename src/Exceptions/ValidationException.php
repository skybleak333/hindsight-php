<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

/**
 * The server rejected the request body or parameters (422).
 */
final class ValidationException extends ApiException
{
    /**
     * The rejected fields, keyed by their location such as "body.items.0.content".
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $detail = is_array($this->body) ? ($this->body['detail'] ?? null) : null;

        return is_array($detail) ? self::fieldErrors($detail) : [];
    }
}
