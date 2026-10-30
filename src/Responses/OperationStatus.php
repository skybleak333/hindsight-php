<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Responses;

enum OperationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case NotFound = 'not_found';

    /** A status this version of the package does not know yet. The original value is in Operation::$raw. */
    case Unknown = 'unknown';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::NotFound => true,
            self::Pending, self::Processing, self::Unknown => false,
        };
    }
}
