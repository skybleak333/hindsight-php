<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

/**
 * The bank, memory, document or other resource does not exist (404).
 */
final class NotFoundException extends ApiException
{
    /**
     * Whether Hindsight says this bank does not exist, as opposed to a 404 from a wrong URL or a proxy.
     *
     * Only the server's own explanation is searched for the bank ID, never the whole message.
     */
    public function isMissingBank(string $bankId): bool
    {
        $detail = is_array($this->body) ? ($this->body['detail'] ?? null) : null;

        return is_string($detail)
            && preg_match('/(?<![\w-])'.preg_quote($bankId, '/').'(?![\w-])/', $detail) === 1;
    }
}
