<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use Fedoseev\Hindsight\Responses\Operation;
use Fedoseev\Hindsight\Responses\OperationStatus;
use RuntimeException;

/**
 * An operation ended without completing: it failed, was cancelled, or is unknown to the server.
 */
final class OperationFailedException extends RuntimeException implements HindsightException
{
    public function __construct(public readonly Operation $operation)
    {
        parent::__construct(match ($operation->status) {
            OperationStatus::Cancelled => "Hindsight operation {$operation->id} was cancelled.",
            OperationStatus::NotFound => "Hindsight operation {$operation->id} was not found.",
            default => $operation->errorMessage === null
                ? "Hindsight operation {$operation->id} failed."
                : "Hindsight operation {$operation->id} failed: {$operation->errorMessage}",
        });
    }
}
