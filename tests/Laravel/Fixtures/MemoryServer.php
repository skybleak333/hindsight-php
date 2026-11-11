<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel\Fixtures;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

final class MemoryServer extends Server
{
    /** @var list<Tool> */
    public static array $memoryTools = [];

    protected function boot(): void
    {
        $this->tools = self::$memoryTools;
    }
}
