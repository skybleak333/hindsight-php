<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
