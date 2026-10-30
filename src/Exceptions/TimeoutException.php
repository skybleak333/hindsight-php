<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Exceptions;

use Fedoseev\Hindsight\Responses\Operation;
use RuntimeException;

/**
 * An operation did not finish within the time the caller was willing to wait.
 */
final class TimeoutException extends RuntimeException implements HindsightException
{
    public function __construct(public readonly Operation $operation, float $seconds)
    {
        parent::__construct("Hindsight operation {$operation->id} is still {$operation->status->value} after {$seconds} seconds.");
    }
}
