<?php

declare(strict_types=1);

namespace Fedoseev\Hindsight\Tests\Laravel\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

final class SupportAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    /**
     * @param  list<mixed>  $middleware
     * @param  list<mixed>  $tools
     */
    public function __construct(public array $middleware = [], public array $tools = []) {}

    public function instructions(): string
    {
        return 'You are a support agent.';
    }

    public function middleware(): array
    {
        return $this->middleware;
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
